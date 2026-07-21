<?php

namespace App\Actions\Auth;

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
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CompleteDoctorRegistration
{
    public function __construct(
        private DoctorRegistrationDraftStore $draftStore,
        private RecordAuthenticationEvent $recordEvent,
    ) {}

    public function handle(string $token, array $draft): User
    {
        $data = $draft['data'];
        $practiceType = DoctorPracticeType::from($data['practice_type']);

        $user = DB::transaction(function () use ($data, $draft, $practiceType): User {
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
                'clinic_id' => $clinic?->id,
                'medical_license' => $data['medical_license'],
                'specialty' => $data['specialty'],
                'practice_type' => $practiceType,
            ]);

            if ($clinic !== null) {
                $user->clinicMemberships()->create([
                    'clinic_id' => $clinic->id,
                    'role_id' => $role->id,
                    'status' => ClinicMembershipStatus::Active,
                ]);
            }

            if ($plan !== null) {
                DoctorSubscription::query()->create([
                    'doctor_profile_id' => $profile->id,
                    'plan_id' => $plan->id,
                    'billing_cycle' => BillingCycle::from($draft['plan']['billing_cycle']),
                    'status' => SubscriptionStatus::Active,
                    'starts_at' => now(),
                    'provider' => 'simulated',
                    'provider_reference' => 'sim_doc_sub_'.Str::lower(Str::random(24)),
                ]);
            }

            return $user;
        }, attempts: 3);

        $this->recordEvent->handle($user, 'doctor_registration_completed', metadata: [
            'practice_type' => $practiceType->value,
            'clinic_id' => $data['clinic_id'] ?? null,
            'plan' => $draft['plan']['key'] ?? null,
        ]);
        $this->draftStore->forget($token);

        return $user;
    }

    private function coveredClinic(int $clinicId): Clinic
    {
        $clinic = Clinic::query()
            ->whereKey($clinicId)
            ->where('status', 'active')
            ->whereHas('subscriptions', fn ($query) => $query->where('status', 'active')->where(function ($query): void {
                $query->whereNull('ends_at')->orWhere('ends_at', '>', now());
            }))
            ->first();

        if ($clinic === null) {
            throw ValidationException::withMessages(['clinic_id' => ['La clínica seleccionada no tiene un plan activo.']]);
        }

        return $clinic;
    }

    private function selectedPlan(mixed $planKey): Plan
    {
        if (! is_string($planKey)) {
            throw ValidationException::withMessages(['plan' => ['El médico independiente debe seleccionar un plan.']]);
        }

        $plan = Plan::query()
            ->where('key', $planKey)
            ->where('is_active', true)
            ->where('publicly_selectable', true)
            ->first();

        if ($plan === null) {
            throw ValidationException::withMessages(['plan' => ['El plan seleccionado no está disponible.']]);
        }

        return $plan;
    }
}
