<?php

declare(strict_types=1);

namespace App\Modules\IAM\Auth;

use App\Modules\IAM\Contracts\Principal;
use App\Shared\Authorization\AnchorRef;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\FieldProfile;
use App\Shared\Authorization\FieldSet;
use App\Shared\Authorization\ResourceRef;
use App\Shared\Authorization\Authorizer;
use App\Shared\Authorization\AnchorType as Anchor;
use App\Shared\Authorization\Contracts\AuthorityBindingRepository;
use App\Shared\Authorization\Contracts\CaseMembershipDirectory;
use App\Shared\Authorization\Contracts\OrganizationScopeDirectory;
use App\Shared\Authorization\Contracts\PayrollRunMembershipDirectory;
use App\Shared\Authorization\Contracts\PersonEmploymentDirectory;
use App\Shared\Authorization\Contracts\SensitiveAccessAuditor;
use App\Shared\Authorization\BindingGrant;
use App\Shared\Authorization\SodRules;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * Default-deny authorization engine.
 *
 * Resolution order for one binding: tenant -> principal+auth_version ->
 * capability -> effective dates -> SOD -> anchor target. Row predicates are
 * unions; field profiles are resolved per covered row and never merged across
 * bindings that do not cover the row.
 */
final class CapabilityAuthorizer implements Authorizer
{
    private const FIELD_SCHEMAS = [
        'employee' => [
            'id' => FieldProfile::P0_PUBLIC,
            'employee_no' => FieldProfile::P0_PUBLIC,
            'display_name' => FieldProfile::P0_PUBLIC,
            'org_unit' => FieldProfile::P0_PUBLIC,
            'job_title' => FieldProfile::P0_PUBLIC,
            'email' => FieldProfile::P1_INTERNAL,
            'mobile' => FieldProfile::P1_INTERNAL,
            'address' => FieldProfile::P1_INTERNAL,
            'id_number' => FieldProfile::P2_SENSITIVE,
            'bank_account' => FieldProfile::P2_SENSITIVE,
            'contract_amount' => FieldProfile::P2_SENSITIVE,
            'salary' => FieldProfile::P3_RESTRICTED,
            'health' => FieldProfile::P3_RESTRICTED,
            'background_check' => FieldProfile::P3_RESTRICTED,
        ],
        'user' => [
            'id' => FieldProfile::P0_PUBLIC,
            'username' => FieldProfile::P0_PUBLIC,
            'status' => FieldProfile::P1_INTERNAL,
            'email' => FieldProfile::P1_INTERNAL,
        ],
        'payslip' => [
            'id' => FieldProfile::P0_PUBLIC,
            'period' => FieldProfile::P0_PUBLIC,
            'gross' => FieldProfile::P3_RESTRICTED,
            'net' => FieldProfile::P3_RESTRICTED,
            'deductions' => FieldProfile::P3_RESTRICTED,
        ],
    ];

    public function __construct(
        private readonly AuthorityBindingRepository $bindings,
        private readonly PersonEmploymentDirectory $people,
        private readonly OrganizationScopeDirectory $organization,
        private readonly CaseMembershipDirectory $cases,
        private readonly PayrollRunMembershipDirectory $payroll,
        private readonly SensitiveAccessAuditor $auditor,
        private readonly \App\Shared\Authorization\Contracts\AccessSubjectStateDirectory $subjects,
    ) {}

    public function constrain(Builder $query, Principal $p, string $capability, string $businessDate): Builder
    {
        $this->assertPrincipalIntegrity($p);
        $this->assertLiveSubject($p);

        $grants = $this->matchingBindings($p, $capability, $businessDate);

        if ($grants === []) {
            // Default deny: an unsatisfiable predicate retains a valid Builder.
            return $query->whereRaw('1 = 0');
        }

        $model = $query->getModel();
        $table = $model->getTable();

        return $query->where(function (Builder $outer) use ($grants, $p, $capability, $businessDate, $table): void {
            foreach ($grants as $grant) {
                $outer->orWhere(function (Builder $inner) use ($grant, $p, $capability, $businessDate, $table): void {
                    $this->constrainForGrant($inner, $grant, $p, $capability, $businessDate, $table);
                });
            }
        });
    }

    public function allows(Principal $p, string $capability, AnchorRef $anchor, string $businessDate): bool
    {
        $this->assertLiveSubject($p);

        if ($capability === 'iam.session.manage' && $p->sessionId() !== null) {
            return true;
        }

        if ($p->breakGlassGrantId() !== null && $this->breakGlassAllows($p, $capability)) {
            return true;
        }

        foreach ($this->matchingBindings($p, $capability, $businessDate) as $grant) {
            if (! $this->matchingAnchorType($grant, $anchor)) {
                continue;
            }

            $anchorId = $anchor->id;

            if ($anchor->type->requiresAnchorId() && $anchorId === null) {
                continue;
            }

            if ($this->anchorAllows($grant, $p, $capability, $anchorId, $businessDate)) {
                return true;
            }
        }

        return false;
    }

