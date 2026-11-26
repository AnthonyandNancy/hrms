<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\IAM\Models\AuthorityBinding;
use App\Modules\IAM\Models\User;
use App\Modules\Platform\Models\AuditEvent;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\FieldProfile;
use App\Shared\RequestContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestPrincipal;
use Tests\TestCase;

final class CapabilityGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_unbound_capability_gate_fails_closed(): void
    {
        $this->getJson('/v1/platform/features')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    public function test_client_headers_cannot_assert_capability(): void
    {
        $this->withHeaders([
            'X-Capabilities' => 'platform.feature.manage',
            'X-Tenant-Id' => '1',
        ])->getJson('/v1/platform/features')->assertUnauthorized();
    }

    public function test_gate_denial_is_audited_via_structured_log_not_success_audit(): void
    {
        $this->getJson('/v1/platform/features')->assertUnauthorized();

        $this->assertSame(0, AuditEvent::query()->count());
    }

    public function test_capability_middleware_denies_unknown_principal_and_allows_bound_gate(): void
    {
        $user = User::factory()->create();
        $principal = new TestPrincipal((int) $user->id);

        app(RequestContext::class)->setActor((int) $user->id);
        $people = app(\App\Shared\Authorization\Contracts\PersonEmploymentDirectory::class);
        $this->assertInstanceOf(\Tests\Fakes\FakePersonEmploymentDirectory::class, $people);
        $people->employmentByUser[(int) $user->id] = [1];
        $this->app->instance(\App\Modules\IAM\Contracts\Principal::class, $principal);
        $this->app->instance(\App\Shared\Auth\AuthenticatedPrincipal::class, $principal);
        $this->app->make(\App\Modules\IAM\Auth\AuthenticationContext::class)->set($principal);

        // No binding yet: the real engine denies.
        $this->getJson('/v1/platform/features')
            ->assertForbidden()
            ->assertJsonPath('data.reason', 'CAPABILITY_DENIED');

        AuthorityBinding::factory()
            ->forPrincipal((int) $user->id)
            ->anchor(AnchorType::SELF, null, FieldProfile::P0_PUBLIC)
            ->create(['capability_code' => 'platform.feature.manage']);

        // Production authority changes bump auth_version, which invalidates
        // the binding cache; mirror that invariant here.
        $user->forceFill(['auth_version' => 2])->save();
        $principal = new TestPrincipal((int) $user->id, null, 2);
        $this->app->instance(\App\Modules\IAM\Contracts\Principal::class, $principal);
        $this->app->instance(\App\Shared\Auth\AuthenticatedPrincipal::class, $principal);
        $this->app->make(\App\Modules\IAM\Auth\AuthenticationContext::class)->set($principal);

        AuthorityBinding::query()->update(['auth_version' => 2]);

        $this->getJson('/v1/platform/features')
            ->assertOk()
            ->assertJsonPath('code', 'OK');
    }
}
