<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Platform\Contracts\InstallationStateStore;
use App\Modules\Platform\Services\HealthService;
use App\Shared\Health\ActiveAdministratorDirectory;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class HealthProbeTest extends TestCase
{
    public function test_liveness_is_ok_without_touching_any_dependency(): void
    {
        $health = Mockery::mock(HealthService::class);
        $health->shouldNotReceive('databaseHealthy');
        $health->shouldNotReceive('cacheHealthy');
        $health->shouldNotReceive('storageHealthy');
        $this->app->instance(HealthService::class, $health);

        $response = $this->getJson('/v1/health/live');

        $response->assertOk()
            ->assertJsonPath('code', 'OK')
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonStructure(['code', 'message', 'data' => ['status', 'version', 'time'], 'request_id']);
        $this->assertStringEndsWith('Z', $response->json('data.time'));
    }

    public function test_readiness_returns_503_when_database_is_down(): void
    {
        $health = Mockery::mock(HealthService::class);
        $health->shouldReceive('databaseHealthy')->once()->andReturnFalse();
        $health->shouldReceive('cacheHealthy')->once()->andReturnTrue();
        $health->shouldReceive('storageHealthy')->once()->andReturnTrue();
        $health->shouldReceive('migrationsHealthy')->once()->andReturnTrue();
        $health->shouldReceive('installationHealthy')->once()->andReturnTrue();
        $health->shouldReceive('administratorHealthy')->once()->andReturnTrue();
        $health->shouldReceive('version')->once()->andReturn('1.0.0');
        $this->app->instance(HealthService::class, $health);

        $this->getJson('/v1/health/ready')
            ->assertStatus(503)
            ->assertJsonPath('code', 'SERVICE_UNAVAILABLE')
            ->assertJsonPath('data.status', 'not_ready')
            ->assertJsonPath('data.checks.db', false);
    }

    public function test_readiness_returns_503_when_cache_or_storage_is_down(): void
    {
        $health = Mockery::mock(HealthService::class);
        $health->shouldReceive('databaseHealthy')->once()->andReturnTrue();
        $health->shouldReceive('cacheHealthy')->once()->andReturnFalse();
        $health->shouldReceive('storageHealthy')->once()->andReturnTrue();
        $health->shouldReceive('migrationsHealthy')->once()->andReturnTrue();
        $health->shouldReceive('installationHealthy')->once()->andReturnTrue();
        $health->shouldReceive('administratorHealthy')->once()->andReturnTrue();
        $health->shouldReceive('version')->once()->andReturn('1.0.0');
        $this->app->instance(HealthService::class, $health);

        $this->getJson('/v1/health/ready')
            ->assertStatus(503)
            ->assertJsonPath('code', 'SERVICE_UNAVAILABLE')
            ->assertJsonPath('data.status', 'not_ready')
            ->assertJsonPath('data.checks.cache', false);
    }

    public function test_readiness_requires_completed_install_and_one_active_administrator(): void
    {
        $health = Mockery::mock(HealthService::class);
        $health->shouldReceive('databaseHealthy')->once()->andReturnTrue();
        $health->shouldReceive('cacheHealthy')->once()->andReturnTrue();
        $health->shouldReceive('storageHealthy')->once()->andReturnTrue();
        $health->shouldReceive('migrationsHealthy')->once()->andReturnTrue();
        $health->shouldReceive('installationHealthy')->once()->andReturnFalse();
        $health->shouldReceive('administratorHealthy')->once()->andReturnTrue();
        $health->shouldReceive('version')->once()->andReturn('1.0.0');
        $this->app->instance(HealthService::class, $health);

        $this->getJson('/v1/health/ready')
            ->assertStatus(503)
            ->assertJsonPath('data.status', 'not_ready')
            ->assertJsonPath('data.checks.installation', false)
            ->assertJsonPath('data.checks.administrator', true);
    }

    public function test_readiness_is_ready_when_every_check_passes(): void
    {
        $health = Mockery::mock(HealthService::class);
        $health->shouldReceive('databaseHealthy')->once()->andReturnTrue();
        $health->shouldReceive('cacheHealthy')->once()->andReturnTrue();
        $health->shouldReceive('storageHealthy')->once()->andReturnTrue();
        $health->shouldReceive('migrationsHealthy')->once()->andReturnTrue();
        $health->shouldReceive('installationHealthy')->once()->andReturnTrue();
        $health->shouldReceive('administratorHealthy')->once()->andReturnTrue();
        $health->shouldReceive('version')->once()->andReturn('1.0.0');
        $this->app->instance(HealthService::class, $health);

        $this->getJson('/v1/health/ready')
            ->assertOk()
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.checks.migrations', true)
            ->assertJsonPath('data.checks.installation', true)
            ->assertJsonPath('data.checks.administrator', true);
    }

    public function test_administrator_readiness_accepts_one_or_more_active_administrators(): void
    {
        /** @var InstallationStateStore&MockInterface $store */
        $store = Mockery::mock(InstallationStateStore::class);
        /** @var ActiveAdministratorDirectory&MockInterface $admins */
        $admins = Mockery::mock(ActiveAdministratorDirectory::class);
        $admins->shouldReceive('count')->once()->andReturn(0);
        $service = new HealthService($store, $admins);
        $this->assertFalse($service->administratorHealthy());

        /** @var ActiveAdministratorDirectory&MockInterface $admins */
        $admins = Mockery::mock(ActiveAdministratorDirectory::class);
        $admins->shouldReceive('count')->once()->andReturn(2);
        $service = new HealthService($store, $admins);
        $this->assertTrue($service->administratorHealthy(), 'A second administrator is a normal production state, not unreadiness.');
    }

    public function test_legacy_health_remains_degraded_not_unavailable_for_cache_failure(): void
    {
        $health = Mockery::mock(HealthService::class);
        $health->shouldReceive('databaseHealthy')->once()->andReturnTrue();
        $health->shouldReceive('cacheHealthy')->once()->andReturnFalse();
        $health->shouldReceive('storageHealthy')->once()->andReturnTrue();
        $health->shouldReceive('version')->once()->andReturn('1.0.0');
        $this->app->instance(HealthService::class, $health);

        $this->getJson('/v1/health')
            ->assertOk()
            ->assertJsonPath('data.status', 'degraded');
    }
}