    public function fields(Principal $p, string $capability, ResourceRef $row): FieldSet
    {
        $this->assertPrincipalIntegrity($p);
        $this->assertLiveSubject($p);

        $schema = self::FIELD_SCHEMAS[$row->resourceType] ?? null;

        if ($schema === null) {
            return new FieldSet([]);
        }

        $businessDate = $this->today();
        $grants = $this->matchingBindings($p, $capability, $businessDate);
        $max = null;

        foreach ($grants as $grant) {
            if (! $this->resourceCoveredByGrant($grant, $p, $row, $capability, $businessDate)) {
                continue;
            }

            if ($max === null || $grant->fieldProfile->rank() > $max->rank()) {
                $max = $grant->fieldProfile;
            }
        }

        if ($max === null) {
            throw new NotFoundOrForbiddenException();
        }

        $effective = $capability === 'payroll.export' || str_ends_with($capability, '.export')
            ? ($max->rank() < FieldProfile::P3_RESTRICTED->rank() ? $max : FieldProfile::P3_RESTRICTED)
            : $max;

        $profiles = [];
        foreach ($schema as $field => $sensitivity) {
            $profiles[$field] = $sensitivity->isAtMost($effective) ? $sensitivity : FieldProfile::P3_RESTRICTED;
        }

        $set = new FieldSet($profiles, $capability === 'payroll.export' || str_ends_with($capability, '.export'));

        $sensitive = [];
        foreach ($set->profiles() as $field => $profile) {
            if (in_array($profile, [FieldProfile::P2_SENSITIVE, FieldProfile::P3_RESTRICTED], true)
                && $set->isVisible($field)) {
                $sensitive[] = $field;
            }
        }

        if ($sensitive !== []) {
            $this->auditor->record(
                tenantId: $p->tenantId(),
                userId: $p->userId(),
                resourceType: $row->resourceType,
                resourceId: (string) $row->id,
                capability: $capability,
                fields: $sensitive,
                context: ['profile' => $effective->value],
            );
        }

        return $set;
    }

    /**
     * @return list<BindingGrant>
     */
    private function matchingBindings(Principal $p, string $capability, string $businessDate): array
    {
        $grants = $this->bindings->effectiveBindings(
            $p->tenantId(),
            $p->userId(),
            $capability,
            $p->authVersion(),
            $businessDate,
        );

        $codes = array_map(static fn (BindingGrant $grant): string => $grant->capabilityCode, $grants);

        try {
            SodRules::assertAllowed(array_values(array_unique($codes)));
        } catch (ConflictException) {
            return [];
        }

        return array_values(array_filter(
            $grants,
            static fn (BindingGrant $grant): bool => $grant->tenantId === $p->tenantId()
                && $grant->principalId === $p->userId()
                && $grant->authVersion === $p->authVersion()
                && $grant->isEffectiveOn($businessDate),
        ));
    }

    private function constrainForGrant(
        Builder $query,
        BindingGrant $grant,
        Principal $principal,
        string $capability,
        string $businessDate,
        string $table,
    ): void {
        $query->where("{$table}.tenant_id", $principal->tenantId());

        switch ($grant->anchorType) {
            case Anchor::SELF:
                $employmentIds = $this->people->employmentIdsForUser($principal->tenantId(), $principal->userId());
                $this->whereColumnIn($query, 'employment_id', $employmentIds);
                break;

            case Anchor::MANAGED:
                $employmentIds = $this->people->managedEmploymentIds($principal->tenantId(), $principal->userId(), $businessDate);
                $this->whereColumnIn($query, 'employment_id', $employmentIds);
                break;

            case Anchor::ORG_UNIT:
                $unitIds = $grant->anchorId === null ? [] : [$grant->anchorId];
                $this->whereColumnIn($query, $this->orgColumn($query), $unitIds);
                break;

            case Anchor::ORG_SUBTREE:
                $unitIds = $grant->anchorId === null
                    ? []
                    : $this->organization->descendantUnitIds($principal->tenantId(), $grant->anchorId, $businessDate);
                $this->whereColumnIn($query, $this->orgColumn($query), $unitIds);
                break;

            case Anchor::CASE:
                $this->whereColumnIn($query, 'case_id', [$grant->anchorId]);
                break;

            case Anchor::PAYROLL_RUN:
                $this->whereColumnIn($query, 'payroll_run_id', [$grant->anchorId]);
                break;

            case Anchor::LEGAL_ENTITY:
                $employmentIds = $this->employmentIdsForLegalEntity($principal->tenantId(), $grant->anchorId, $businessDate);
                $this->whereColumnIn($query, 'employment_id', $employmentIds);
                break;
        }
    }

