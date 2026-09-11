<?php

namespace Tests\Feature;

use App\Models\Specialty;
use App\Models\User;
use App\Services\Auth\OtpCodeGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FixedOtpCodeGenerator;
use Tests\TestCase;

class AdminSpecialtyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(OtpCodeGenerator::class, new FixedOtpCodeGenerator);
    }

    public function test_super_admin_can_create_update_and_list_specialties(): void
    {
        $this->authenticateAdmin();

        $root = $this->withSpaOrigin()->postJson('/api/v1/auth/admin/specialties', [
            'code' => 'cardiologia',
            'name' => 'Cardiología',
        ])->assertCreated()
            ->assertJsonPath('data.code', 'cardiologia')
            ->assertJsonPath('data.is_active', true);
        $rootId = $root->json('data.id');

        $child = $this->withSpaOrigin()->postJson('/api/v1/auth/admin/specialties', [
            'code' => 'cardiologia_intervencionista',
            'name' => 'Cardiología intervencionista',
            'parent_id' => $rootId,
        ])->assertCreated()
            ->assertJsonPath('data.parent_id', $rootId);
        $childId = $child->json('data.id');

        $this->withSpaOrigin()->patchJson("/api/v1/auth/admin/specialties/{$childId}", [
            'is_active' => false,
        ])->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->withSpaOrigin()->getJson('/api/v1/auth/admin/specialties')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Cardiología')
            ->assertJsonPath('data.1.is_active', false);

        $this->assertDatabaseHas('admin_audit_events', ['module' => 'specialties', 'action' => 'specialty_created']);
        $this->assertDatabaseHas('admin_audit_events', ['module' => 'specialties', 'action' => 'specialty_updated']);
    }

    public function test_specialty_admin_rejects_invalid_parent_and_unsafe_parent_deactivation(): void
    {
        $root = Specialty::factory()->create(['code' => 'medicina_interna']);
        $child = Specialty::factory()->create(['code' => 'cardiologia', 'parent_id' => $root->id]);
        $this->authenticateAdmin();

        $this->withSpaOrigin()->postJson('/api/v1/auth/admin/specialties', [
            'code' => 'subespecialidad_invalida',
            'name' => 'Subespecialidad inválida',
            'parent_id' => $child->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('parent_id');

        $this->withSpaOrigin()->patchJson("/api/v1/auth/admin/specialties/{$root->id}", [
            'is_active' => false,
        ])->assertUnprocessable()->assertJsonValidationErrors('is_active');
    }

    public function test_specialty_admin_requires_an_authenticated_admin_session(): void
    {
        $this->getJson('/api/v1/auth/admin/specialties')->assertUnauthorized();
    }

    private function authenticateAdmin(): void
    {
        User::factory()->superAdmin()->create([
            'email' => 'specialty-admin@example.test',
            'phone_number' => '+525512345678',
            'password' => 'SecurePass123',
        ]);
        $login = $this->withSpaOrigin()->postJson('/api/v1/auth/login', [
            'country_code' => 'MX',
            'phone_number' => '55 1234 5678',
            'password' => 'SecurePass123',
            'requested_role' => 'super_admin',
        ])->assertAccepted();
        $verification = $this->withSpaOrigin()->postJson('/api/v1/auth/login/verify-otp', [
            'challenge_id' => $login->json('data.challenge_id'),
            'code' => '123456',
        ])->assertOk();
        $sessionCookie = $verification->getCookie(config('session.cookie'));
        $this->withCookie($sessionCookie->getName(), $sessionCookie->getValue());
    }

    private function withSpaOrigin(): static
    {
        return $this->withCredentials()->withHeader('Origin', 'http://localhost:3000');
    }
}
