<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Platform\Features\FeatureFlagService;
use App\Modules\Platform\Models\AuditEvent;
use App\Shared\RequestContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class FeatureFlagTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['api', 'feature:payroll_enabled'])->prefix('v1')->group(function (): void {
            Route::get('/test-payroll-gate', fn (): array => ['ok' => true]);
        });
    }

    public function test_risky_domains_default_off_and_return_404_with_stable_code(): void
    {
        $response = $this->getJson('/v1/test-payroll-gate');

        $response->assertNotFound()
            ->assertJsonPath('code', 'NOT_FOUND')
            ->assertJsonPath('data.feature', 'payroll_enabled');
        $this->assertDatabaseHas('audit_events', [
            'action' => 'feature_flag.blocked',
            'aggregate_id' => 'payroll_enabled',
        ]);
    }

    public function test_enabling_flag_allows_route(): void
    {
        app(FeatureFlagService::class)->set('payroll_enabled', true, null, 'test enable');
        app(RequestContext::class)->setActor(1, null);

        $this->getJson('/v1/test-payroll-gate')
            ->assertOk()
            ->assertJsonPath('ok', true);
    }

    public function test_flag_change_is_audited_and_idempotent_override(): void
    {
        $flags = app(FeatureFlagService::class);
        $flags->set('payroll_enabled', true, 7, 'cutover wave three approved');

        $this->assertTrue($flags->enabled('payroll_enabled'));
        $this->assertFalse($flags->enabled('time_enabled'));
        $this->assertDatabaseHas('audit_events', [
            'action' => 'feature_flag.changed',
            'aggregate_id' => 'payroll_enabled',
            'actor_user_id' => 7,
        ]);

        $flags->set('payroll_enabled', true, 7, 'repeat idempotent call');
        $this->assertSame(1, AuditEvent::query()->where('action', 'feature_flag.changed')->count());
    }

    public function test_unknown_flag_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(FeatureFlagService::class)->enabled('not_a_flag');
    }

    public function test_flag_route_requires_capability_metadata(): void
    {
        $route = Route::getRoutes()->getByName('platform.features.update');
        $this->assertNotNull($route);
        $this->assertContains('capability:platform.feature.manage', $route->gatherMiddleware());
    }
}
