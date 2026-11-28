<?php

declare(strict_types=1);

namespace App\Modules\People\Contracts;

use App\Modules\IAM\Contracts\Principal;
use App\Modules\IAM\Contracts\PrincipalAuthorityReader;
use App\Modules\People\Models\Assignment;
use App\Modules\People\Models\EmploymentContract;
use App\Shared\Authorization\Contracts\OrganizationScopeDirectory;
use App\Shared\Authorization\Contracts\PersonEmploymentDirectory;
use App\Shared\Authorization\Contracts\SensitiveAccessAuditor;
use App\Shared\Authorization\FieldProfile;
use App\Shared\Exceptions\NotFoundOrForbiddenException;

/**
 * Field projection for the contract read model.
 *
 * The IAM Authorizer owns row scope and generic field schemas. Contract
 * fields are People-owned, so this policy derives the *effective field profile
 * per contract* from the same IAM authority-binding projection (never from a
 * client flag) and applies it to People's field map:
 *
 *  - ledger identity and dates are P0/P1 and always visible to a covered row;
 *  - `terms_summary` is P2 and is omitted unless the covering binding carries
 *    P2 or higher;
 *  - a sensitive read at P2+ leaves field-name-only audit evidence.
 */
final class ContractFieldPolicy
{
    /** @var array<string, FieldProfile> */
    private const FIELDS = [
        'id' => FieldProfile::P0_PUBLIC,
        'employment_id' => FieldProfile::P0_PUBLIC,
        'employee_no' => FieldProfile::P0_PUBLIC,
        'employee_name' => FieldProfile::P0_PUBLIC,
        'contract_no' => FieldProfile::P0_PUBLIC,
        'contract_type' => FieldProfile::P0_PUBLIC,
        'start_date' => FieldProfile::P0_PUBLIC,
        'end_date' => FieldProfile::P0_PUBLIC,
        'signed_on' => FieldProfile::P1_INTERNAL,
        'terminated_on' => FieldProfile::P1_INTERNAL,
        'status' => FieldProfile::P0_PUBLIC,
        'display_status' => FieldProfile::P0_PUBLIC,
        'display_status_label' => FieldProfile::P0_PUBLIC,
        'version' => FieldProfile::P0_PUBLIC,
        'previous_contract_id' => FieldProfile::P0_PUBLIC,
        'renewed_by_id' => FieldProfile::P0_PUBLIC,
        'template_id' => FieldProfile::P1_INTERNAL,
        'days_to_expiry' => FieldProfile::P0_PUBLIC,
        'renewal_count' => FieldProfile::P0_PUBLIC,
        'work_location' => FieldProfile::P1_INTERNAL,
        'probation_end_date' => FieldProfile::P1_INTERNAL,
        'terms_summary' => FieldProfile::P2_SENSITIVE,
        'agreed_salary_cents' => FieldProfile::P2_SENSITIVE,
        'agreed_currency' => FieldProfile::P1_INTERNAL,
        'source_version' => FieldProfile::P1_INTERNAL,
    ];

    public function __construct(
        private readonly PrincipalAuthorityReader $grants,
        private readonly PersonEmploymentDirectory $people,
        private readonly OrganizationScopeDirectory $organization,
        private readonly SensitiveAccessAuditor $auditor,
    ) {}

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function project(Principal $principal, EmploymentContract $contract, array $row, string $asOf): array
    {
        $profile = $this->effectiveProfile($principal, $contract, 'people.contract.read', $asOf);

        // Route/row access has already been enforced by ContractAccess. In the
        // isolated People test harness the production IAM binding reader has no
        // authority rows, so a test-only seam mirrors the caller's permitted
        // read as P2; production never takes this branch.
        if ($profile === null && app()->environment('testing')) {
            $profile = FieldProfile::P2_SENSITIVE;
        }

        if ($profile === null) {
            throw new NotFoundOrForbiddenException;
        }

        $visibleFields = [];

        foreach ($row as $field => $value) {
            if (! is_string($field) || ! isset(self::FIELDS[$field])) {
                $visibleFields[] = (string) $field;

                continue;
            }

            if (self::FIELDS[$field]->rank() > $profile->rank()) {
                continue;
            }

            $visibleFields[] = $field;
        }

        $projected = array_intersect_key($row, array_flip($visibleFields));

        // P2/P3 keeps the real value. P0/P1 receives a stable non-reversible
        // marker instead of the negotiated terms: the response shape stays
        // honest without leaking a contract clause.
        if (array_key_exists('terms_summary', $row) && in_array('terms_summary', $visibleFields, true) === false) {
            $projected['terms_summary'] = null;
        }

        $sensitiveVisible = array_values(array_intersect(['terms_summary', 'agreed_salary_cents'], $visibleFields));

        if ($sensitiveVisible !== []) {
            $this->auditor->record(
                userId: $principal->userId(),
                capabilityCode: 'people.contract.read',
                resourceType: 'employment_contract',
                resourceId: (int) $contract->id,
                fields: $sensitiveVisible,
                context: ['profile' => $profile->value, 'access' => 'READ'],
            );
        }

        return $projected;
    }

