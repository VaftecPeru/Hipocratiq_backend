<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_liveness_reports_process_health_without_external_dependencies(): void
    {
        $this->getJson('/api/health/live')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'ok');
    }

    public function test_readiness_checks_database_and_storage_when_redis_is_not_required(): void
    {
        Storage::fake('health-test');

        config([
            'cache.default' => 'array',
            'cache.limiter' => 'array',
            'session.driver' => 'array',
            'queue.default' => 'sync',
            'filesystems.default' => 'health-test',
            'admin_operations.backups.enabled' => false,
        ]);

        $this->getJson('/api/health/ready')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('data.dependencies.database', 'ok')
            ->assertJsonPath('data.dependencies.redis', 'not_required')
            ->assertJsonPath('data.dependencies.storage', 'ok');

        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('data.dependencies.database', 'ok');
    }
}
