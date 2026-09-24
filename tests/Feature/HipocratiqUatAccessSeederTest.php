<?php

namespace Tests\Feature;

use App\Enums\DoctorPracticeType;
use App\Models\User;
use App\Services\Auth\OtpCodeGenerator;
use Database\Seeders\HipocratiqUatAccessSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\FixedOtpCodeGenerator;
use Tests\TestCase;

class HipocratiqUatAccessSeederTest extends TestCase
{
    use RefreshDatabase;

    private string $password;

    protected function setUp(): void
    {
        parent::setUp();

        $this->password = Str::random(32);
        config([
            'uat.enabled' => true,
            'uat.password' => $this->password,
        ]);
        $this->app->instance(OtpCodeGenerator::class, new FixedOtpCodeGenerator);
    }

    public function test_uat_seeder_creates_exact_official_role_accounts_without_hardcoded_password(): void
    {
        $this->seed(HipocratiqUatAccessSeeder::class);

        $expected = [
            'superadmin@hipocratiq.com' => 'Superadministrador',
            'admin@hipocratiq.com' => 'Administrador',
            'recepcion@hipocratiq.com' => 'Recepcionista',
            'medico@hipocratiq.com' => 'Oscar Gutiérrez Rico',
            'medico.independiente@hipocratiq.com' => 'Médico Independiente',
            'paciente@hipocratiq.com' => 'Paciente Principal',
        ];

        foreach ($expected as $email => $name) {
            $user = User::query()->where('email', $email)->firstOrFail();
            $this->assertSame($name, $user->name);
            $this->assertSame('MX', $user->country_code);
            $this->assertTrue(Hash::check($this->password, (string) $user->password));
        }

        $clinicDoctor = User::query()->where('email', 'medico@hipocratiq.com')->firstOrFail();
        $independentDoctor = User::query()->where('email', 'medico.independiente@hipocratiq.com')->firstOrFail();

        $this->assertTrue($clinicDoctor->hasActiveDoctorAccess());
        $this->assertSame(DoctorPracticeType::Clinic, $clinicDoctor->doctorProfile?->practice_type);
        $this->assertTrue($independentDoctor->hasActiveDoctorAccess());
        $this->assertSame(DoctorPracticeType::Independent, $independentDoctor->doctorProfile?->practice_type);
        $this->assertSame('UAT-SYNTHETIC', $clinicDoctor->doctorProfile?->medical_license_issuer);
        $this->assertSame('unverified', $clinicDoctor->doctorProfile?->medical_license_verification_status);
    }

    #[DataProvider('webAccessCases')]
    public function test_each_official_uat_account_completes_the_expected_web_login_flow(
        string $email,
        string $requestedRole,
        string $actorContext,
        string $expectedContext,
        string $allowedPath,
        string $forbiddenPath,
        ?string $expectedPracticeType,
    ): void {
        $this->seed(HipocratiqUatAccessSeeder::class);

        $payload = [
            'purpose' => 'login',
            'client_type' => 'web',
            'actor_context' => $actorContext,
            'requested_role' => $requestedRole,
            'identifier' => $email,
        ];

        if ($actorContext === 'staff') {
            $payload['password'] = $this->password;
        }

        $challenge = $this->withSpaOrigin()
            ->postJson('/api/v1/auth/otp/request', $payload)
            ->assertAccepted()
            ->assertJsonPath('data.next_step', 'verify_otp');

        $verification = $this->withSpaOrigin()
            ->postJson('/api/v1/auth/otp/verify', [
                'purpose' => 'login',
                'client_type' => 'web',
                'challenge_id' => $challenge->json('data.challenge_id'),
                'code' => '123456',
            ])
            ->assertOk()
            ->assertJsonPath('data.actor_context', $expectedContext)
            ->assertJsonPath('data.user.email', $email);

        if ($expectedPracticeType !== null) {
            $verification->assertJsonPath('data.user.doctor.practice_type', $expectedPracticeType);
        }

        $cookie = $verification->getCookie(config('session.cookie'));
        $this->withCookie($cookie->getName(), $cookie->getValue());

        $this->withSpaOrigin()
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.actor_context', $expectedContext)
            ->assertJsonPath('data.user.email', $email);

        $this->withSpaOrigin()->getJson($allowedPath)->assertOk();
        $this->withSpaOrigin()->getJson($forbiddenPath)->assertForbidden();
    }

