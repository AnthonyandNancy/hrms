<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Platform\Models\AuditEvent;
use App\Shared\Authorization\CapabilityGate;
use App\Shared\RequestContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class CapabilityGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_unbound_capability_gate_fails_closed(): void
    {
        $this->getJson('/v1/platform/features')
            ->assertForbidden()
            ->assertJsonPath('code', 'FORBIDDEN')
            ->assertJsonPath('data.capability', 'platform.feature.manage');
    }

    public function test_client_headers_cannot_assert_capability(): void
    {
        $this->withHeaders([
            'X-Capabilities' => 'platform.feature.manage',
            'X-Tenant-Id' => '1',
        ])->getJson('/v1/platform/features')->assertForbidden();
    }

    public function test_gate_denial_is_audited_via_structured_log_not_success_audit(): void
    {
        $this->getJson('/v1/platform/features')->assertForbidden();

        $this->assertSame(0, AuditEvent::query()->count());
    }

    public function test_capability_middleware_denies_unknown_principal_and_allows_bound_gate(): void
    {
        $this->app->instance(CapabilityGate::class, new class implements CapabilityGate
        {
            public function allows(Request $request, string $capability): bool
            {
                return $capability === 'platform.feature.manage'
                    && RequestContext::userId() === 7;
            }
        });

        app(RequestContext::class)->setActor(1, 7);

        $this->getJson('/v1/platform/features')
            ->assertOk()
            ->assertJsonPath('code', 'OK');
    }
}
