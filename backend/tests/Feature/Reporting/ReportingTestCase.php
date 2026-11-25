<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Modules\IAM\Auth\AuthenticatedPrincipal;
use App\Modules\IAM\Auth\AuthenticationContext;
use App\Modules\IAM\Auth\CapabilityAuthorizer;
use App\Modules\IAM\Contracts\Principal;
use App\Modules\IAM\Http\Middleware\RequireCapability;
use App\Modules\IAM\Models\AuthorityBinding;
use App\Modules\IAM\Models\User;
use App\Modules\People\Models\Employment;
use App\Modules\Time\Models\LeaveLedgerEntry;
use App\Modules\Time\Models\LeaveType;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\Authorizer;
use App\Shared\Authorization\Contracts\OrganizationScopeDirectory;
use App\Shared\Authorization\Contracts\PersonEmploymentDirectory;
use App\Shared\Authorization\FieldProfile;
use Tests\Fakes\FakeOrganizationScopeDirectory;
use Tests\Fakes\FakePersonEmploymentDirectory;
use Tests\Support\PeopleScenario;
use Tests\Support\PeopleTestCase;

/**
 * Shared synthetic workforce and real authorization-engine wiring for the
 * reporting consistency tests. The route capability gate is the production
 * RequireCapability middleware; only the scoped directory ports are fakes, as
 * in the existing Authorization feature tests.
 */
abstract class ReportingTestCase extends PeopleTestCase
{
    protected const BUSINESS_DATE = '2026-11-23';

    protected FakeOrganizationScopeDirectory $organizationScope;

    protected FakePersonEmploymentDirectory $peopleScope;

    protected function setUp(): void
    {
        parent::setUp();

        // Exercise the production capability gate rather than the permissive
        // People test middleware. The engine is still the real default-deny
        // CapabilityAuthorizer backed by SQL authority bindings.
        $this->app['router']->aliasMiddleware('capability', RequireCapability::class);

        // The authorization engine reads only these two Shared contracts. Bind
        // explicit in-memory ports, exactly as the IAM authorization feature
        // suite does, and still run the real default-deny engine and bindings.
        $this->organizationScope = new FakeOrganizationScopeDirectory;
        $this->peopleScope = new FakePersonEmploymentDirectory;
        $this->app->instance(OrganizationScopeDirectory::class, $this->organizationScope);
        $this->app->instance(PersonEmploymentDirectory::class, $this->peopleScope);
        $this->app->instance(Authorizer::class, $this->app->make(CapabilityAuthorizer::class));
    }

