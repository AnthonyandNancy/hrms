<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\IAM\Contracts\Principal;
use App\Shared\Authorization\AnchorRef;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\Authorizer;
use App\Shared\Authorization\FieldProfile;
use App\Shared\Authorization\FieldSet;
use App\Shared\Authorization\ResourceRef;
use Illuminate\Database\Eloquent\Builder;

final class AllowAllAuthorizer implements Authorizer
{
    /** @var list<array{capability: string, anchor: AnchorRef, on: string}> */
    public array $calls = [];

    /** @var list<string> */
    public array $deniedCapabilities = [];

    /** @var array<string, FieldProfile> */
    public array $fieldProfiles = [];

    public ?int $constrainedOrgUnitId = null;

    public function constrain(Builder $query, Principal $principal, string $capability, string $businessDate): Builder
    {
        $this->calls[] = ['capability' => $capability, 'anchor' => AnchorRef::self(), 'on' => $businessDate];

        if ($this->constrainedOrgUnitId !== null) {
            $query->where('org_unit_id', $this->constrainedOrgUnitId);
        }

        return in_array($capability, $this->deniedCapabilities, true) ? $query->whereRaw('1 = 0') : $query;
    }

    public function permits(Principal $principal, string $capability, string $businessDate): bool
    {
        return $this->allows($principal, $capability, AnchorRef::self(), $businessDate);
    }

    public function allows(Principal $principal, string $capability, AnchorRef $anchor, string $businessDate): bool
    {
        $this->calls[] = ['capability' => $capability, 'anchor' => $anchor, 'on' => $businessDate];

        if ($this->constrainedOrgUnitId !== null && $anchor->type === AnchorType::COMPANY) {
            return false;
        }

        return ! in_array($capability, $this->deniedCapabilities, true);
    }

    public function fields(Principal $principal, string $capability, ResourceRef $row): FieldSet
    {
        // Record the resolution: ADR-0024 tests assert that a restricted read
        // actually passes through the field projection.
        $this->calls[] = [
            'capability' => $capability,
            'anchor' => AnchorRef::self(),
            'on' => '',
        ];

        $profile = $this->fieldProfiles[$capability] ?? null;
        $schemas = [
            'payslip' => [
                'gross' => FieldProfile::P3_RESTRICTED,
                'net' => FieldProfile::P3_RESTRICTED,
                'deductions' => FieldProfile::P3_RESTRICTED,
                'taxable' => FieldProfile::P3_RESTRICTED,
                'employer_cost' => FieldProfile::P3_RESTRICTED,
                'employee_si' => FieldProfile::P3_RESTRICTED,
                'employee_hf' => FieldProfile::P3_RESTRICTED,
                'tax' => FieldProfile::P3_RESTRICTED,
            ],
            'employee_compensation' => [
                'id' => FieldProfile::P0_PUBLIC,
                'employment_id' => FieldProfile::P0_PUBLIC,
                'effective_from' => FieldProfile::P0_PUBLIC,
                'currency' => FieldProfile::P0_PUBLIC,
                'base_salary_cents' => FieldProfile::P3_RESTRICTED,
            ],
            'benefit_enrollment' => [
                'id' => FieldProfile::P0_PUBLIC,
                'plan_id' => FieldProfile::P0_PUBLIC,
                'employment_id' => FieldProfile::P0_PUBLIC,
                'base_amount_cents' => FieldProfile::P3_RESTRICTED,
                'employee_rate' => FieldProfile::P1_INTERNAL,
                'employer_rate' => FieldProfile::P1_INTERNAL,
                'employee_contribution_cents' => FieldProfile::P3_RESTRICTED,
                'employer_contribution_cents' => FieldProfile::P3_RESTRICTED,
                'effective_from' => FieldProfile::P0_PUBLIC,
                'effective_to' => FieldProfile::P0_PUBLIC,
                'version' => FieldProfile::P0_PUBLIC,
            ],
            'benefit_transaction' => [
                'id' => FieldProfile::P0_PUBLIC,
                'enrollment_id' => FieldProfile::P0_PUBLIC,
                'employment_id' => FieldProfile::P0_PUBLIC,
                'period' => FieldProfile::P0_PUBLIC,
                'base_amount_cents' => FieldProfile::P3_RESTRICTED,
                'employee_amount_cents' => FieldProfile::P3_RESTRICTED,
                'employer_amount_cents' => FieldProfile::P3_RESTRICTED,
                'total_amount_cents' => FieldProfile::P3_RESTRICTED,
                'payroll_run_id' => FieldProfile::P1_INTERNAL,
                'entry_type' => FieldProfile::P0_PUBLIC,
                'reversal_of_id' => FieldProfile::P0_PUBLIC,
                'reversed_by_id' => FieldProfile::P0_PUBLIC,
                'reason' => FieldProfile::P1_INTERNAL,
                'source_hash' => FieldProfile::P1_INTERNAL,
                'status' => FieldProfile::P0_PUBLIC,
            ],
            'salary_adjustment' => [
                'id' => FieldProfile::P0_PUBLIC,
                'employment_id' => FieldProfile::P0_PUBLIC,
                'effective_from' => FieldProfile::P0_PUBLIC,
                'currency' => FieldProfile::P0_PUBLIC,
                'case_id' => FieldProfile::P1_INTERNAL,
                'base_salary_cents' => FieldProfile::P3_RESTRICTED,
            ],
        ];
        $amounts = $schemas[$row->resourceType] ?? [];

        if ($profile === null) {
            throw new \App\Shared\Exceptions\NotFoundOrForbiddenException;
        }

        if ($profile->rank() < FieldProfile::P2_SENSITIVE->rank()) {
            throw new \App\Shared\Exceptions\NotFoundOrForbiddenException;
        }

        $set = new FieldSet($amounts, restrictedVisible: $profile->rank() >= FieldProfile::P3_RESTRICTED->rank());

        // Mirror production CapabilityAuthorizer::fields(): any P2/P3 field
        // that is visible leaves field-name-only evidence.
        $sensitive = [];

        foreach ($amounts as $field => $sensitivity) {
            if (in_array($sensitivity, [FieldProfile::P2_SENSITIVE, FieldProfile::P3_RESTRICTED], true)
                && $set->isVisible($field)) {
                $sensitive[] = $field;
            }
        }

        if ($sensitive !== []) {
            app(\App\Shared\Authorization\Contracts\SensitiveAccessAuditor::class)->record(
                userId: (int) $principal->userId(),
                capabilityCode: $capability,
                resourceType: $row->resourceType,
                resourceId: (int) $row->id,
                fields: $sensitive,
                context: ['profile' => $profile->value, 'access' => 'READ'],
            );
        }

        return $set;
    }
}
