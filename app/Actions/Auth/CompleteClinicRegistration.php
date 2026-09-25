<?php

namespace App\Actions\Auth;

use App\Billing\SubscriptionPurchase;
use App\Contracts\BillingGateway;
use App\Enums\BillingCycle;
use App\Enums\ClinicMembershipStatus;
use App\Enums\ClinicStatus;
use App\Enums\OnboardingStatus;
use App\Enums\RoleKey;
use App\Enums\SubscriptionStatus;
use App\Enums\UserStatus;
use App\Models\Clinic;
use App\Models\ClinicSubscription;
use App\Models\Plan;
use App\Models\Role;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Services\Auth\ClinicRegistrationDraftStore;
use App\Services\Auth\GoogleRegistrationIdentity;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class CompleteClinicRegistration
{
    public function __construct(
        private ClinicRegistrationDraftStore $draftStore,
        private RecordAuthenticationEvent $recordEvent,
        private BillingGateway $billingGateway,
        private GoogleRegistrationIdentity $googleIdentity,
    ) {}

    /** @return array{user: User, clinic: Clinic} */
    public function handle(string $token, array $draft): array
    {
        $data = $draft['data'];
        $planKey = $draft['plan']['key'] ?? null;
        $billingCycle = $draft['plan']['billing_cycle'] ?? null;

        if (
            ! is_string($planKey)
            || ! in_array($planKey, ['basic', 'professional'], true)
            || ! is_string($billingCycle)
            || BillingCycle::tryFrom($billingCycle) === null
        ) {
            throw ValidationException::withMessages(['plan' => ['Selecciona un plan válido.']]);
        }

        $adminPhone = PhoneNumber::from($data['admin_phone_number'], $data['country_code']);
        $contactPhone = PhoneNumber::from($data['contact_phone'], $data['country_code']);
        $plan = Plan::query()
            ->where('key', $planKey)
            ->where('is_active', true)
            ->where('publicly_selectable', true)
            ->first();

        if ($plan === null) {
            throw ValidationException::withMessages(['plan' => ['El plan seleccionado no está disponible.']]);
        }

        $verifiedOtp = $draft['otp'] ?? null;

        if (! is_array($verifiedOtp) || ! is_string($verifiedOtp['challenge_id'] ?? null)) {
            throw ValidationException::withMessages(['code' => ['El código OTP no está disponible.']]);
        }

        if (User::query()->where('email', $data['admin_email'])->orWhere('phone_number', $adminPhone->value)->exists()) {
            throw ValidationException::withMessages(['admin_email' => ['El correo o teléfono del administrador ya está registrado.']]);
        }

        if (Clinic::query()->where('tax_id', $data['tax_id'])->exists()) {
            throw ValidationException::withMessages(['tax_id' => ['La clínica ya está registrada.']]);
        }

        $amount = $billingCycle === BillingCycle::Annual->value
            ? $plan->annual_price_cents
            : $plan->monthly_price_cents;
        $idempotencyKey = 'clinic_registration:'.hash('sha256', $token);
        $receipt = $this->billingGateway->purchase(new SubscriptionPurchase(
            idempotencyKey: $idempotencyKey,
            amountCents: $amount,
            currency: $plan->currency,
            subjectType: 'clinic',
            planKey: $planKey,
            billingCycle: $billingCycle,
        ));

        $this->draftStore->consumeVerifiedOtp($token, $verifiedOtp['challenge_id']);

        try {
            $result = DB::transaction(function () use (
                $data,
                $billingCycle,
                $adminPhone,
                $contactPhone,
                $plan,
                $receipt,
                $idempotencyKey,
                $amount,
            ): array {
                if (User::query()->where('email', $data['admin_email'])->orWhere('phone_number', $adminPhone->value)->exists()) {
                    throw ValidationException::withMessages(['admin_email' => ['El correo o teléfono del administrador ya está registrado.']]);
                }

                if (Clinic::query()->where('tax_id', $data['tax_id'])->exists()) {
                    throw ValidationException::withMessages(['tax_id' => ['La clínica ya está registrada.']]);
                }

                $lockedPlan = Plan::query()
                    ->lockForUpdate()
                    ->whereKey($plan->id)
                    ->where('is_active', true)
                    ->where('publicly_selectable', true)
                    ->first();

                if ($lockedPlan === null) {
                    throw ValidationException::withMessages(['plan' => ['El plan seleccionado no está disponible.']]);
                }

                $clinic = Clinic::query()->create([
                    'name' => $data['clinic_name'],
                    'slug' => $this->uniqueSlug($data['clinic_name']),
                    'tax_id' => $data['tax_id'],
                    'country_code' => $data['country_code'],
                    'contact_phone' => $contactPhone->value,
                    'address' => $data['address'],
                    'status' => ClinicStatus::Active,
                ]);

                $user = User::query()->create([
                    'name' => $data['admin_name'],
                    'email' => $data['admin_email'],
                    'google_sub' => $data['google_identity']['sub'] ?? null,
                    'google_avatar_url' => $data['google_identity']['avatar'] ?? null,
                    'google_linked_at' => isset($data['google_identity']) ? now() : null,
                    'email_verified_at' => isset($data['google_identity']) ? now() : null,
                    'phone_number' => $adminPhone->value,
                    'country_code' => $adminPhone->countryCode,
                    'password' => $data['password_hash'],
                    'status' => UserStatus::Active,
                    'onboarding_status' => OnboardingStatus::Completed,
                    'phone_verified_at' => now(),
                ]);

                $role = Role::query()->where('key', RoleKey::ClinicAdmin->value)->firstOrFail();
                $user->roles()->attach($role);
                $user->clinicMemberships()->create([
                    'clinic_id' => $clinic->id,
                    'role_id' => $role->id,
                    'status' => ClinicMembershipStatus::Active,
                ]);

                $subscription = ClinicSubscription::query()->create([
                    'clinic_id' => $clinic->id,
                    'plan_id' => $lockedPlan->id,
                    'billing_cycle' => BillingCycle::from($billingCycle),
                    'status' => SubscriptionStatus::Active,
                    'starts_at' => now(),
                    'provider' => $receipt->provider,
                    'provider_reference' => $receipt->subscriptionReference,
                ]);

                SubscriptionPayment::query()->create([
                    'clinic_subscription_id' => $subscription->id,
                    'idempotency_key' => $idempotencyKey,
                    'amount_cents' => $amount,
                    'currency' => $lockedPlan->currency,
                    'status' => 'succeeded',
                    'provider' => $receipt->provider,
                    'provider_reference' => $receipt->paymentReference,
                    'paid_at' => $receipt->paidAt,
                ]);

                return compact('user', 'clinic');
            }, attempts: 3);
        } catch (Throwable $exception) {
            $this->draftStore->restoreVerifiedOtp($token, $verifiedOtp);

            throw $exception;
        }

        $this->recordEvent->handleSafely($result['user'], 'clinic_registration_completed', metadata: [
            'clinic_id' => $result['clinic']->id,
            'plan' => $planKey,
            'google_linked' => isset($data['google_identity']),
        ]);
        $this->googleIdentity->consume($data['google_registration_token'] ?? null);
        $this->draftStore->forget($token);

        return $result;
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $counter = 2;

        while (Clinic::query()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$counter}";
            $counter++;
        }

        return $slug;
    }
}
