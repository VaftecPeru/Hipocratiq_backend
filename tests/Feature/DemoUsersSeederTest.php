<?php

namespace Tests\Feature;

use App\Models\Clinic;
use App\Models\SupportTicket;
use App\Models\User;
use Database\Seeders\DemoUsersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
