<?php

namespace App\Actions\Admin;

use App\Enums\BillingCycle;
use App\Enums\ClinicStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Clinic;
use App\Models\ClinicSubscription;
use App\Models\Plan;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class CreateAdminClinic
{
    /** @param array{name: string, tax_id: string, country_code: string, contact_phone: string, address: string, plan: string, billing_cycle: string} $data */
    public function handle(array $data): Clinic
    {
        try {
            $contactPhone = PhoneNumber::from($data['contact_phone'], $data['country_code']);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['contact_phone' => [$exception->getMessage()]]);
        }

        return DB::transaction(function () use ($data, $contactPhone): Clinic {
            $taxId = mb_strtoupper(trim($data['tax_id']));
            if (Clinic::query()->where('tax_id', $taxId)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['tax_id' => ['La identificación fiscal ya está registrada.']]);
            }

            $plan = Plan::query()
                ->where('key', $data['plan'])
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            if ($plan === null) {
                throw ValidationException::withMessages(['plan' => ['El plan seleccionado no está disponible.']]);
            }

            $clinic = Clinic::query()->create([
                'name' => trim($data['name']),
                'slug' => $this->uniqueSlug($data['name']),
                'tax_id' => $taxId,
                'country_code' => $data['country_code'],
                'contact_phone' => $contactPhone->value,
                'address' => trim($data['address']),
                'status' => ClinicStatus::Active,
            ]);

            ClinicSubscription::query()->create([
                'clinic_id' => $clinic->id,
                'plan_id' => $plan->id,
                'billing_cycle' => BillingCycle::from($data['billing_cycle']),
                'status' => SubscriptionStatus::Active,
                'starts_at' => now(),
                'provider' => 'admin_manual',
                'provider_reference' => 'admin-clinic-'.Str::uuid(),
            ]);

            return $clinic;
        });
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'clinic';
        $slug = $base;
        $counter = 2;

        while (Clinic::query()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$counter}";
            $counter++;
        }

        return $slug;
    }
}
