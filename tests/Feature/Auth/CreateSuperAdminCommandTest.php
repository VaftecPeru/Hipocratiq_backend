<?php

namespace Tests\Feature\Auth;

use App\Enums\OnboardingStatus;
use App\Enums\RoleKey;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateSuperAdminCommandTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_command_creates_verified_global_super_admin(): void
    {
        $this->artisan('app:create-super-admin', [
            'email' => 'admin@hipocratiq.com',
            'phone' => '+51900112844',
            '--name' => 'Administrador Principal',
        ])
            ->expectsQuestion('Contraseña', 'SecureAdmin123')
            ->expectsQuestion('Confirmar contraseña', 'SecureAdmin123')
            ->expectsOutput('Super Admin creado: admin@hipocratiq.com')
            ->assertSuccessful();

        $user = User::query()->sole();

        $this->assertSame('Administrador Principal', $user->name);
        $this->assertSame('+51900112844', $user->phone_number);
        $this->assertSame('PE', $user->country_code);
        $this->assertSame(UserStatus::Active, $user->status);
        $this->assertSame(OnboardingStatus::Completed, $user->onboarding_status);
        $this->assertNotNull($user->email_verified_at);
        $this->assertNotNull($user->phone_verified_at);
        $this->assertTrue(Hash::check('SecureAdmin123', $user->password));
        $this->assertTrue($user->hasRole(RoleKey::SuperAdmin));
    }

    public function test_command_rejects_weak_password(): void
    {
        $this->artisan('app:create-super-admin', [
            'email' => 'admin@hipocratiq.com',
            'phone' => '+51900112844',
        ])
            ->expectsQuestion('Contraseña', 'weak')
            ->expectsQuestion('Confirmar contraseña', 'weak')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_command_rejects_duplicate_super_admin_identity(): void
    {
        User::factory()->superAdmin()->create([
            'email' => 'admin@hipocratiq.com',
            'phone_number' => '+51900112844',
        ]);

        $this->artisan('app:create-super-admin', [
            'email' => 'admin@hipocratiq.com',
            'phone' => '+51900112844',
        ])
            ->expectsQuestion('Contraseña', 'SecureAdmin123')
            ->expectsQuestion('Confirmar contraseña', 'SecureAdmin123')
            ->assertFailed();

        $this->assertDatabaseCount('users', 1);
    }
}