    /**
     * Seed two synthetic org units and four employments. Org A owns two
     * employments, one contract expiry and one leave balance; Org B owns the
     * other two and a second contract/leave row.
     *
     * @return array{
     *     entity_id: int,
     *     org_a_id: int,
     *     org_b_id: int,
     *     employment_a1_id: int,
     *     employment_a2_id: int,
     *     employment_b1_id: int,
     *     employment_b2_id: int,
     *     broad_user: User,
     *     narrow_user: User,
     *     broad_principal: AuthenticatedPrincipal,
     *     narrow_principal: AuthenticatedPrincipal
     * }
     */
    protected function seedSyntheticWorkforce(): array
    {
        $scenario = $this->scenario();
        $entity = $scenario->legalEntity('SYNTH-LE', 'Synthetic HRMS Entity', 'Asia/Shanghai', 'CNY');
        $orgA = $scenario->orgUnit($entity, 'SYNTH-ORG-A', null, 'Synthetic Org A');
        $orgB = $scenario->orgUnit($entity, 'SYNTH-ORG-B', null, 'Synthetic Org B');

        $a1 = $this->employment($scenario, $orgA->id, 'SYNTH-A-001', 'Synthetic Alpha One');
        $a2 = $this->employment($scenario, $orgA->id, 'SYNTH-A-002', 'Synthetic Alpha Two');
        $b1 = $this->employment($scenario, $orgB->id, 'SYNTH-B-001', 'Synthetic Beta One');
        $b2 = $this->employment($scenario, $orgB->id, 'SYNTH-B-002', 'Synthetic Beta Two');

        $fileObjectId = $scenario->fileObject();
        $scenario->document($a1, $fileObjectId, ['expires_at' => '2026-12-05']);
        $scenario->document($b1, $fileObjectId, ['expires_at' => '2026-12-12']);

        $leaveType = LeaveType::query()->create([
            'tenant_id' => 1,
            'code' => 'SYNTH-ANNUAL',
            'name' => 'Synthetic annual leave',
            'is_statutory' => true,
            'is_annual' => true,
            'unit' => 'DAY',
            'annual_entitlement_days' => '10.00',
            'requires_attachment' => false,
            'allow_negative' => false,
            'expiry_rule_json' => null,
            'legal_basis' => 'Synthetic fixture only',
        ]);

        $this->leaveGrant($a2->id, (int) $leaveType->id, 600, 'SYNTH-GRANT-A2');
        $this->leaveGrant($b2->id, (int) $leaveType->id, 240, 'SYNTH-GRANT-B2');

        $this->organizationScope->knownLegalEntities = [(int) $entity->id];
        $this->organizationScope->legalEntityByUnit = [
            (int) $orgA->id => (int) $entity->id,
            (int) $orgB->id => (int) $entity->id,
        ];
        $this->organizationScope->unitsByLegalEntity = [
            (int) $entity->id => [(int) $orgA->id, (int) $orgB->id],
        ];
        $this->organizationScope->descendants = [
            (int) $orgA->id => [(int) $orgA->id],
            (int) $orgB->id => [(int) $orgB->id],
        ];
        $this->peopleScope->employmentByOrgUnit = [
            (int) $orgA->id => [(int) $a1->id, (int) $a2->id],
            (int) $orgB->id => [(int) $b1->id, (int) $b2->id],
        ];

        $broadUser = User::factory()->create(['tenant_id' => 1]);
        $narrowUser = User::factory()->create(['tenant_id' => 1]);

        $broadPrincipal = $this->authenticate((int) $broadUser->id, (int) $a1->id, (int) $broadUser->auth_version);
        $narrowPrincipal = $this->authenticate((int) $narrowUser->id, (int) $a2->id, (int) $narrowUser->auth_version);

        $this->grant($broadUser, 'reporting.dashboard.read', AnchorType::LEGAL_ENTITY, (int) $entity->id);
        $this->grant($broadUser, 'reporting.report.read', AnchorType::LEGAL_ENTITY, (int) $entity->id);
        $this->grant($broadUser, 'reporting.definition.manage', AnchorType::LEGAL_ENTITY, (int) $entity->id);
        $this->grant($broadUser, 'reporting.subscription.manage', AnchorType::LEGAL_ENTITY, (int) $entity->id);
        $this->grant($broadUser, 'reporting.export', AnchorType::LEGAL_ENTITY, (int) $entity->id);
        $this->grant($broadUser, 'reporting.export.read', AnchorType::LEGAL_ENTITY, (int) $entity->id);
        $this->grant($narrowUser, 'reporting.dashboard.read', AnchorType::ORG_UNIT, (int) $orgA->id);
        $this->grant($narrowUser, 'reporting.report.read', AnchorType::ORG_UNIT, (int) $orgA->id);
        $this->grant($narrowUser, 'reporting.subscription.manage', AnchorType::ORG_UNIT, (int) $orgA->id);
        $this->grant($narrowUser, 'reporting.subscription.manage', AnchorType::LEGAL_ENTITY, (int) $entity->id);
        // The export route itself is legal-entity anchored; row scope for the
        // exported report still comes from reporting.report.read above.
        $this->grant($narrowUser, 'reporting.export', AnchorType::LEGAL_ENTITY, (int) $entity->id);
        $this->grant($narrowUser, 'reporting.export.read', AnchorType::LEGAL_ENTITY, (int) $entity->id);

        return [
            'entity_id' => (int) $entity->id,
            'org_a_id' => (int) $orgA->id,
            'org_b_id' => (int) $orgB->id,
            'employment_a1_id' => (int) $a1->id,
            'employment_a2_id' => (int) $a2->id,
            'employment_b1_id' => (int) $b1->id,
            'employment_b2_id' => (int) $b2->id,
            'broad_user' => $broadUser,
            'narrow_user' => $narrowUser,
            'broad_principal' => $broadPrincipal,
            'narrow_principal' => $narrowPrincipal,
        ];
    }

