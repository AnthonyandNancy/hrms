<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Modules\IAM\Models\AuthorityBinding;
use App\Modules\IAM\Models\User;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\FieldProfile;
use App\Shared\Authorization\SodRules;
use App\Shared\Time\Clock;
use App\Shared\Time\FrozenClock;
use Illuminate\Support\Facades\DB;

final class SodRiskAcknowledgementTest extends AuthorizationTestSupport
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = new FrozenClock('2026-11-24T09:00:00Z');
        $this->app->instance(Clock::class, $this->clock);
    }

    public function test_an_unacknowledged_cumulative_risk_on_a_direct_binding_is_rejected(): void
    {
        $this->artisan('iam:sync-capabilities')->assertSuccessful();
        $this->systemAdmin();
        $target = User::factory()->create(['auth_version' => 1]);
        $this->grant($target, 'iam.binding.manage');

        $this->postJson("/v1/users/{$target->id}/bindings", $this->bindingPayload('iam.audit.read', 'AUDIT_READ'))
            ->assertStatus(422)
            ->assertJsonPath('data.reason', 'SOD_RISK_NOT_ACKNOWLEDGED')
            ->assertJsonPath('data.risks.0.kind', SodRules::KIND_CUMULATIVE)
            ->assertJsonPath('data.risks.0.pair', ['iam.audit.read', 'iam.binding.manage']);

        $this->assertSame(0, AuthorityBinding::query()
            ->where('principal_id', $target->id)
            ->where('capability_code', 'iam.audit.read')
            ->count());
    }

    public function test_an_acknowledged_risk_on_a_direct_binding_returns_warnings_and_is_audited(): void
    {
        $this->artisan('iam:sync-capabilities')->assertSuccessful();
        $actor = $this->systemAdmin();
        $target = User::factory()->create(['auth_version' => 1]);
        $this->grant($target, 'iam.binding.manage');

        $risks = SodRules::risks(['iam.binding.manage', 'iam.audit.read']);

        $response = $this->postJson("/v1/users/{$target->id}/bindings", [
            ...$this->bindingPayload('iam.audit.read', 'AUDIT_READ'),
            'acknowledge_sod_risk' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('data.capability', 'iam.audit.read')
            ->assertJsonPath('data.warnings.0.kind', SodRules::KIND_CUMULATIVE);

        $this->assertSame($risks, $response->json('data.warnings'));

        $metadata = $this->persistedSodMetadata((int) $actor->id);

        $this->assertSame((int) $target->id, $metadata['target_user_id']);
        $this->assertSame('iam.audit.read', $metadata['capability']);
        $this->assertSame('COMPANY', $metadata['anchor_type']);
        $this->assertNull($metadata['anchor_id']);
        $this->assertSame($risks, $metadata['risks']);
    }

    public function test_the_api_refuses_to_grant_the_wildcard_directly(): void
    {
        $this->artisan('iam:sync-capabilities')->assertSuccessful();
        $this->systemAdmin();
        $target = User::factory()->create(['auth_version' => 1]);

        $this->postJson("/v1/users/{$target->id}/bindings", [
            ...$this->bindingPayload('*', 'ESCALATION_ATTEMPT'),
            'field_profile' => 'P3_RESTRICTED',
            'acknowledge_sod_risk' => true,
        ])
            ->assertStatus(422)
            ->assertJsonPath('data.reason', 'WILDCARD_NOT_GRANTABLE');

        $this->assertSame(0, AuthorityBinding::query()
            ->where('capability_code', '*')
            ->where('principal_id', $target->id)
            ->count());
    }

    public function test_a_cumulative_risk_on_role_assignment_requires_acknowledgement(): void
    {
        $this->artisan('iam:sync-capabilities')->assertSuccessful();
        $this->systemAdmin();
        $target = User::factory()->create(['auth_version' => 1]);
        $this->grant($target, 'iam.binding.manage');

        $this->postJson("/v1/users/{$target->id}/roles", [
            'role_code' => 'AUDITOR',
            'reason' => 'Independent audit coverage.',
        ])
            ->assertStatus(422)
            ->assertJsonPath('data.reason', 'SOD_RISK_NOT_ACKNOWLEDGED');

        $this->assertDatabaseMissing('user_roles', ['user_id' => $target->id, 'role_code' => 'AUDITOR']);
    }

    public function test_an_acknowledged_role_assignment_returns_warnings_and_is_audited(): void
    {
        $this->artisan('iam:sync-capabilities')->assertSuccessful();
        $actor = $this->systemAdmin();
        $target = User::factory()->create(['auth_version' => 1]);
        $this->grant($target, 'iam.binding.manage');

        $risks = SodRules::risks(['iam.binding.manage', 'iam.audit.read']);

        $response = $this->postJson("/v1/users/{$target->id}/roles", [
            'role_code' => 'AUDITOR',
            'reason' => 'Independent audit coverage.',
            'acknowledge_sod_risk' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('data.role_code', 'AUDITOR')
            ->assertJsonPath('data.warnings.0.kind', SodRules::KIND_CUMULATIVE);

        $this->assertSame($risks, $response->json('data.warnings'));

        $metadata = $this->persistedSodMetadata((int) $actor->id);

        $this->assertSame((int) $target->id, $metadata['target_user_id']);
        $this->assertSame('AUDITOR', $metadata['role_code']);
        $this->assertSame($risks, $metadata['risks']);
    }

    public function test_the_role_assignment_path_does_not_accept_a_client_supplied_capability(): void
    {
        $this->artisan('iam:sync-capabilities')->assertSuccessful();
        $this->systemAdmin();
        $target = User::factory()->create(['auth_version' => 1]);

        $this->postJson("/v1/users/{$target->id}/roles", [
            'role_code' => '*',
            'capability_code' => '*',
            'capabilities' => [['capability_code' => '*']],
            'reason' => 'Attempted privilege escalation.',
            'acknowledge_sod_risk' => true,
        ])
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['fields' => ['role_code']]]);

        $this->assertSame(0, AuthorityBinding::query()
            ->where('capability_code', '*')
            ->where('principal_id', $target->id)
            ->count());
        $this->assertDatabaseCount('user_roles', 0);

        // A valid template still ignores the injected capability payload:
        // only the template's own grants may be written.
        $this->postJson("/v1/users/{$target->id}/roles", [
            'role_code' => 'EMPLOYEE',
            'capability_code' => '*',
            'capabilities' => [['capability_code' => '*']],
            'reason' => 'Self-service access.',
        ])->assertCreated();

        $this->assertSame(0, AuthorityBinding::query()
            ->where('capability_code', '*')
            ->where('principal_id', $target->id)
            ->count());
        $this->assertGreaterThan(0, AuthorityBinding::query()
            ->where('principal_id', $target->id)
            ->where('source_type', 'ROLE')
            ->count());
    }

    /**
     * Read the JSON column exactly as persisted, proving the security-event
     * allowlist really carries the risks and the binding/role context.
     *
     * @return array<string, mixed>
     */
    private function persistedSodMetadata(int $actorId): array
    {
        $raw = DB::table('auth_events')
            ->where('event_type', 'security.sod_risk_accepted')
            ->where('user_id', $actorId)
            ->value('metadata_json');

        $this->assertIsString($raw);
        $metadata = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($metadata);

        return $metadata;
    }

    /**
     * @return array<string, mixed>
     */
    private function bindingPayload(string $capability, string $purpose): array
    {
        return [
            'capability_code' => $capability,
            'anchor_type' => 'COMPANY',
            'field_profile' => 'P2_SENSITIVE',
            'purpose_code' => $purpose,
        ];
    }

    private function grant(User $user, string $capability): void
    {
        AuthorityBinding::factory()
            ->forPrincipal(1, (int) $user->id, (int) $user->auth_version)
            ->anchor(AnchorType::COMPANY, null, FieldProfile::P1_INTERNAL)
            ->create([
                'capability_code' => $capability,
                'valid_from' => '2025-01-01 00:00:00',
                'valid_to' => '2030-01-01 00:00:00',
            ]);
    }
}
