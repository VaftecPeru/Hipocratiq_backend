<?php

namespace App\Actions\Auth;

use App\Billing\BillingReceipt;
use App\Billing\SubscriptionPurchase;
use App\Contracts\BillingGateway;
use App\Enums\BillingCycle;
use App\Enums\ClinicMembershipStatus;
use App\Enums\DoctorPracticeType;
use App\Enums\OnboardingStatus;
use App\Enums\RoleKey;
use App\Enums\SubscriptionStatus;
use App\Enums\UserStatus;
use App\Models\Clinic;
use App\Models\DoctorProfile;
use App\Models\DoctorSubscription;
use App\Models\Plan;
use App\Models\Role;
use App\Models\User;
use App\Services\Auth\DoctorRegistrationDraftStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

final class CompleteDoctorRegistration
{
    public function __construct(
        private DoctorRegistrationDraftStore $draftStore,
        private RecordAuthenticationEvent $recordEvent,
        private BillingGateway $billingGateway,
    ) {}

    public function handle(string $token, array $draft): User
    {
        $data = $draft['data'];
        $practiceType = DoctorPracticeType::from($data['practice_type']);
        $verifiedOtp = $draft['otp'] ?? null;

        if (! is_array($verifiedOtp) || ! is_string($verifiedOtp['challenge_id'] ?? null)) {
            throw ValidationException::withMessages(['code' => ['El código OTP no está disponible.']]);
        }

        if (User::query()->where('email', $data['doctor_email'])->orWhere('phone_number', $data['doctor_phone_number'])->exists()) {
            throw ValidationException::withMessages(['doctor_email' => ['El correo o teléfono del médico ya está registrado.']]);
        }

        if (DoctorProfile::query()->where('medical_license', $data['medical_license'])->exists()) {
            throw ValidationException::withMessages(['medical_license' => ['La licencia médica ya está registrada.']]);
        }

        $clinic = $practiceType === DoctorPracticeType::Clinic
            ? $this->coveredClinic((int) $data['clinic_id'])
            : null;
        $plan = $practiceType === DoctorPracticeType::Independent
            ? $this->selectedPlan($draft['plan']['key'] ?? null)
            : null;
        $billingCycle = $draft['plan']['billing_cycle'] ?? null;
        $receipt = null;

        if ($plan !== null) {
            if (! is_string($billingCycle) || BillingCycle::tryFrom($billingCycle) === null) {
                throw ValidationException::withMessages(['plan' => ['Selecciona un ciclo de facturación válido.']]);
            }

            $amount = $billingCycle === BillingCycle::Annual->value
                ? $plan->annual_price_cents
                : $plan->monthly_price_cents;
            $receipt = $this->billingGateway->purchase(new SubscriptionPurchase(
                idempotencyKey: 'doctor_registration:'.hash('sha256', $token),
                amountCents: $amount,
                currency: $plan->currency,
                subjectType: 'doctor',
                planKey: $plan->key,
                billingCycle: $billingCycle,
            ));
        }

        $this->draftStore->consumeVerifiedOtp($token, $verifiedOtp['challenge_id']);

        try {
            $user = DB::transaction(function () use (
                $data,
                $practiceType,
                $clinic,
                $plan,
                $billingCycle,
                $receipt,
            ): User {
                if (User::query()->where('email', $data['doctor_email'])->orWhere('phone_number', $data['doctor_phone_number'])->exists()) {
                    throw ValidationException::withMessages(['doctor_email' => ['El correo o teléfono del médico ya está registrado.']]);
                }

                if (DoctorProfile::query()->where('medical_license', $data['medical_license'])->exists()) {
                    throw ValidationException::withMessages(['medical_license' => ['La licencia médica ya está registrada.']]);
                }

                $lockedClinic = $clinic === null ? null : $this->coveredClinic($clinic->id, true);
                $lockedPlan = $plan === null ? null : $this->selectedPlan($plan->key, true);
                $user = User::query()->create([
                    'name' => $data['doctor_name'],
                    'email' => $data['doctor_email'],
                    'phone_number' => $data['doctor_phone_number'],
                    'country_code' => $data['country_code'],
                    'password' => $data['password_hash'],
                    'status' => UserStatus::Active,
                    'onboarding_status' => OnboardingStatus::Completed,
                    'phone_verified_at' => now(),
                ]);

                $role = Role::query()->where('key', RoleKey::Doctor->value)->firstOrFail();
                $user->roles()->attach($role);
                $profile = $user->doctorProfile()->create([
                    'clinic_id' => $lockedClinic?->id,
                    'medical_license' => $data['medical_license'],
                    'specialty' => $data['specialty'],
                    'specialty_id' => $data['specialty_id'] ?? null,
                    'subspecialty_id' => $data['subspecialty_id'] ?? null,
                    'practice_type' => $practiceType,
                ]);

                if ($lockedClinic !== null) {
                    $user->clinicMemberships()->create([
                        'clinic_id' => $lockedClinic->id,
                        'role_id' => $role->id,
                        'status' => ClinicMembershipStatus::Active,
                    ]);
                }

                if ($lockedPlan !== null && $receipt instanceof BillingReceipt && is_string($billingCycle)) {
                    DoctorSubscription::query()->create([
                        'doctor_profile_id' => $profile->id,
                        'plan_id' => $lockedPlan->id,
                        'billing_cycle' => BillingCycle::from($billingCycle),
                        'status' => SubscriptionStatus::Active,
                        'starts_at' => now(),
                        'provider' => $receipt->provider,
                        'provider_reference' => $receipt->subscriptionReference,
                    ]);
                }

                return $user;
            }, attempts: 3);
        } catch (Throwable $exception) {
            $this->draftStore->restoreVerifiedOtp($token, $verifiedOtp);

            throw $exception;
        }

        $this->recordEvent->handleSafely($user, 'doctor_registration_completed', metadata: [
            'practice_type' => $practiceType->value,
            'clinic_id' => $data['clinic_id'] ?? null,
            'plan' => $draft['plan']['key'] ?? null,
        ]);
        $this->draftStore->forget($token);

        return $user;
    }

    private function coveredClinic(int $clinicId, bool $lockForUpdate = false): Clinic
    {
        $query = Clinic::query()
            ->whereKey($clinicId)
            ->where('status', 'active')
            ->whereHas('subscriptions', fn ($query) => $query->currentlyActive());

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $clinic = $query->first();

        if ($clinic === null) {
            throw ValidationException::withMessages(['clinic_id' => ['La clínica seleccionada no tiene un plan activo.']]);
        }

        return $clinic;
    }

    private function selectedPlan(mixed $planKey, bool $lockForUpdate = false): Plan
    {
        if (! is_string($planKey)) {
            throw ValidationException::withMessages(['plan' => ['El médico independiente debe seleccionar un plan.']]);
        }

        $query = Plan::query()
            ->where('key', $planKey)
            ->where('is_active', true)
            ->where('publicly_selectable', true);

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $plan = $query->first();

        if ($plan === null) {
            throw ValidationException::withMessages(['plan' => ['El plan seleccionado no está disponible.']]);
        }

        return $plan;
    }
}
