<?php

namespace App\Actions\Auth;

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
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CompleteClinicRegistration
{
    public function __construct(
        private ClinicRegistrationDraftStore $draftStore,
        private RecordAuthenticationEvent $recordEvent,
    ) {}

    /** @return array{user: User, clinic: Clinic} */
    public function handle(string $token, array $draft): array
    {
        $data = $draft['data'];
        $planKey = $draft['plan']['key'] ?? null;
        $billingCycle = $draft['plan']['billing_cycle'] ?? null;

        if (! is_string($planKey) || ! in_array($planKey, ['basic', 'professional'], true)) {
            throw ValidationException::withMessages(['plan' => ['Selecciona un plan válido.']]);
        }

        $adminPhone = PhoneNumber::from($data['admin_phone_number'], $data['country_code']);
        $contactPhone = PhoneNumber::from($data['contact_phone'], $data['country_code']);

        $result = DB::transaction(function () use ($data, $planKey, $billingCycle, $adminPhone, $contactPhone): array {
            if (User::query()->where('email', $data['admin_email'])->orWhere('phone_number', $adminPhone->value)->exists()) {
                throw ValidationException::withMessages(['admin_email' => ['El correo o teléfono del administrador ya está registrado.']]);
            }

            if (Clinic::query()->where('tax_id', $data['tax_id'])->exists()) {
                throw ValidationException::withMessages(['tax_id' => ['La clínica ya está registrada.']]);
            }

            $plan = Plan::query()
                ->where('key', $planKey)
                ->where('is_active', true)
                ->where('publicly_selectable', true)
                ->first();

            if ($plan === null) {
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
                'phone_number' => $adminPhone->value,
                'country_code' => $adminPhone->countryCode,
                'password' => $data['password_hash'],
                'status' => UserStatus::Active,
                'onboarding_status' => OnboardingStatus::Completed,
                'phone_verified_at' => now(),
            ]);

            $role = Role::query()->where('key', RoleKey::ClinicAdmin->value)->firstOrFail();
            $user->clinicMemberships()->create([
                'clinic_id' => $clinic->id,
                'role_id' => $role->id,
                'status' => ClinicMembershipStatus::Active,
            ]);

            $reference = 'sim_sub_'.Str::lower(Str::random(24));
            $subscription = ClinicSubscription::query()->create([
                'clinic_id' => $clinic->id,
                'plan_id' => $plan->id,
                'billing_cycle' => BillingCycle::from($billingCycle),
                'status' => SubscriptionStatus::Active,
                'starts_at' => now(),
                'provider' => 'simulated',
                'provider_reference' => $reference,
            ]);

            $amount = $billingCycle === BillingCycle::Annual->value
                ? $plan->annual_price_cents
                : $plan->monthly_price_cents;

            SubscriptionPayment::query()->create([
                'clinic_subscription_id' => $subscription->id,
                'idempotency_key' => $reference,
                'amount_cents' => $amount,
                'currency' => $plan->currency,
                'status' => 'succeeded',
                'provider' => 'simulated',
                'provider_reference' => 'sim_pay_'.Str::lower(Str::random(24)),
                'paid_at' => now(),
            ]);

            return compact('user', 'clinic');
        }, attempts: 3);

        $this->recordEvent->handle($result['user'], 'clinic_registration_completed', metadata: [
            'clinic_id' => $result['clinic']->id,
            'plan' => $planKey,
        ]);
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
