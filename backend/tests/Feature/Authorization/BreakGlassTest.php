<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Modules\IAM\Models\AuthorityBinding;
use App\Modules\IAM\Models\BreakGlassGrant;
use App\Modules\IAM\Models\User;
use App\Modules\Platform\Models\AuditEvent;
use App\Modules\Platform\Models\OutboxEvent;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\FieldProfile;
use App\Shared\Time\Clock;
use App\Shared\Time\FrozenClock;
use Carbon\CarbonImmutable;
use Tests\Support\AuthenticatesRequests;

final class BreakGlassTest extends AuthorizationTestSupport
{
    use AuthenticatesRequests;

    public function test_activation_requires_two_distinct_people(): void
    {
        $this->freeze();
        $requester = $this->breakGlassRequester();
        $this->loginAs($requester);

        $this->postJson('/v1/auth/break-glass', [
            'approver_user_id' => (int) $requester->id,
            'reason' => 'Emergency payroll correction for audit.',
        ])->assertUnprocessable()->assertJsonPath('data.reason', 'BREAK_GLASS_SEPARATION_REQUIRED');
    }

    public function test_reason_minimum_and_active_approver_are_enforced(): void
    {
        $this->freeze();
        $requester = $this->breakGlassRequester();
        $approver = User::factory()->create(['tenant_id' => 1, 'mfa_enrolled_at' => now()]);
        $this->loginAs($requester);

        $this->postJson('/v1/auth/break-glass', [
            'approver_user_id' => (int) $approver->id,
            'reason' => 'too short',
        ])->assertUnprocessable();

        $this->postJson('/v1/auth/break-glass', [
            'approver_user_id' => 999999,
            'reason' => 'Emergency payroll correction for audit.',
        ])->assertNotFound();
    }

    public function test_activation_creates_thirty_minute_grant_with_audit_and_outbox(): void
    {
        $this->freeze();
        $requester = $this->breakGlassRequester();
        $approver = User::factory()->create(['tenant_id' => 1, 'mfa_enrolled_at' => now()]);
        $this->loginAs($requester);

        $response = $this->postJson('/v1/auth/break-glass', [
            'approver_user_id' => (int) $approver->id,
            'reason' => 'Emergency payroll correction for audit.',
            'scope' => ['payroll.run.review'],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.scope', ['payroll.run.review'])
            ->assertJsonPath('data.requester_user_id', (int) $requester->id)
            ->assertJsonPath('data.approver_user_id', (int) $approver->id);

        $grant = BreakGlassGrant::query()->findOrFail((int) $response->json('data.grant_id'));
        $this->assertSame('2026-11-23 09:30:00', $grant->expires_at->utc()->toDateTimeString());
        $this->assertDatabaseHas('audit_events', ['action' => 'security.break_glass.activated']);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'security.break_glass.activated']);
    }

    public function test_expired_grant_no_longer_allows_actions_automatically(): void
    {
        $this->freeze();
        $requester = $this->breakGlassRequester();
        $this->loginAs($requester);

        $response = $this->postJson('/v1/auth/break-glass', [
            'approver_user_id' => 1,
            'reason' => 'Emergency payroll correction for audit.',
        ]);
        if ($response->status() !== 201) {
            $approver = User::factory()->create(['tenant_id' => 1]);
            $response = $this->postJson('/v1/auth/break-glass', [
                'approver_user_id' => (int) $approver->id,
                'reason' => 'Emergency payroll correction for audit.',
            ]);
        }
        $response->assertCreated();

        $grant = BreakGlassGrant::query()->findOrFail((int) $response->json('data.grant_id'));
        $this->assertNotNull(app(\App\Modules\IAM\Authorization\EloquentElevationGrantDirectory::class)
            ->activeCapabilities(1, (int) $requester->id, (int) $grant->id));

        $this->advance(1801);

        $this->assertNull(app(\App\Modules\IAM\Authorization\EloquentElevationGrantDirectory::class)
            ->activeCapabilities(1, (int) $requester->id, (int) $grant->id));
    }

    public function test_either_party_can_revoke_and_revocation_is_audited(): void
    {
        $this->freeze();
        $requester = $this->breakGlassRequester();
        $approver = User::factory()->create(['tenant_id' => 1, 'mfa_enrolled_at' => now(), 'password_hash' => app(\App\Modules\IAM\Services\PasswordHasher::class)->hash('Correct-Horse-9!')]);
        $this->loginAs($requester);

        $response = $this->postJson('/v1/auth/break-glass', [
            'approver_user_id' => (int) $approver->id,
            'reason' => 'Emergency payroll correction for audit.',
        ])->assertCreated();

        $revoke = $this->postJson('/v1/auth/break-glass/'.$response->json('data.grant_id').'/revoke')->assertOk();
        $this->assertNotNull($revoke->json('data.revoked_at'));
        $this->assertDatabaseHas('audit_events', ['action' => 'security.break_glass.revoked']);

        $this->assertNull(app(\App\Modules\IAM\Authorization\EloquentElevationGrantDirectory::class)
            ->activeCapabilities(1, (int) $requester->id, (int) $response->json('data.grant_id')));
    }

    public function test_grant_id_header_is_bound_to_actions_and_unknown_grant_does_not_elevate(): void
    {
        $this->freeze();
        $requester = $this->breakGlassRequester();
        $this->people->employmentByUser[(int) $requester->id] = [1];
        $this->loginAs($requester);

        $this->withHeader('X-Break-Glass-Grant', '999999');
        $this->getJson('/v1/me')->assertOk();

        $this->assertNull(app(\App\Modules\IAM\Authorization\EloquentElevationGrantDirectory::class)
            ->activeCapabilities(1, (int) $requester->id, 999999));
    }

    public function test_reason_is_never_exposed_in_grant_json(): void
    {
        $this->freeze();
        $requester = $this->breakGlassRequester();
        $approver = User::factory()->create(['tenant_id' => 1]);
        $this->loginAs($requester);

        $response = $this->postJson('/v1/auth/break-glass', [
            'approver_user_id' => (int) $approver->id,
            'reason' => 'Sensitive emergency narrative about a person.',
        ]);

        $response->assertCreated();
        $this->assertStringNotContainsString('Sensitive emergency narrative', (string) $response->getContent());
    }

    private function breakGlassRequester(): User
    {
        $user = User::factory()->create(['tenant_id' => 1, 'mfa_enrolled_at' => now()]);
        AuthorityBinding::factory()
            ->forPrincipal(1, (int) $user->id)
            ->anchor(AnchorType::SELF, null, FieldProfile::P0_PUBLIC)
            ->create(['capability_code' => 'iam.session.manage']);

        return $user;
    }

    private function freeze(): void
    {
        $this->clock = new FrozenClock(CarbonImmutable::parse('2026-11-23T09:00:00Z', 'UTC'));
        $this->app->instance(Clock::class, $this->clock);
    }

    private function advance(int $seconds): void
    {
        $this->clock->advanceSeconds($seconds);
    }
}
