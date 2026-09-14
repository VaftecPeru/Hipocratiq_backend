<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Admin\RecordAdminAuditEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AdminPlatformSettingsUpdateRequest;
use App\Models\PlatformSetting;
use Illuminate\Http\JsonResponse;

class AdminPlatformSettingsController extends Controller
{
    private const DEFAULT_COUNTRY_CODE = 'MX';

    private const DEFAULT_LOCALE = 'es-MX';

    private const SUPPORTED_COUNTRY_CODES = ['MX', 'PE', 'US'];

    private const SUPPORTED_LOCALES = ['es-MX', 'es-PE', 'en-US'];

    public function show(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->serializeSettings($this->settings()),
        ]);
    }

    public function publicShow(): JsonResponse
    {
        $settings = $this->settings();
        $defaultCountryCode = in_array($settings->default_country_code, self::SUPPORTED_COUNTRY_CODES, true)
            ? $settings->default_country_code
            : self::DEFAULT_COUNTRY_CODE;

        return response()->json([
            'success' => true,
            'data' => [
                'default_country_code' => $defaultCountryCode,
                'localization' => $this->serializePublicLocalization($settings),
            ],
        ]);
    }

    public function update(AdminPlatformSettingsUpdateRequest $request, RecordAdminAuditEvent $audit): JsonResponse
    {
        $settings = $this->settings();
        $before = $this->serializeSettings($settings);
        $data = $request->validated();
        $defaultCountryCode = $data['default_country_code'] ?? $settings->default_country_code;
        $enabledCountryCodes = $data['enabled_country_codes'] ?? $settings->enabled_country_codes;
        $enabledCountryCodes = array_values(array_unique([
            self::DEFAULT_COUNTRY_CODE,
            ...$enabledCountryCodes,
            $defaultCountryCode,
        ]));

        $branding = $data['branding'] ?? [];
        $localization = $data['localization'] ?? [];
        $enabledLocales = $localization['enabled_locales'] ?? ($settings->enabled_locales ?: ['es-MX']);
        $defaultLocale = $localization['default_locale'] ?? $settings->default_locale;
        $enabledLocales = array_values(array_unique([...$enabledLocales, $defaultLocale]));
        $aiConfiguration = array_replace($this->defaultAiConfiguration(), $settings->ai_configuration ?? [], $data['ai'] ?? []);

        $settings->update([
            'default_country_code' => $defaultCountryCode,
            'enabled_country_codes' => $enabledCountryCodes,
            'platform_name' => $branding['platform_name'] ?? $settings->platform_name,
            'support_email' => array_key_exists('support_email', $branding) ? $branding['support_email'] : $settings->support_email,
            'support_url' => array_key_exists('support_url', $branding) ? $branding['support_url'] : $settings->support_url,
            'default_locale' => $defaultLocale,
            'enabled_locales' => $enabledLocales,
            'ai_configuration' => $aiConfiguration,
        ]);
        $settings->refresh();
        $audit->handle(
            $request->user(),
            $request,
            'settings',
            'platform_settings_updated',
            PlatformSetting::class,
            $settings->id,
            'Configuración regional global',
            countryCode: $settings->default_country_code,
            metadata: ['before' => $before, 'after' => $this->serializeSettings($settings)],
            critical: true,
        );

        return response()->json([
            'success' => true,
            'data' => $this->serializeSettings($settings),
        ]);
    }

    private function settings(): PlatformSetting
    {
        return PlatformSetting::query()->firstOrCreate(
            ['id' => 1],
            [
                'default_country_code' => self::DEFAULT_COUNTRY_CODE,
                'enabled_country_codes' => [self::DEFAULT_COUNTRY_CODE],
                'platform_name' => 'MEDIQ',
                'default_locale' => 'es-MX',
                'enabled_locales' => ['es-MX'],
                'ai_configuration' => $this->defaultAiConfiguration(),
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeSettings(PlatformSetting $settings): array
    {
        $enabledCountryCodes = array_values(array_unique(array_filter(
            [self::DEFAULT_COUNTRY_CODE, ...($settings->enabled_country_codes ?? []), $settings->default_country_code],
            fn (string $countryCode): bool => in_array($countryCode, self::SUPPORTED_COUNTRY_CODES, true),
        )));

        return [
            'default_country_code' => $settings->default_country_code,
            'enabled_country_codes' => $enabledCountryCodes,
            'header_country_codes' => $enabledCountryCodes,
            'branding' => [
                'platform_name' => $settings->platform_name ?: 'MEDIQ',
                'support_email' => $settings->support_email,
                'support_url' => $settings->support_url,
                'asset_uploads_available' => false,
            ],
            'localization' => [
                ...$this->serializePublicLocalization($settings),
                'supported_locales' => self::SUPPORTED_LOCALES,
                'catalog_status' => 'source_only',
            ],
            'ai' => array_replace($this->defaultAiConfiguration(), $settings->ai_configuration ?? []),
            'ai_capabilities' => [
                'status' => config('admin_operations.ai.enabled') ? 'configured' : 'not_configured',
                'provider' => config('admin_operations.ai.provider'),
                'predictions_available' => false,
                'data_sources' => [],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultAiConfiguration(): array
    {
        return [
            'frequency' => 'daily',
            'window_days' => 30,
            'sensitivity' => 'medium',
            'automatic_alerts' => false,
            'enabled_models' => [],
        ];
    }

    /**
     * @return array{default_locale: string, enabled_locales: list<string>}
     */
    private function serializePublicLocalization(PlatformSetting $settings): array
    {
        $defaultLocale = in_array($settings->default_locale, self::SUPPORTED_LOCALES, true)
            ? $settings->default_locale
            : self::DEFAULT_LOCALE;
        $enabledLocales = array_values(array_unique(array_filter(
            [$defaultLocale, ...($settings->enabled_locales ?? [])],
            fn (mixed $locale): bool => is_string($locale) && in_array($locale, self::SUPPORTED_LOCALES, true),
        )));

        return [
            'default_locale' => $defaultLocale,
            'enabled_locales' => $enabledLocales,
        ];
    }
}
