<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\BillingCycle;
use App\Enums\ClinicStatus;
use App\Enums\SubscriptionStatus;
use App\Models\ClinicSubscription;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class MembershipSubscriptionCreateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'clinic_id' => ['required', 'integer', Rule::exists('clinics', 'id')->where(fn ($query) => $query->where('status', ClinicStatus::Active->value))],
            'plan_id' => ['required', 'integer', Rule::exists('plans', 'id')->where(fn ($query) => $query->where('is_active', true))],
            'billing_cycle' => ['required', Rule::enum(BillingCycle::class)],
            'starts_at' => ['required', 'date'],
            'auto_renew' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $clinicId = $this->integer('clinic_id');

            if ($clinicId === 0 || $validator->errors()->has('clinic_id')) {
                return;
            }

            $hasActiveSubscription = ClinicSubscription::query()
                ->where('clinic_id', $clinicId)
                ->where('status', SubscriptionStatus::Active->value)
                ->where(function ($query): void {
                    $query->whereNull('ends_at')->orWhere('ends_at', '>', now());
                })
                ->exists();

            if ($hasActiveSubscription) {
                $validator->errors()->add('clinic_id', 'La clínica ya tiene una suscripción activa.');
            }
        });
    }
}
