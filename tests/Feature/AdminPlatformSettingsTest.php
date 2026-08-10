<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\OtpCodeGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FixedOtpCodeGenerator;
use Tests\TestCase;

class AdminPlatformSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(OtpCodeGenerator::class, new FixedOtpCodeGenerator);
    }

    public function test_super_admin_can_read_default_platform_country_settings(): void
    {
        $this->authenticateAdmin();

        $this->withSpaOrigin()
            ->getJson('/api/v1/auth/admin/platform-settings')
            ->assertOk()
            ->assertJsonPath('data.default_country_code', 'MX')
            ->assertJsonPath('data.enabled_country_codes', ['MX'])
            ->assertJsonPath('data.header_country_codes', ['MX']);
    }

    public function test_super_admin_can_update_the_secondary_header_country(): void
    {
        $this->authenticateAdmin();

        $this->withSpaOrigin()
            ->patchJson('/api/v1/auth/admin/platform-settings', [
                'default_country_code' => 'PE',
                'enabled_country_codes' => ['MX', 'PE'],
            ])
            ->assertOk()
            ->assertJsonPath('data.default_country_code', 'PE')
            ->assertJsonPath('data.enabled_country_codes', ['MX', 'PE'])
            ->assertJsonPath('data.header_country_codes', ['MX', 'PE']);

        $this->assertDatabaseHas('platform_settings', [
            'id' => 1,
            'default_country_code' => 'PE',
        ]);
    }

    public function test_platform_country_settings_reject_unsupported_countries(): void
    {
        $this->authenticateAdmin();

        $this->withSpaOrigin()
            ->patchJson('/api/v1/auth/admin/platform-settings', [
                'default_country_code' => 'AR',
                'enabled_country_codes' => ['MX', 'AR'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['default_country_code', 'enabled_country_codes.1']);
    }

    public function test_super_admin_can_persist_branding_localization_and_ai_configuration(): void
    {
        $this->authenticateAdmin();

        $this->withSpaOrigin()->patchJson('/api/v1/auth/admin/platform-settings', [
            'branding' => [
                'platform_name' => 'Hipocratio Global',
                'support_email' => 'soporte@hipocratio.test',
                'support_url' => 'https://support.hipocratio.test',
            ],
            'localization' => [
                'default_locale' => 'es-PE',
                'enabled_locales' => ['es-MX', 'es-PE'],
            ],
            'ai' => [
                'frequency' => 'every_12_hours',
                'window_days' => 14,
                'sensitivity' => 'high',
                'automatic_alerts' => true,
                'enabled_models' => ['dengue'],
            ],
        ])->assertOk()
            ->assertJsonPath('data.branding.platform_name', 'Hipocratio Global')
            ->assertJsonPath('data.localization.default_locale', 'es-PE')
            ->assertJsonPath('data.ai.enabled_models', ['dengue'])
            ->assertJsonPath('data.ai_capabilities.predictions_available', false);

        $this->assertDatabaseHas('platform_settings', [
            'id' => 1,
            'platform_name' => 'Hipocratio Global',
            'default_locale' => 'es-PE',
        ]);
        $this->assertDatabaseHas('admin_audit_events', [
            'module' => 'settings',
            'action' => 'platform_settings_updated',
            'is_critical' => true,
        ]);
    }

    private function authenticateAdmin(): User
    {
        $admin = User::factory()->superAdmin()->create([
            'email' => 'admin@example.test',
            'phone_number' => '+525512345678',
            'password' => 'SecurePass123',
        ]);

        $login = $this->withSpaOrigin()->postJson('/api/v1/auth/login', [
            'country_code' => 'MX',
            'phone_number' => '55 1234 5678',
            'password' => 'SecurePass123',
        ])->assertAccepted();
        $verification = $this->withSpaOrigin()->postJson('/api/v1/auth/login/verify-otp', [
            'challenge_id' => $login->json('data.challenge_id'),
            'code' => '123456',
        ])->assertOk();
        $sessionCookie = $verification->getCookie(config('session.cookie'));

        $this->withCookie($sessionCookie->getName(), $sessionCookie->getValue());

        return $admin;
    }

    private function withSpaOrigin(): static
    {
        return $this->withCredentials()->withHeader('Origin', 'http://localhost:3000');
    }
}
