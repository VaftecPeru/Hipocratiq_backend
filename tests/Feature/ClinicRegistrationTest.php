<?php

namespace Tests\Feature;

use App\Enums\RoleKey;
use App\Enums\RoleScope;
use App\Models\Clinic;
use App\Models\Plan;
use App\Models\Role;
use App\Models\User;
use App\Services\Auth\OtpCodeGenerator;
use App\Services\Sms\ArraySmsSender;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\Support\FixedOtpCodeGenerator;
use Tests\TestCase;

class ClinicRegistrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(OtpCodeGenerator::class, new FixedOtpCodeGenerator);
        $this->app->make(ArraySmsSender::class)->clear();
        Role::query()->create(['key' => RoleKey::ClinicAdmin->value, 'name' => 'Administrador Clínico', 'scope' => RoleScope::Organization, 'is_system' => true]);
        Plan::query()->create(['key' => 'basic', 'name' => 'Básico', 'monthly_price_cents' => 4900, 'annual_price_cents' => 47000, 'currency' => 'USD', 'features' => [], 'publicly_selectable' => true, 'is_active' => true]);
        Plan::query()->create(['key' => 'professional', 'name' => 'Profesional', 'monthly_price_cents' => 9900, 'annual_price_cents' => 95000, 'currency' => 'USD', 'features' => [], 'publicly_selectable' => true, 'is_active' => true]);
    }

    public function test_clinic_registration_creates_all_records_only_after_plan_and_otp(): void
    {
        $draft = $this->withSpaOrigin()->postJson('/api/v1/auth/register/clinic/draft', $this->payload());
        $draft->assertCreated()->assertJsonPath('data.next_step', 'select_plan');
        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, Clinic::query()->count());

        $cookie = $draft->getCookie('clinic_registration');
        $plan = $this->withCookie($cookie->getName(), $cookie->getValue())->withSpaOrigin()->postJson('/api/v1/auth/register/clinic/plan', [
            'plan' => 'basic',
            'billing_cycle' => 'monthly',
        ]);
        $plan->assertOk()->assertJsonPath('data.next_step', 'verify_otp');

        $verify = $this->withCookie($cookie->getName(), $cookie->getValue())->withSpaOrigin()->postJson('/api/v1/auth/register/clinic/verify-otp', [
            'challenge_id' => $plan->json('data.challenge_id'),
            'code' => '123456',
        ]);

        $verify->assertOk()->assertJsonPath('data.next_step', 'success');
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('clinics', 1);
        $this->assertDatabaseCount('clinic_memberships', 1);
        $this->assertDatabaseCount('clinic_subscriptions', 1);
        $this->assertDatabaseCount('subscription_payments', 1);
        $this->assertDatabaseCount('role_user', 1);
        $this->assertTrue(User::query()->sole()->hasRole(RoleKey::ClinicAdmin));
        $this->assertAuthenticated();
        $sessionCookie = $verify->getCookie(config('session.cookie'));
        $this->withCookie($sessionCookie->getName(), $sessionCookie->getValue())->withSpaOrigin()->getJson('/api/v1/auth/clinic/dashboard')
            ->assertOk()
            ->assertJsonPath('data.subscription.plan', 'basic');

        $this->withCookie($sessionCookie->getName(), $sessionCookie->getValue())->withSpaOrigin()->postJson('/api/v1/auth/logout')->assertNoContent();
        $login = $this->withSpaOrigin()->postJson('/api/v1/auth/otp/request', [
            'purpose' => 'login',
            'identifier' => 'ana@clinica.test',
            'actor_context' => 'staff',
            'password' => 'SecurePass123',
        ]);
        $login->assertAccepted();
        $relogin = $this->withSpaOrigin()->postJson('/api/v1/auth/otp/verify', [
            'purpose' => 'login',
            'challenge_id' => $login->json('data.challenge_id'),
            'code' => '123456',
        ]);
        $relogin->assertOk()->assertJsonPath('data.actor_context', 'clinic_admin');
        $reloginCookie = $relogin->getCookie(config('session.cookie'));
        $this->withCookie($reloginCookie->getName(), $reloginCookie->getValue())
            ->withSpaOrigin()
            ->getJson('/api/v1/auth/clinic/dashboard')
            ->assertOk();

        $this->withCookie($reloginCookie->getName(), $reloginCookie->getValue())
            ->withSpaOrigin()
            ->postJson('/api/v1/auth/logout')
            ->assertNoContent();
        $legacyLogin = $this->withSpaOrigin()->postJson('/api/v1/auth/login', [
            'country_code' => 'MX',
            'phone_number' => '55 8765 4321',
            'password' => 'SecurePass123',
        ]);
        $legacyLogin->assertAccepted();
        $legacyVerification = $this->withSpaOrigin()->postJson('/api/v1/auth/login/verify-otp', [
            'challenge_id' => $legacyLogin->json('data.challenge_id'),
            'code' => '123456',
        ]);
        $legacyVerification->assertOk()->assertJsonPath('data.actor_context', 'clinic_admin');
        $legacyCookie = $legacyVerification->getCookie(config('session.cookie'));
        $this->withCookie($legacyCookie->getName(), $legacyCookie->getValue())
            ->withSpaOrigin()
            ->getJson('/api/v1/auth/clinic/dashboard')
            ->assertOk();
    }

    public function test_clinic_admin_cannot_bypass_password_using_patient_context(): void
    {
        $draft = $this->withSpaOrigin()->postJson('/api/v1/auth/register/clinic/draft', $this->payload());
        $cookie = $draft->getCookie('clinic_registration');
        $plan = $this->withCookie($cookie->getName(), $cookie->getValue())->withSpaOrigin()->postJson('/api/v1/auth/register/clinic/plan', [
            'plan' => 'basic',
            'billing_cycle' => 'monthly',
        ]);
        $verification = $this->withCookie($cookie->getName(), $cookie->getValue())->withSpaOrigin()->postJson('/api/v1/auth/register/clinic/verify-otp', [
            'challenge_id' => $plan->json('data.challenge_id'),
            'code' => '123456',
        ]);
        $sessionCookie = $verification->getCookie(config('session.cookie'));
        $this->withCookie($sessionCookie->getName(), $sessionCookie->getValue())->withSpaOrigin()->postJson('/api/v1/auth/logout')->assertNoContent();

        $user = User::query()->sole();
        $user->patientProfile()->create();

        $this->postJson('/api/v1/auth/otp/request', [
            'purpose' => 'login',
            'identifier' => 'ana@clinica.test',
            'actor_context' => 'patient',
            'client_type' => 'mobile',
            'device_name' => 'Untrusted device',
            'device_id' => 'untrusted-installation',
        ])->assertUnprocessable()->assertJsonValidationErrors('identifier');

        $this->assertDatabaseCount('otp_challenges', 0);
    }

    public function test_registration_without_plan_does_not_create_domain_records(): void
    {
        $response = $this->withSpaOrigin()->postJson('/api/v1/auth/register/clinic/draft', $this->payload());

        $response->assertCreated();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('clinics', 0);
        $this->assertDatabaseCount('clinic_subscriptions', 0);
    }

    public function test_enterprise_cannot_be_selected_through_public_registration(): void
    {
        $draft = $this->withSpaOrigin()->postJson('/api/v1/auth/register/clinic/draft', $this->payload());
        $cookie = $draft->getCookie('clinic_registration');

        $this->withCookie($cookie->getName(), $cookie->getValue())->withSpaOrigin()->postJson('/api/v1/auth/register/clinic/plan', [
            'plan' => 'enterprise',
            'billing_cycle' => 'monthly',
        ])->assertUnprocessable()->assertJsonValidationErrors('plan');
    }

    public function test_wrong_otp_never_creates_clinic_or_user(): void
    {
        $draft = $this->withSpaOrigin()->postJson('/api/v1/auth/register/clinic/draft', $this->payload());
        $cookie = $draft->getCookie('clinic_registration');
        $plan = $this->withCookie($cookie->getName(), $cookie->getValue())->withSpaOrigin()->postJson('/api/v1/auth/register/clinic/plan', [
            'plan' => 'professional',
            'billing_cycle' => 'annual',
        ]);

        $this->withCookie($cookie->getName(), $cookie->getValue())->withSpaOrigin()->postJson('/api/v1/auth/register/clinic/verify-otp', [
            'challenge_id' => $plan->json('data.challenge_id'),
            'code' => '000000',
        ])->assertUnprocessable()->assertJsonValidationErrors('code');

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('clinics', 0);
        $this->assertDatabaseCount('clinic_subscriptions', 0);
    }

    public function test_missing_clinic_admin_role_rolls_back_registration_records(): void
    {
        $draft = $this->withSpaOrigin()->postJson('/api/v1/auth/register/clinic/draft', $this->payload());
        $cookie = $draft->getCookie('clinic_registration');
        $plan = $this->withCookie($cookie->getName(), $cookie->getValue())->withSpaOrigin()->postJson('/api/v1/auth/register/clinic/plan', [
            'plan' => 'basic',
            'billing_cycle' => 'monthly',
        ]);

        Role::query()->where('key', RoleKey::ClinicAdmin->value)->delete();

        $this->withoutExceptionHandling();
        $this->assertThrows(
            fn () => $this->withCookie($cookie->getName(), $cookie->getValue())->withSpaOrigin()->postJson('/api/v1/auth/register/clinic/verify-otp', [
                'challenge_id' => $plan->json('data.challenge_id'),
                'code' => '123456',
            ]),
            ModelNotFoundException::class,
        );

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('clinics', 0);
        $this->assertDatabaseCount('clinic_memberships', 0);
        $this->assertDatabaseCount('clinic_subscriptions', 0);
        $this->assertDatabaseCount('subscription_payments', 0);
        $this->assertDatabaseCount('role_user', 0);
    }

    public function test_clinic_otp_expires_at_exactly_five_minutes(): void
    {
        $draft = $this->withSpaOrigin()->postJson('/api/v1/auth/register/clinic/draft', $this->payload());
        $cookie = $draft->getCookie('clinic_registration');
        $plan = $this->withCookie($cookie->getName(), $cookie->getValue())->withSpaOrigin()->postJson('/api/v1/auth/register/clinic/plan', [
            'plan' => 'basic',
            'billing_cycle' => 'monthly',
        ]);

        $this->travel(5)->minutes();

        $this->withCookie($cookie->getName(), $cookie->getValue())->withSpaOrigin()->postJson('/api/v1/auth/register/clinic/verify-otp', [
            'challenge_id' => $plan->json('data.challenge_id'),
            'code' => '123456',
        ])->assertUnprocessable()->assertJsonValidationErrors('code');

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('clinics', 0);
    }

    public function test_clinic_registration_allows_only_five_otp_resends(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $draft = $this->withSpaOrigin()->postJson('/api/v1/auth/register/clinic/draft', $this->payload());
        $cookie = $draft->getCookie('clinic_registration');
        $this->withCookie($cookie->getName(), $cookie->getValue())->withSpaOrigin()->postJson('/api/v1/auth/register/clinic/plan', [
            'plan' => 'basic',
            'billing_cycle' => 'monthly',
        ])->assertJsonPath('data.resends_remaining', 5);

        for ($resend = 1; $resend <= 5; $resend++) {
            $this->travel(61)->seconds();
            $this->withCookie($cookie->getName(), $cookie->getValue())->withSpaOrigin()
                ->postJson('/api/v1/auth/register/clinic/resend-otp')
                ->assertOk()
                ->assertJsonPath('data.resends_remaining', 5 - $resend);
        }

        $this->travel(61)->seconds();
        $this->withCookie($cookie->getName(), $cookie->getValue())->withSpaOrigin()
            ->postJson('/api/v1/auth/register/clinic/resend-otp')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('otp');
        $this->assertCount(6, $this->app->make(ArraySmsSender::class)->messages());
    }

    public function test_protected_api_routes_return_json_401_without_accept_header(): void
    {
        $this->get('/api/v1/auth/me')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    private function payload(): array
    {
        return [
            'clinic_name' => 'Clínica Santa Elena',
            'tax_id' => 'RFC-123456',
            'country_code' => 'MX',
            'contact_phone' => '55 1234 5678',
            'address' => 'Calle Principal 123, Ciudad de México',
            'admin_name' => 'Ana Martínez',
            'admin_email' => 'ana@clinica.test',
            'admin_phone_number' => '55 8765 4321',
            'password' => 'SecurePass123',
            'password_confirmation' => 'SecurePass123',
            'terms_accepted' => true,
        ];
    }

    private function withSpaOrigin(): static
    {
        return $this->withCredentials()->withHeader('Origin', 'http://localhost:3000');
    }
}
