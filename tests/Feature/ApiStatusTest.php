<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApiStatusTest extends TestCase
{
    public function test_api_root_returns_service_metadata(): void
    {
        $response = $this->getJson('/api');

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', config('app.name'))
            ->assertJsonPath('data.status', 'ready');
    }

    public function test_api_health_returns_ok_status(): void
    {
        $response = $this->getJson('/api/health');

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonStructure([
                'data' => [
                    'checked_at',
                ],
            ]);
    }

    public function test_missing_api_endpoint_returns_json_not_found_response(): void
    {
        $response = $this->getJson('/api/missing-endpoint');

        $response
            ->assertNotFound()
            ->assertJson([
                'success' => false,
                'message' => 'API endpoint not found.',
            ]);
    }

    public function test_web_root_is_not_registered(): void
    {
        $response = $this->getJson('/');

        $response->assertNotFound();
    }
}