    /**
     * @param  list<int|null>  $ids
     */
    private function whereColumnIn(Builder $query, string $column, array $ids): void
    {
        $clean = array_values(array_filter($ids, static fn (?int $id): bool => $id !== null && $id > 0));

        if ($clean === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereIn($column, $clean);
    }

    private function orgColumn(Builder $query): string
    {
        $model = $query->getModel();

        return method_exists($model, 'authorizationOrgColumn') ? $model->authorizationOrgColumn() : 'org_unit_id';
    }

    /**
     * @return list<int>
     */
    private function employmentIdsForLegalEntity(int $tenantId, ?int $legalEntityId, string $businessDate): array
    {
        if ($legalEntityId === null) {
            return [];
        }

        $unitIds = $this->organization->unitIdsForLegalEntity($tenantId, $legalEntityId, $businessDate);
        $employmentIds = [];

        foreach ($unitIds as $unitId) {
            foreach ($this->people->employmentIdsForOrgUnits($tenantId, [$unitId], $businessDate) as $employmentId) {
                $employmentIds[] = $employmentId;
            }
        }

        return array_values(array_unique($employmentIds));
    }

    private function matchingAnchorType(BindingGrant $grant, AnchorRef $anchor): bool
    {
        return $grant->anchorType === $anchor->type;
    }

    private function anchorAllows(BindingGrant $grant, Principal $p, string $capability, ?int $anchorId, string $businessDate): bool
    {
        return match ($grant->anchorType) {
            Anchor::SELF => $this->people->employmentIdsForUser($p->tenantId(), $p->userId()) !== [],
            Anchor::MANAGED => $this->people->managedEmploymentIds($p->tenantId(), $p->userId(), $businessDate) !== [],
            Anchor::ORG_UNIT => $anchorId !== null
                && $anchorId === $grant->anchorId
                && $this->organization->isUnitInTenant($p->tenantId(), $anchorId, $businessDate),
            Anchor::ORG_SUBTREE => $anchorId !== null
                && $this->organization->isUnitInTenant($p->tenantId(), $anchorId, $businessDate)
                && in_array($anchorId, $this->organization->descendantUnitIds($p->tenantId(), $grant->anchorId ?? 0, $businessDate), true),
            Anchor::CASE => $anchorId !== null
                && $anchorId === $grant->anchorId
                && $this->cases->isMember($p->tenantId(), $p->userId(), $anchorId, $capability),
            Anchor::PAYROLL_RUN => $anchorId !== null
                && $anchorId === $grant->anchorId
                && $this->payroll->isMember($p->tenantId(), $p->userId(), $anchorId, $capability),
            Anchor::LEGAL_ENTITY => $anchorId !== null
                && $anchorId === $grant->anchorId
                && $this->organization->legalEntityExists($p->tenantId(), $anchorId),
        };
    }

    private function resourceCoveredByGrant(BindingGrant $grant, Principal $p, ResourceRef $row, string $capability, string $businessDate): bool
    {
        if ($row->tenantId !== null && $row->tenantId !== $p->tenantId()) {
            return false;
        }

        return match ($grant->anchorType) {
            Anchor::SELF => $row->employmentId !== null
                && in_array($row->employmentId, $this->people->employmentIdsForUser($p->tenantId(), $p->userId()), true)
                && $grant->anchorId === null,
            Anchor::MANAGED => $row->employmentId !== null
                && in_array($row->employmentId, $this->people->managedEmploymentIds($p->tenantId(), $p->userId(), $businessDate), true),
            Anchor::ORG_UNIT => $row->orgUnitId !== null && $row->orgUnitId === $grant->anchorId,
            Anchor::ORG_SUBTREE => $row->orgUnitId !== null
                && $grant->anchorId !== null
                && in_array($row->orgUnitId, $this->organization->descendantUnitIds($p->tenantId(), $grant->anchorId, $businessDate), true),
            Anchor::CASE => $row->caseId !== null
                && $row->caseId === $grant->anchorId
                && $this->cases->isMember($p->tenantId(), $p->userId(), $row->caseId, $capability),
            Anchor::PAYROLL_RUN => $row->payrollRunId !== null
                && $row->payrollRunId === $grant->anchorId
                && $this->payroll->isMember($p->tenantId(), $p->userId(), $row->payrollRunId, $capability),
            Anchor::LEGAL_ENTITY => $row->legalEntityId !== null && $row->legalEntityId === $grant->anchorId,
        };
    }

    private function assertLiveSubject(Principal $p): void
    {
        if (! $this->subjects->isEnabled($p->tenantId(), $p->userId())) {
            throw new NotFoundOrForbiddenException();
        }

        if ($this->subjects->currentAuthVersion($p->tenantId(), $p->userId()) !== $p->authVersion()) {
            throw new NotFoundOrForbiddenException();
        }
    }

    private function assertPrincipalIntegrity(Principal $p): void
    {
        if ($p->userId() <= 0 || $p->tenantId() <= 0 || $p->authVersion() <= 0) {
            throw new NotFoundOrForbiddenException();
        }
    }

    private function breakGlassAllows(Principal $principal, string $capability): bool
    {
        $grantId = $principal->breakGlassGrantId();

        if ($grantId === null) {
            return false;
        }

        $scope = app(\App\Shared\Authorization\Contracts\ElevationGrantDirectory::class)
            ->activeCapabilities($principal->tenantId(), $principal->userId(), $grantId);

        if ($scope === null) {
            return false;
        }

        return in_array('*', $scope, true) || in_array($capability, $scope, true);
    }

    private function today(): string
    {
        return \Carbon\CarbonImmutable::now('UTC')->toDateString();
    }
}
