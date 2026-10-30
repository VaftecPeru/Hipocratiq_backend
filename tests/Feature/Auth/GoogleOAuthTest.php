<?php

namespace Tests\Feature\Auth;

use App\Enums\RoleKey;
use App\Models\User;
use App\Services\Auth\GoogleOAuthStateStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleOAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::clear();
        config([
            'services.google_oauth.enabled' => true,
            'services.google_oauth.client_id' => 'google-client-id',
            'services.google_oauth.client_secret' => 'google-client-secret',
            'services.google_oauth.redirect_uri' => 'https://hipocratiq.test/backend/api/v1/auth/google/callback',
            'services.google_oauth.frontend_callback_url' => 'https://hipocratiq.test/auth/google/callback',
        ]);
    }

    public function test_google_redirect_uses_authorization_code_flow_and_server_callback(): void
    {
        $response = $this->get('/api/v1/auth/google/redirect?requested_role=super_admin')
            ->assertRedirect();

        $location = (string) $response->headers->get('Location');
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $location);

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('google-client-id', $query['client_id']);
        $this->assertSame('https://hipocratiq.test/backend/api/v1/auth/google/callback', $query['redirect_uri']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('openid email profile', $query['scope']);
        $this->assertIsString($query['state']);
        $this->assertSame(64, strlen($query['state']));
    }

    public function test_google_callback_exchanges_code_and_redirects_with_single_use_handoff(): void
    {
        $state = app(GoogleOAuthStateStore::class)->createAuthorization([
            'requested_role' => RoleKey::SuperAdmin->value,
        ]);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'google-access-token',
                'token_type' => 'Bearer',
            ]),
            'https://openidconnect.googleapis.com/v1/userinfo' => Http::response([
                'sub' => 'google-sub-001',
                'email' => 'admin@example.test',
                'email_verified' => true,
                'name' => 'Google Admin',
                'picture' => 'https://images.example.test/avatar.png',
            ]),
        ]);

        $response = $this->get('/api/v1/auth/google/callback?state='.$state.'&code=authorization-code')
            ->assertRedirect();

        $location = (string) $response->headers->get('Location');
        $this->assertStringStartsWith('https://hipocratiq.test/auth/google/callback?handoff=', $location);

        Http::assertSentCount(2);
    }

    public function test_existing_super_admin_can_login_with_matching_verified_google_email(): void
    {
        $user = User::factory()->superAdmin()->create([
            'email' => 'superadmin@hipocratiq.test',
            'email_verified_at' => null,
        ]);
        $handoff = app(GoogleOAuthStateStore::class)->createHandoff([
            'requested_role' => RoleKey::SuperAdmin->value,
            'identity' => [
                'sub' => 'google-superadmin-001',
                'email' => 'superadmin@hipocratiq.test',
                'name' => 'Super Admin',
                'avatar' => null,
            ],
        ]);

        $response = $this->withSpaOrigin()
            ->postJson('/api/v1/auth/google/complete', ['handoff' => $handoff])
            ->assertOk()
            ->assertJsonPath('data.status', 'authenticated')
            ->assertJsonPath('data.actor_context', 'staff')
            ->assertJsonPath('data.user.id', $user->id);

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'google_sub' => 'google-superadmin-001',
        ]);
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertNotNull($user->fresh()->google_linked_at);

        $cookie = $response->getCookie(config('session.cookie'));
        $this->withCookie($cookie->getName(), $cookie->getValue())
            ->withSpaOrigin()
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id);
    }

    public function test_new_patient_google_identity_is_sent_to_safe_registration_flow(): void
    {
        $handoff = app(GoogleOAuthStateStore::class)->createHandoff([
            'requested_role' => 'patient',
            'identity' => [
                'sub' => 'google-patient-001',
                'email' => 'new.patient@example.test',
                'name' => 'Paciente Google',
                'avatar' => null,
            ],
        ]);

        $response = $this->withSpaOrigin()
            ->postJson('/api/v1/auth/google/complete', ['handoff' => $handoff])
            ->assertOk()
            ->assertJsonPath('data.status', 'registration_required')
            ->assertJsonPath('data.registration_type', 'patient')
            ->assertJsonPath('data.identity.email', 'new.patient@example.test');

        $registrationToken = (string) $response->json('data.registration_token');

        $this->getJson('/api/v1/auth/google/registration-profile?token='.$registrationToken.'&type=patient')
            ->assertOk()
            ->assertJsonPath('data.identity.name', 'Paciente Google')
            ->assertJsonPath('data.identity.email', 'new.patient@example.test');
    }

    public function test_google_does_not_self_register_privileged_super_admin_account(): void
    {
        $handoff = app(GoogleOAuthStateStore::class)->createHandoff([
            'requested_role' => RoleKey::SuperAdmin->value,
            'identity' => [
                'sub' => 'google-unknown-admin',
                'email' => 'unknown-admin@example.test',
                'name' => 'Unknown Admin',
                'avatar' => null,
            ],
        ]);

        $this->withSpaOrigin()
            ->postJson('/api/v1/auth/google/complete', ['handoff' => $handoff])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('google');
    }

    public function test_google_handoff_is_single_use(): void
    {
        $user = User::factory()->superAdmin()->create(['email' => 'single-use@example.test']);
        $handoff = app(GoogleOAuthStateStore::class)->createHandoff([
            'requested_role' => RoleKey::SuperAdmin->value,
            'identity' => [
                'sub' => 'google-single-use',
                'email' => $user->email,
                'name' => $user->name,
                'avatar' => null,
            ],
        ]);

        $this->withSpaOrigin()
            ->postJson('/api/v1/auth/google/complete', ['handoff' => $handoff])
            ->assertOk();

        $this->withSpaOrigin()
            ->postJson('/api/v1/auth/google/complete', ['handoff' => $handoff])
            ->assertUnprocessable();
    }

    private function withSpaOrigin(): static
    {
        return $this->withCredentials()->withHeader('Origin', 'http://localhost:3000');
    }
}