    #[DataProvider('mobileAccessCases')]
    public function test_each_supported_uat_account_completes_mobile_login_and_workspace_access(
        string $email,
        string $requestedRole,
        string $actorContext,
        string $expectedContext,
        string $allowedPath,
    ): void {
        $this->seed(HipocratiqUatAccessSeeder::class);

        $deviceName = 'HIPOCRATIQ UAT Device';
        $deviceId = 'hipocratiq-'.str_replace(['@', '.'], '-', $email).'-001';
        $payload = [
            'purpose' => 'login',
            'client_type' => 'mobile',
            'actor_context' => $actorContext,
            'requested_role' => $requestedRole,
            'identifier' => $email,
            'device_name' => $deviceName,
            'device_id' => $deviceId,
        ];

        if ($actorContext === 'staff') {
            $payload['password'] = $this->password;
        }

        $challenge = $this->postJson('/api/v1/auth/otp/request', $payload)
            ->assertAccepted();

        $verification = $this->postJson('/api/v1/auth/otp/verify', [
            'purpose' => 'login',
            'client_type' => 'mobile',
            'challenge_id' => $challenge->json('data.challenge_id'),
            'code' => '123456',
            'device_name' => $deviceName,
            'device_id' => $deviceId,
        ])
            ->assertOk()
            ->assertJsonPath('data.actor_context', $expectedContext)
            ->assertJsonPath('data.user.email', $email);

        $token = (string) $verification->json('data.access_token');

        $this->withMobileToken($token, $deviceName, $deviceId)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.actor_context', $expectedContext);

        $this->withMobileToken($token, $deviceName, $deviceId)
            ->getJson($allowedPath)
            ->assertOk();
    }

    public function test_uat_seeder_refuses_to_run_when_explicit_access_flag_is_disabled(): void
    {
        config(['uat.enabled' => false]);

        $this->expectException(RuntimeException::class);
        $this->seed(HipocratiqUatAccessSeeder::class);
    }

    /** @return array<string, array{string, string, string, string, string, string, string|null}> */
    public static function webAccessCases(): array
    {
        return [
            'super admin' => ['superadmin@hipocratiq.com', 'super_admin', 'staff', 'staff', '/api/v1/auth/admin/dashboard', '/api/v1/auth/doctor/dashboard', null],
            'clinic admin' => ['admin@hipocratiq.com', 'clinic_admin', 'staff', 'clinic_admin', '/api/v1/auth/clinic/dashboard', '/api/v1/auth/admin/dashboard', null],
            'receptionist' => ['recepcion@hipocratiq.com', 'receptionist', 'staff', 'receptionist', '/api/v1/auth/clinic/dashboard', '/api/v1/auth/clinic/epidemiology', null],
            'clinic doctor' => ['medico@hipocratiq.com', 'doctor', 'staff', 'doctor', '/api/v1/auth/doctor/dashboard', '/api/v1/auth/clinic/dashboard', 'clinic'],
            'independent doctor' => ['medico.independiente@hipocratiq.com', 'doctor', 'staff', 'doctor', '/api/v1/auth/doctor/dashboard', '/api/v1/auth/clinic/dashboard', 'independent'],
            'patient' => ['paciente@hipocratiq.com', 'patient', 'patient', 'patient', '/api/v1/auth/patient/profile', '/api/v1/auth/doctor/dashboard', null],
        ];
    }

    /** @return array<string, array{string, string, string, string, string}> */
    public static function mobileAccessCases(): array
    {
        return [
            'clinic admin' => ['admin@hipocratiq.com', 'clinic_admin', 'staff', 'clinic_admin', '/api/v1/auth/clinic/dashboard'],
            'receptionist' => ['recepcion@hipocratiq.com', 'receptionist', 'staff', 'receptionist', '/api/v1/auth/clinic/dashboard'],
            'clinic doctor' => ['medico@hipocratiq.com', 'doctor', 'staff', 'doctor', '/api/v1/auth/doctor/dashboard'],
            'independent doctor' => ['medico.independiente@hipocratiq.com', 'doctor', 'staff', 'doctor', '/api/v1/auth/doctor/dashboard'],
            'patient' => ['paciente@hipocratiq.com', 'patient', 'patient', 'patient', '/api/v1/auth/patient/profile'],
        ];
    }

    private function withSpaOrigin(): static
    {
        return $this->withCredentials()->withHeader('Origin', 'http://localhost:3000');
    }

    private function withMobileToken(string $token, string $deviceName, string $deviceId): static
    {
        return $this->withToken($token)->withHeaders([
            'X-Client-Type' => 'mobile',
            'X-Device-Name' => $deviceName,
            'X-Device-ID' => $deviceId,
        ]);
    }
}