    protected function employment(
        PeopleScenario $scenario,
        int $orgUnitId,
        string $employeeNo,
        string $name,
    ): Employment {
        $entity = $scenario->legalEntity('SYNTH-LE', 'Synthetic HRMS Entity');
        $org = \App\Modules\Organization\Models\OrgUnit::query()->whereKey($orgUnitId)->firstOrFail();
        $employment = $scenario->employment(
            $scenario->person(['legal_name' => $name]),
            $entity,
            $employeeNo,
        );
        $scenario->assignment($employment, $org);

        return $employment;
    }

    protected function leaveGrant(int $employmentId, int $leaveTypeId, int $minutes, string $sourceId): void
    {
        LeaveLedgerEntry::query()->create([
            'tenant_id' => 1,
            'employment_id' => $employmentId,
            'leave_type_id' => $leaveTypeId,
            'account' => 'GRANT',
            'quantity_minutes' => $minutes,
            'business_date' => self::BUSINESS_DATE,
            'effective_on' => self::BUSINESS_DATE,
            'source_type' => 'TEST',
            'source_id' => $sourceId,
            'case_id' => null,
            'reason' => 'Synthetic opening balance for reporting test.',
            'entry_hash' => hash('sha256', $sourceId.'|'.$employmentId.'|'.$minutes),
            'recorded_at' => $this->clock->now(),
        ]);
    }

    protected function authenticate(int $userId, int $employmentId, int $authVersion = 1): AuthenticatedPrincipal
    {
        $principal = new AuthenticatedPrincipal(
            userId: $userId,
            tenantId: 1,
            employmentId: $employmentId,
            authVersion: $authVersion,
            privileged: false,
            mfa: true,
            sessionId: 'synthetic-session',
        );

        $this->app->instance(Principal::class, $principal);
        $this->app->instance(AuthenticatedPrincipal::class, $principal);
        $this->app->make(AuthenticationContext::class)->set($principal);

        return $principal;
    }

    protected function grant(
        User $user,
        string $capability,
        AnchorType $anchor,
        ?int $anchorId,
        FieldProfile $profile = FieldProfile::P0_PUBLIC,
    ): AuthorityBinding {
        /** @var AuthorityBinding $binding */
        $binding = AuthorityBinding::factory()
            ->forPrincipal((int) $user->tenant_id, (int) $user->id, (int) $user->auth_version)
            ->anchor($anchor, $anchorId, $profile)
            ->create([
                'capability_code' => $capability,
                'valid_from' => '2026-01-01 00:00:00',
                'valid_to' => '9999-12-31 00:00:00',
                'purpose_code' => 'TEST',
                'created_at' => $this->clock->now(),
            ]);

        return $binding;
    }

    /**
     * Post an export command with the mandatory Idempotency-Key. The key is
     * derived from the report/payload so a test retry is a true replay.
     *
     * @param  array<string, mixed>  $payload
     * @return \Illuminate\Testing\TestResponse<\Illuminate\Http\Response>
     */
    protected function postExport(string $reportCode, array $payload = []): \Illuminate\Testing\TestResponse
    {
        $body = $this->exportPayload($reportCode, $payload);

        return $this->postJson('/v1/reporting/exports', $body, [
            'Idempotency-Key' => 'export-'.hash('sha256', json_encode($body, JSON_THROW_ON_ERROR)),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function exportPayload(string $reportCode, array $payload = []): array
    {
        return [
            'reportCode' => $reportCode,
            'purpose' => 'BUSINESS_OPERATION',
            'fields' => ['employee', 'employee_no', 'org_path'],
            'asOf' => self::BUSINESS_DATE,
            ...$payload,
        ];
    }
}
