<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Platform\Services\HealthService;
use Mockery;
use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_health_is_public_and_reports_all_checks(): void
    {
        $response = $this->getJson('/v1/health');

        $response->assertOk()
            ->assertJsonPath('code', 'OK')
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('data.checks.db', true)
            ->assertJsonStructure([
                'code',
                'message',
                'data' => ['status', 'version', 'checks' => ['db', 'cache', 'storage'], 'time'],
                'request_id',
            ]);
        $response->assertHeader('X-Request-Id');

        $this->assertStringEndsWith('Z', $response->json('data.time'));
    }

    public function test_cache_or_storage_degradation_is_reported_as_degraded_with_http_200(): void
    {
        $health = Mockery::mock(HealthService::class);
        $health->shouldReceive('databaseHealthy')->once()->andReturnTrue();
        $health->shouldReceive('cacheHealthy')->once()->andReturnFalse();
        $health->shouldReceive('storageHealthy')->once()->andReturnTrue();
        $health->shouldReceive('version')->once()->andReturn('1.0.0-reference');
        $this->app->instance(HealthService::class, $health);

        $response = $this->getJson('/v1/health');

        $response->assertOk()
            ->assertJsonPath('data.status', 'degraded')
            ->assertJsonPath('data.checks.db', true)
            ->assertJsonPath('data.checks.cache', false)
            ->assertJsonPath('data.checks.storage', true);
    }

    public function test_database_failure_returns_service_unavailable_envelope(): void
    {
        $health = Mockery::mock(HealthService::class);
        $health->shouldReceive('databaseHealthy')->once()->andReturnFalse();
        $health->shouldReceive('cacheHealthy')->once()->andReturnTrue();
        $health->shouldReceive('storageHealthy')->once()->andReturnTrue();
        $health->shouldReceive('version')->once()->andReturn('1.0.0-reference');
        $this->app->instance(HealthService::class, $health);

        $response = $this->getJson('/v1/health');

        $response->assertStatus(503)
            ->assertJsonPath('code', 'SERVICE_UNAVAILABLE')
            ->assertJsonPath('data.status', 'unavailable')
            ->assertJsonPath('data.checks.db', false)
            ->assertJsonStructure(['code', 'message', 'data', 'request_id']);
    }
}