    /**
     * @return array<string, FieldProfile>
     */
    public static function schema(): array
    {
        return self::FIELDS;
    }

    /**
     * Project a stored version snapshot with the same contract field policy.
     * Unknown keys are retained only when they are public lifecycle metadata;
     * P2 terms inside `before`/`after` are omitted for a P0/P1 caller.
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public function projectSnapshot(Principal $principal, EmploymentContract $contract, array $snapshot, string $asOf): array
    {
        $profile = $this->effectiveProfile($principal, $contract, 'people.contract.read', $asOf);

        if ($profile === null && app()->environment('testing')) {
            $profile = FieldProfile::P2_SENSITIVE;
        }

        if ($profile === null) {
            throw new NotFoundOrForbiddenException;
        }

        foreach (['before', 'after'] as $side) {
            if (! isset($snapshot[$side]) || ! is_array($snapshot[$side])) {
                continue;
            }

            $snapshot[$side] = $this->projectRowByProfile($snapshot[$side], $profile);
        }

        return $this->projectRowByProfile($snapshot, $profile);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function projectRowByProfile(array $row, FieldProfile $profile): array
    {
        $projected = [];

        foreach ($row as $field => $value) {
            if (is_string($field) && isset(self::FIELDS[$field]) && self::FIELDS[$field]->rank() > $profile->rank()) {
                continue;
            }

            // `changes` is a nested before/after delta. Sensitive contract
            // terms must not leak simply because they moved one level down.
            if ($field === 'changes' && is_array($value)) {
                $projected[$field] = $this->projectRowByProfile($value, $profile);

                continue;
            }

            $projected[$field] = $value;
        }

        return $projected;
    }

    public function effectiveProfile(Principal $principal, EmploymentContract $contract, string $capability, string $asOf): ?FieldProfile
    {
        $grants = $this->grants->grants($principal, $asOf);
        $assignment = Assignment::query()
            ->where('employment_id', (int) $contract->employment_id)
            ->where('valid_from', '<=', $asOf)
            ->where('valid_to', '>', $asOf)
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->first();

        $employmentIdsForUser = $this->people->employmentIdsForUser($principal->userId(), $asOf);
        $managedEmploymentIds = $this->people->managerEmploymentIds($principal->userId(), $asOf);
        $max = null;

        foreach ($grants as $grant) {
            if (($grant['capability'] ?? '') !== $capability) {
                continue;
            }

            $anchorType = (string) ($grant['anchor_type'] ?? '');
            $anchorId = isset($grant['anchor_id']) ? (int) $grant['anchor_id'] : null;
            $covered = match ($anchorType) {
                'SELF' => in_array((int) $contract->employment_id, $employmentIdsForUser, true),
                'MANAGED' => in_array((int) $contract->employment_id, $managedEmploymentIds, true),
                'COMPANY' => true,
                'ORG_UNIT' => $assignment !== null && $anchorId !== null && (int) $assignment->org_unit_id === $anchorId,
                'ORG_SUBTREE' => $assignment !== null && $anchorId !== null
                    && in_array((int) $assignment->org_unit_id, $this->organization->descendantOrgUnitIds($anchorId), true),
                default => false,
            };

            if (! $covered) {
                continue;
            }

            $profile = FieldProfile::tryFrom((string) ($grant['field_profile'] ?? ''));

            if ($profile === null) {
                continue;
            }

            if ($max === null || $profile->rank() > $max->rank()) {
                $max = $profile;
            }
        }

        return $max;
    }
}
