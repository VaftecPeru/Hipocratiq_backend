<?php

namespace Tests\Feature;

use App\Models\DiagnosticProvider;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class DiagnosticProviderRegistrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function payload(): array
    {
        return [
            'provider_type' => 'laboratory',
            'name' => 'Laboratorio Sintético de Pruebas',
            'country_code' => 'MX',
            'tax_id' => 'ABC010203XY1',
            'contact_name' => 'Operador UAT',
            'contact_email' => 'laboratorio@example.test',
            'contact_phone' => '55 1234 5678',
            'terms_accepted' => true,
        ];
    }

    public function test_registration_is_pending_without_creating_an_account_or_affiliation(): void
    {
        $this->postJson('/api/v1/auth/register/diagnostic-provider/request', $this->payload())
            ->assertStatus(202)
            ->assertJsonPath('data.next_step', 'await_verification')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonMissingPath('data.access_token');

        $this->assertDatabaseHas('diagnostic_providers', [
            'country_code' => 'MX',
            'provider_type' => 'laboratory',
            'status' => 'pending',
            'contact_phone' => '+525512345678',
        ]);
        $this->assertDatabaseCount('diagnostic_provider_memberships', 0);
        $this->assertDatabaseCount('diagnostic_clinic_affiliations', 0);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_untrusted_status_and_approval_fields_never_activate_provider(): void
    {
        $this->postJson('/api/v1/auth/register/diagnostic-provider/request', [
            ...$this->payload(),
            'status' => 'active',
            'verified_at' => now()->toIso8601String(),
            'verified_by_user_id' => 1,
        ])->assertStatus(202);

        $provider = DiagnosticProvider::query()->firstOrFail();
        $this->assertSame('pending', $provider->status);
        $this->assertNull($provider->verified_at);
        $this->assertNull($provider->verified_by_user_id);
    }

    public function test_rejects_invalid_provider_type_and_mismatched_country_phone(): void
    {
        $this->postJson('/api/v1/auth/register/diagnostic-provider/request', [
            ...$this->payload(),
            'provider_type' => 'admin',
            'contact_phone' => '+51987654321',
        ])->assertStatus(422)->assertJsonValidationErrors(['provider_type', 'contact_phone']);

        $this->assertDatabaseCount('diagnostic_providers', 0);
    }

    public function test_country_tax_identity_cannot_be_registered_twice(): void
    {
        $this->postJson('/api/v1/auth/register/diagnostic-provider/request', $this->payload())
            ->assertStatus(202);

        $this->postJson('/api/v1/auth/register/diagnostic-provider/request', $this->payload())
            ->assertStatus(422)
            ->assertJsonValidationErrors('tax_id');

        $this->assertDatabaseCount('diagnostic_providers', 1);
    }

    public function test_requires_terms_of_service_accepted(): void
    {
        $this->postJson('/api/v1/auth/register/diagnostic-provider/request', [
            ...$this->payload(),
            'terms_accepted' => false,
        ])->assertStatus(422)->assertJsonValidationErrors('terms_accepted');
    }
}
