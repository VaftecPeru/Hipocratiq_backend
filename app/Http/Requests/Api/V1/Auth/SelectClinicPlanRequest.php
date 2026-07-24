<?php

namespace App\Http\Requests\Api\V1\Auth;

use App\Enums\BillingCycle;
use App\Enums\PlanKey;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SelectClinicPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'plan' => [
                'required',
                Rule::enum(PlanKey::class),
                Rule::in([PlanKey::Basic->value, PlanKey::Professional->value]),
                Rule::exists('plans', 'key')->where(fn ($query) => $query
                    ->where('is_active', true)
                    ->where('publicly_selectable', true)),
            ],
            'billing_cycle' => ['required', Rule::enum(BillingCycle::class)],
        ];
    }
}
