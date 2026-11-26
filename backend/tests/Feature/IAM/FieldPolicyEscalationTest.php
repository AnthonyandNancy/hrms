<?php

declare(strict_types=1);

namespace Tests\Feature\IAM;

use App\Modules\IAM\Models\AuthorityBinding;
use App\Modules\IAM\Models\User;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\FieldProfile;
use App\Shared\Authorization\ResourceRef;
use Tests\Feature\Authorization\AuthorizationTestSupport;
use Tests\Support\AuthenticatesRequests;

final class FieldPolicyEscalationTest extends AuthorizationTestSupport
{
    use AuthenticatesRequests;

    public function test_p3_field_policy_can_only_be_tightened_never_widened(): void
    {
        $actor = $this->fieldManager();
        $this->enableMfa($actor);
        $auth = $this->loginAs($actor);
        $this->stepUp();

        $this->getJson('/v1/system/field-policies', ['X-Legal-Entity-Id' => '1'])
            ->assertOk()
            ->assertJsonFragment(['field_key' => 'employee.salary', 'profile' => 'P3_RESTRICTED']);

        $this->putJson('/v1/system/field-policies/employee.salary', [
            'field_profile' => 'P1_INTERNAL',
        ], ['Idempotency-Key' => 'field-widen', 'X-CSRF-Token' => $auth['csrf'], 'X-Legal-Entity-Id' => '1'])
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'FIELD_POLICY_WIDENING_REJECTED');

        $this->putJson('/v1/system/field-policies/employee.salary', [
            'field_profile' => 'P3_RESTRICTED',
        ], ['Idempotency-Key' => 'field-tighten', 'X-CSRF-Token' => $auth['csrf'], 'X-Legal-Entity-Id' => '1'])
            ->assertOk()
            ->assertJsonPath('data.profile', 'P3_RESTRICTED');

        $this->putJson('/v1/system/field-policies/employee.not_registered', [
            'field_profile' => 'P3_RESTRICTED',
        ], ['Idempotency-Key' => 'field-unknown', 'X-CSRF-Token' => $auth['csrf'], 'X-Legal-Entity-Id' => '1'])
            ->assertUnprocessable()
            ->assertJsonPath('data.reason', 'UNKNOWN_FIELD_POLICY');
    }

    public function test_p3_field_projection_still_writes_sensitive_access_audit(): void
    {
        $user = User::factory()->create(['tenant_id' => 1, 'auth_version' => 1]);
        $this->people->employmentByUser[(int) $user->id] = [1];
        $this->organization->knownLegalEntities = [1];
        AuthorityBinding::factory()
            ->forPrincipal(1, (int) $user->id, 1)
            ->anchor(AnchorType::COMPANY, null, FieldProfile::P3_RESTRICTED)
            ->create([
                'capability_code' => 'people.employee.read',
                'valid_from' => '2025-01-01 00:00:00',
                'valid_to' => '2030-01-01 00:00:00',
            ]);

        $principal = $this->actingAsUser($user, 1, true);

        app(\App\Modules\IAM\Auth\CapabilityAuthorizer::class)->fields(
            $principal,
            'people.employee.read',
            new ResourceRef('employee', 99, ['salary' => 1000000], tenantId: 1, employmentId: 1, legalEntityId: 1),
        );

        $this->assertNotEmpty($this->sensitiveAuditor->records);
        $this->assertContains('salary', $this->sensitiveAuditor->records[0]['fields']);
    }

    private function fieldManager(): User
    {
        $user = User::factory()->create(['tenant_id' => 1, 'auth_version' => 1]);
        $this->people->employmentByUser[(int) $user->id] = [1];
        $this->organization->knownLegalEntities = [1];

        foreach (['iam.field.manage' => FieldProfile::P2_SENSITIVE] as $capability => $profile) {
            AuthorityBinding::factory()
                ->forPrincipal(1, (int) $user->id, 1)
                ->anchor(AnchorType::COMPANY, null, $profile)
                ->create([
                    'capability_code' => $capability,
                    'valid_from' => '2025-01-01 00:00:00',
                    'valid_to' => '2030-01-01 00:00:00',
                ]);
        }

        return $user;
    }
}
