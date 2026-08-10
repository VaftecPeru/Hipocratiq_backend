<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminPlatformSettingsUpdateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'default_country_code' => ['sometimes', 'string', 'size:2', Rule::in(['MX', 'PE', 'US'])],
            'enabled_country_codes' => ['sometimes', 'array', 'min:1'],
            'enabled_country_codes.*' => ['string', 'size:2', Rule::in(['MX', 'PE', 'US'])],
            'branding' => ['sometimes', 'array'],
            'branding.platform_name' => ['sometimes', 'string', 'min:2', 'max:80'],
            'branding.support_email' => ['sometimes', 'nullable', 'email:rfc', 'max:255'],
            'branding.support_url' => ['sometimes', 'nullable', 'url:http,https', 'max:255'],
            'localization' => ['sometimes', 'array'],
            'localization.default_locale' => ['sometimes', Rule::in(['es-MX', 'es-PE', 'en-US'])],
            'localization.enabled_locales' => ['sometimes', 'array', 'min:1'],
            'localization.enabled_locales.*' => [Rule::in(['es-MX', 'es-PE', 'en-US'])],
            'ai' => ['sometimes', 'array'],
            'ai.frequency' => ['sometimes', Rule::in(['every_12_hours', 'daily', 'weekly'])],
            'ai.window_days' => ['sometimes', 'integer', Rule::in([14, 30, 60])],
            'ai.sensitivity' => ['sometimes', Rule::in(['low', 'medium', 'high'])],
            'ai.automatic_alerts' => ['sometimes', 'boolean'],
            'ai.enabled_models' => ['sometimes', 'array'],
            'ai.enabled_models.*' => [Rule::in(['respiratory', 'dengue', 'gastrointestinal', 'hospital_occupancy'])],
        ];
    }
}
