<?php

namespace Tests\Feature;

use App\Enums\RoleKey;
use App\Models\Clinic;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\Auth\OtpCodeGenerator;
use Database\Seeders\DemoUsersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FixedOtpCodeGenerator;
use Tests\TestCase;

class DemoUsersSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The demo seeder must reseed cleanly even when the demo clinic already
     * has support tickets, which restrict clinic deletion.
     */
    public function test_seeder_purges_demo_clinic_with_support_tickets(): void
    {
        $this->seed(DemoUsersSeeder::class);

        $demoClinic = Clinic::query()->where('slug', 'clinica-demo-salud')->firstOrFail();

        SupportTicket::query()->create([
            'clinic_id' => $demoClinic->id,
            'category' => 'billing',
            'priority' => 'normal',
            'status' => 'open',
            'subject' => 'Demo ticket',
            'description' => 'Existing ticket before reseed.',
            'contact_email' => 'clinica@hipocratiq.test',
        ]);

        $this->seed(DemoUsersSeeder::class);

        $this->assertSame(1, Clinic::query()->where('slug', 'clinica-demo-salud')->count());
        $this->assertSame(0, SupportTicket::query()->count());
        $this->assertSame(1, User::query()->where('email', 'clinica@hipocratiq.test')->count());
    }

    public function test_seeder_creates_an_active_receptionist_that_can_login(): void
    {
        $this->app->instance(OtpCodeGenerator::class, new FixedOtpCodeGenerator);
        $this->seed(DemoUsersSeeder::class);

        $receptionist = User::query()->where('email', 'recepcion@hipocratiq.test')->firstOrFail();

        $this->assertTrue($receptionist->hasActiveReceptionistAccess());
        $this->assertTrue($receptionist->hasRole(RoleKey::Receptionist));
        $this->assertDatabaseHas('clinic_memberships', [
            'user_id' => $receptionist->id,
            'status' => 'active',
        ]);

        $login = $this->withSpaOrigin()->postJson('/api/v1/auth/otp/request', [
            'purpose' => 'login',
            'client_type' => 'web',
            'actor_context' => 'staff',
            'requested_role' => 'receptionist',
            'identifier' => 'recepcion@hipocratiq.test',
            'password' => 'Hipocratiq2026!',
        ])->assertAccepted();

        $this->withSpaOrigin()->postJson('/api/v1/auth/otp/verify', [
            'purpose' => 'login',
            'challenge_id' => $login->json('data.challenge_id'),
            'code' => '123456',
        ])->assertOk()->assertJsonPath('data.actor_context', 'receptionist');
    }

    private function withSpaOrigin(): static
    {
        return $this->withCredentials()->withHeader('Origin', 'http://localhost:3000');
    }
}
