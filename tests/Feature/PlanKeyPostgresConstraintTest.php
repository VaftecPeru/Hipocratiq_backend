<?php

namespace Tests\Feature;

use App\Models\Plan;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PlanKeyPostgresConstraintTest extends TestCase
{
    use RefreshDatabase;

    public function test_postgres_allows_custom_plan_keys_from_the_saas_catalog(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL-specific plan key constraint regression.');
        }

        foreach (['startup', 'mexico', 'legacy'] as $key) {
            Plan::factory()->create(['key' => $key]);
            $this->assertDatabaseHas('plans', ['key' => $key]);
        }

        $plan = Plan::query()->where('key', 'startup')->firstOrFail();
        $plan->update(['key' => 'premium_mx-2']);

        $this->assertDatabaseHas('plans', ['key' => 'premium_mx-2']);
        $this->assertDatabaseMissing('plans', ['key' => 'startup']);
    }

    public function test_postgres_rejects_invalid_plan_slugs_at_database_boundary(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL-specific plan key constraint regression.');
        }

        $this->expectException(QueryException::class);

        // Bypasses FormRequest to verify the database still protects integrity.
        Plan::factory()->create(['key' => 'Invalid Plan!']);
    }
}
