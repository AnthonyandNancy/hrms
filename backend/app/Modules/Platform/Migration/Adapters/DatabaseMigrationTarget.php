<?php

declare(strict_types=1);

namespace App\Modules\Platform\Migration\Adapters;

use App\Modules\Platform\Migration\Contracts\DatabaseMigrationTargetPort;
use App\Modules\Platform\Migration\Dto\MigrationRow;
use App\Modules\Platform\Migration\Dto\TargetMetrics;
use App\Modules\Platform\Migration\Dto\TargetWriteResult;
use App\Modules\Platform\Migration\Enums\MigrationDomain;
use App\Modules\Platform\Migration\Models\StagingRow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use stdClass;

/**
 * Generic adapter for the real target tables. It is registered for every
 * domain but reports itself unavailable until the owning module's tables and
 * required columns exist, so this branch can be tested without inventing the
 * business schema. Availability is re-evaluated per operation (safe for cache-
 * warm during a phased deploy).
 *
 * Direct table writes are deliberate and scoped: this is the one adapter the
 * migration framework uses to bootstrap target modules before their services
 * are reachable. It never writes outside the documented target tables.
 */
final class DatabaseMigrationTarget implements DatabaseMigrationTargetPort
{
    public function __construct(private readonly MigrationDomain $domain) {}

    public function domain(): MigrationDomain
    {
        return $this->domain;
    }

    public function isAvailable(): bool
    {
        foreach ($this->requiredColumns() as $table => $columns) {
            if (! Schema::hasTable($table)) {
                return false;
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    return false;
                }
            }
        }

        return true;
    }

    public function unavailableReason(): ?string
    {
        foreach ($this->requiredColumns() as $table => $columns) {
            if (! Schema::hasTable($table)) {
                return "Required target table '{$table}' does not exist yet.";
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    return "Required target column '{$table}.{$column}' does not exist yet.";
                }
            }
        }

        return null;
    }

    public function apply(MigrationRow $row, int $tenantId, int $runId): TargetWriteResult
    {
        if (! $this->isAvailable()) {
            throw new RuntimeException((string) $this->unavailableReason());
        }

        return match ($this->domain) {
            MigrationDomain::ORGANIZATIONS => $this->applyOrganization($row, $tenantId),
            MigrationDomain::PEOPLE => $this->applyPerson($row, $tenantId),
            MigrationDomain::ASSIGNMENTS => $this->applyAssignment($row, $tenantId),
            MigrationDomain::TERMS => $this->applyTerms($row, $tenantId),
            MigrationDomain::LEAVE_OPENINGS => $this->applyLeaveOpening($row, $tenantId),
            MigrationDomain::PAYROLL_YTD => $this->applyPayrollYtd($row, $tenantId),
        };
    }

    public function finalize(int $tenantId, int $runId): void
    {
        if ($this->domain === MigrationDomain::ORGANIZATIONS) {
            $this->rebuildOrganizationClosure($tenantId);
        }

        if ($this->domain === MigrationDomain::ASSIGNMENTS) {
            $this->resolveManagers($tenantId, $runId);
        }
    }

    public function metrics(int $tenantId): TargetMetrics
    {
        $table = match ($this->domain) {
            MigrationDomain::ORGANIZATIONS => 'org_units',
            MigrationDomain::PEOPLE => 'employments',
            MigrationDomain::ASSIGNMENTS => 'assignments',
            MigrationDomain::TERMS => 'employment_terms',
            MigrationDomain::LEAVE_OPENINGS => 'leave_ledger_entries',
            MigrationDomain::PAYROLL_YTD => 'payroll_ytd',
        };
        $query = DB::table($table)->where('tenant_id', $tenantId);
        $sums = [];

        if ($this->domain === MigrationDomain::LEAVE_OPENINGS) {
            $query->where('source_type', 'MIGRATION_OPENING')->where('account', 'GRANT');
            $sums['quantity_minutes'] = (int) $query->sum('quantity_units');
        }

        if ($this->domain === MigrationDomain::PAYROLL_YTD) {
            $query->where('source_type', 'MIGRATION');
            foreach ($this->domain->reconciliationSums() as $field) {
                $sums[$field] = (int) $query->sum($field);
            }
        }

        return new TargetMetrics(count: (int) $query->count(), sums: $sums);
    }

    /**
     * @return list<string>
     */
    public function rollbackStatements(int $tenantId, int $runId): array
    {
        $statements = [];
        $stgIds = StagingRow::forDomain($this->domain)
            ->newQuery()
            ->where('run_id', $runId)
            ->whereNotNull('target_id')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        $ids = implode(',', $stgIds !== [] ? $stgIds : ['NULL']);

        foreach ($this->rollbackTables() as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'source_id')) {
                $statements[] = "DELETE FROM {$table} WHERE tenant_id = {$tenantId} AND source_type IN ('MIGRATION','MIGRATION_OPENING') AND source_id IN ({$ids});";
            }
        }

        return $statements;
    }

    /**
     * @return array<string, list<string>>
     */
    private function requiredColumns(): array
    {
        return match ($this->domain) {
            MigrationDomain::ORGANIZATIONS => [
                'legal_entities' => ['id', 'tenant_id', 'code'],
                'org_units' => ['id', 'tenant_id', 'legal_entity_id', 'parent_id', 'code', 'name', 'type', 'valid_from', 'valid_to', 'source_type', 'source_id'],
                'org_unit_closure' => ['ancestor_id', 'descendant_id', 'depth', 'valid_from', 'valid_to'],
            ],
            MigrationDomain::PEOPLE => [
                'legal_entities' => ['id', 'tenant_id', 'code'],
                'persons' => ['id', 'tenant_id', 'legal_name', 'id_type', 'id_number_ciphertext', 'id_number_hash', 'birth_date'],
                'employments' => ['id', 'tenant_id', 'person_id', 'legal_entity_id', 'employee_no', 'employment_type', 'status', 'valid_from', 'valid_to', 'hire_date', 'termination_date', 'source_type', 'source_id'],
            ],
            MigrationDomain::ASSIGNMENTS => [
                'employments' => ['id', 'tenant_id', 'employee_no'],
                'org_units' => ['id', 'tenant_id', 'code'],
                'assignments' => ['id', 'tenant_id', 'employment_id', 'org_unit_id', 'position_id', 'manager_assignment_id', 'assignment_type', 'is_primary', 'valid_from', 'valid_to', 'source_type', 'source_id'],
            ],
            MigrationDomain::TERMS => [
                'employments' => ['id', 'tenant_id', 'employee_no'],
                'employment_terms' => ['id', 'tenant_id', 'employment_id', 'version', 'effective_from', 'effective_to', 'probation_end_date', 'salary_scheme_code', 'source_type', 'source_id'],
            ],
            MigrationDomain::LEAVE_OPENINGS => [
                'employments' => ['id', 'tenant_id', 'employee_no'],
                'leave_types' => ['id', 'tenant_id', 'code'],
                'leave_accounts' => ['id', 'tenant_id', 'employment_id', 'leave_type_id', 'opened_on', 'closed_on'],
                'leave_ledger_entries' => ['id', 'tenant_id', 'employment_id', 'leave_type_id', 'account', 'quantity_units', 'business_date', 'effective_on', 'source_type', 'source_id'],
            ],
            MigrationDomain::PAYROLL_YTD => [
                'employments' => ['id', 'tenant_id', 'employee_no'],
                'payroll_ytd' => ['id', 'tenant_id', 'employment_id', 'tax_year', 'taxable_income_cents', 'tax_withheld_cents', 'social_insurance_cents', 'housing_fund_cents', 'gross_cents', 'net_cents'],
            ],
        };
    }

    /**
     * @return list<string>
     */
    private function rollbackTables(): array
    {
        return match ($this->domain) {
            MigrationDomain::ORGANIZATIONS => ['org_units'],
            MigrationDomain::PEOPLE => ['employments'],
            MigrationDomain::ASSIGNMENTS => ['assignments'],
            MigrationDomain::TERMS => ['employment_terms'],
            MigrationDomain::LEAVE_OPENINGS => ['leave_ledger_entries'],
            MigrationDomain::PAYROLL_YTD => ['payroll_ytd'],
        };
    }

    private function applyOrganization(MigrationRow $row, int $tenantId): TargetWriteResult
    {
        $data = $row->data;
        $now = CarbonImmutable::now('UTC');
        $legalEntityId = $this->legalEntityId($tenantId, (string) $data['legal_entity_code']);
        $mappedId = isset($data['mapped_org_id']) && is_int($data['mapped_org_id']) ? $data['mapped_org_id'] : null;
        $existing = DB::table('org_units')->where('tenant_id', $tenantId)->where('code', $data['code'])->first();
        $parentId = $this->organizationId($tenantId, (string) ($data['parent_code'] ?? ''));

        $attributes = [
            'tenant_id' => $tenantId,
            'legal_entity_id' => $legalEntityId,
            'parent_id' => $parentId,
            'code' => $data['code'],
            'name' => $data['name'],
            'type' => $data['type'],
            'status' => 'ACTIVE',
            'valid_from' => $data['valid_from'],
            'valid_to' => $data['valid_to'],
            'recorded_at' => $now,
            'superseded_at' => null,
            'source_type' => 'MIGRATION',
            'source_id' => $row->stgId,
        ];

        if ($existing instanceof stdClass) {
            if ($this->sameAttributes($existing, $attributes, ['parent_id', 'name', 'type', 'valid_from', 'valid_to', 'legal_entity_id'])) {
                return new TargetWriteResult((int) $existing->id, TargetWriteResult::SKIPPED, true);
            }

            DB::table('org_units')->where('id', $existing->id)->update([
                'parent_id' => $parentId,
                'name' => $data['name'],
                'type' => $data['type'],
                'valid_from' => $data['valid_from'],
                'valid_to' => $data['valid_to'],
                'source_type' => 'MIGRATION',
                'source_id' => $row->stgId,
            ]);

            return new TargetWriteResult((int) $existing->id, TargetWriteResult::UPDATED);
        }

        if ($mappedId !== null && ! DB::table('org_units')->where('id', $mappedId)->exists()) {
            $attributes['id'] = $mappedId;
        }

        $id = (int) DB::table('org_units')->insertGetId($attributes);

        return new TargetWriteResult($id, TargetWriteResult::CREATED);
    }

    private function applyPerson(MigrationRow $row, int $tenantId): TargetWriteResult
    {
        $data = $row->data;
        $now = CarbonImmutable::now('UTC');
        $legalEntityId = $this->legalEntityId($tenantId, (string) $data['legal_entity_code']);
        $person = null;

        if (($data['id_number_hash'] ?? '') !== '') {
            $person = DB::table('persons')
                ->where('tenant_id', $tenantId)
                ->where('id_type', $data['id_type'])
                ->where('id_number_hash', $data['id_number_hash'])
                ->first();
        }

        if (! $person instanceof stdClass) {
            $personId = (int) DB::table('persons')->insertGetId([
                'tenant_id' => $tenantId,
                'legal_name' => $data['legal_name'],
                'id_type' => $data['id_type'],
                'id_number_ciphertext' => $data['id_number_ciphertext'],
                'id_number_hash' => $data['id_number_hash'],
                'birth_date' => $data['birth_date'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $personReplayed = false;
        } else {
            $personId = (int) $person->id;
            $personReplayed = true;
        }

        $employment = DB::table('employments')
            ->where('tenant_id', $tenantId)
            ->where('legal_entity_id', $legalEntityId)
            ->where('employee_no', $data['employee_no'])
            ->first();

        $attributes = [
            'tenant_id' => $tenantId,
            'person_id' => $personId,
            'legal_entity_id' => $legalEntityId,
            'employee_no' => $data['employee_no'],
            'employment_type' => $data['employment_type'],
            'status' => $data['status'],
            'valid_from' => $data['valid_from'],
            'valid_to' => $data['valid_to'],
            'hire_date' => $data['hire_date'],
            'termination_date' => $data['termination_date'],
            'recorded_at' => $now,
            'superseded_at' => null,
            'source_type' => 'MIGRATION',
            'source_id' => $row->stgId,
        ];

        if ($employment instanceof stdClass) {
            if ($this->sameAttributes($employment, $attributes, ['person_id', 'employment_type', 'status', 'valid_from', 'valid_to', 'hire_date', 'termination_date'])) {
                return new TargetWriteResult((int) $employment->id, TargetWriteResult::SKIPPED, true);
            }

            DB::table('employments')->where('id', $employment->id)->update([
                'person_id' => $personId,
                'status' => $data['status'],
                'valid_from' => $data['valid_from'],
                'valid_to' => $data['valid_to'],
                'termination_date' => $data['termination_date'],
                'source_type' => 'MIGRATION',
                'source_id' => $row->stgId,
            ]);

            return new TargetWriteResult((int) $employment->id, TargetWriteResult::UPDATED);
        }

        $mappedEmploymentId = isset($data['mapped_employment_id']) && is_int($data['mapped_employment_id'])
            ? $data['mapped_employment_id']
            : null;

        if ($mappedEmploymentId !== null && ! DB::table('employments')->where('id', $mappedEmploymentId)->exists()) {
            $attributes['id'] = $mappedEmploymentId;
        }

        $employmentId = (int) DB::table('employments')->insertGetId($attributes);

        return new TargetWriteResult($employmentId, $personReplayed ? TargetWriteResult::SKIPPED : TargetWriteResult::CREATED);
    }

    private function applyAssignment(MigrationRow $row, int $tenantId): TargetWriteResult
    {
        $data = $row->data;
        $now = CarbonImmutable::now('UTC');
        $employmentId = $this->employmentId($tenantId, (string) $data['employment_no']);
        $orgUnitId = $this->organizationId($tenantId, (string) $data['org_code']);

        if ($orgUnitId === null) {
            throw new RuntimeException("Assignment references unknown organization '{$data['org_code']}'.");
        }

        $existing = DB::table('assignments')
            ->where('tenant_id', $tenantId)
            ->where('employment_id', $employmentId)
            ->where('valid_from', $data['valid_from'])
            ->where('is_primary', $data['is_primary'] ? 1 : 0)
            ->first();

        $attributes = [
            'tenant_id' => $tenantId,
            'employment_id' => $employmentId,
            'org_unit_id' => $orgUnitId,
            'position_id' => $this->positionId($tenantId, (string) ($data['position_code'] ?? '')),
            'manager_assignment_id' => null,
            'assignment_type' => $data['assignment_type'],
            'is_primary' => $data['is_primary'] ? 1 : 0,
            'valid_from' => $data['valid_from'],
            'valid_to' => $data['valid_to'],
            'recorded_at' => $now,
            'superseded_at' => null,
            'source_type' => 'MIGRATION',
            'source_id' => $row->stgId,
        ];

        if ($existing instanceof stdClass) {
            if ($this->sameAttributes($existing, $attributes, ['org_unit_id', 'assignment_type', 'is_primary', 'valid_from', 'valid_to'])) {
                return new TargetWriteResult((int) $existing->id, TargetWriteResult::SKIPPED, true);
            }

            DB::table('assignments')->where('id', $existing->id)->update([
                'org_unit_id' => $orgUnitId,
                'position_id' => $attributes['position_id'],
                'assignment_type' => $data['assignment_type'],
                'valid_from' => $data['valid_from'],
                'valid_to' => $data['valid_to'],
                'source_type' => 'MIGRATION',
                'source_id' => $row->stgId,
            ]);

            return new TargetWriteResult((int) $existing->id, TargetWriteResult::UPDATED);
        }

        $id = (int) DB::table('assignments')->insertGetId($attributes);

        return new TargetWriteResult($id, TargetWriteResult::CREATED);
    }

    private function applyTerms(MigrationRow $row, int $tenantId): TargetWriteResult
    {
        $data = $row->data;
        $now = CarbonImmutable::now('UTC');
        $employmentId = $this->employmentId($tenantId, (string) $data['employment_no']);
        $existing = DB::table('employment_terms')
            ->where('tenant_id', $tenantId)
            ->where('employment_id', $employmentId)
            ->where('version', $data['version'])
            ->first();

        $attributes = [
            'tenant_id' => $tenantId,
            'employment_id' => $employmentId,
            'version' => $data['version'],
            'effective_from' => $data['effective_from'],
            'effective_to' => $data['effective_to'],
            'probation_end_date' => $data['probation_end_date'],
            'salary_scheme_code' => $data['salary_scheme_code'],
            'work_location' => null,
            'work_schedule' => null,
            'notice_period_days' => 0,
            'confidentiality' => 0,
            'non_compete' => 0,
            'recorded_at' => $now,
            'source_type' => 'MIGRATION',
            'source_id' => $row->stgId,
        ];

        if ($existing instanceof stdClass) {
            if ($this->sameAttributes($existing, $attributes, ['effective_from', 'effective_to', 'probation_end_date', 'salary_scheme_code'])) {
                return new TargetWriteResult((int) $existing->id, TargetWriteResult::SKIPPED, true);
            }

            DB::table('employment_terms')->where('id', $existing->id)->update([
                'effective_from' => $data['effective_from'],
                'effective_to' => $data['effective_to'],
                'probation_end_date' => $data['probation_end_date'],
                'salary_scheme_code' => $data['salary_scheme_code'],
                'source_type' => 'MIGRATION',
                'source_id' => $row->stgId,
            ]);

            return new TargetWriteResult((int) $existing->id, TargetWriteResult::UPDATED);
        }

        $id = (int) DB::table('employment_terms')->insertGetId($attributes);

        return new TargetWriteResult($id, TargetWriteResult::CREATED);
    }

    private function applyLeaveOpening(MigrationRow $row, int $tenantId): TargetWriteResult
    {
        $data = $row->data;
        $now = CarbonImmutable::now('UTC');
        $employmentId = $this->employmentId($tenantId, (string) $data['employment_no']);
        $leaveTypeId = (int) DB::table('leave_types')
            ->where('tenant_id', $tenantId)
            ->where('code', $data['leave_type'])
            ->value('id');

        if ($leaveTypeId === 0) {
            throw new RuntimeException("Leave type '{$data['leave_type']}' is not configured for tenant {$tenantId}.");
        }

        $accountId = DB::table('leave_accounts')
            ->where('tenant_id', $tenantId)
            ->where('employment_id', $employmentId)
            ->where('leave_type_id', $leaveTypeId)
            ->whereNull('closed_on')
            ->value('id');

        if ($accountId === null) {
            $accountId = (int) DB::table('leave_accounts')->insertGetId([
                'tenant_id' => $tenantId,
                'employment_id' => $employmentId,
                'leave_type_id' => $leaveTypeId,
                'opened_on' => $data['business_date'],
                'closed_on' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $existing = DB::table('leave_ledger_entries')
            ->where('tenant_id', $tenantId)
            ->where('employment_id', $employmentId)
            ->where('leave_type_id', $leaveTypeId)
            ->where('account', 'GRANT')
            ->where('quantity_units', $data['quantity_minutes'])
            ->where('business_date', $data['business_date'])
            ->where('source_type', 'MIGRATION_OPENING')
            ->first();

        if ($existing instanceof stdClass) {
            return new TargetWriteResult((int) $existing->id, TargetWriteResult::SKIPPED, true);
        }

        $id = (int) DB::table('leave_ledger_entries')->insertGetId([
            'tenant_id' => $tenantId,
            'employment_id' => $employmentId,
            'leave_type_id' => $leaveTypeId,
            'account' => 'GRANT',
            'quantity_units' => $data['quantity_minutes'],
            'business_date' => $data['business_date'],
            'effective_on' => $data['effective_on'],
            'source_type' => 'MIGRATION_OPENING',
            'source_id' => $row->stgId,
            'case_id' => null,
            'reason' => 'Opening balance imported from legacy system',
            'recorded_at' => $now,
            'created_at' => $now,
        ]);

        // Keep the account id referenced so static analysis and operators can
        // see the account was resolved; ledger entries reference employment and
        // type rather than a redundant account id in the frozen schema.
        unset($accountId);

        return new TargetWriteResult($id, TargetWriteResult::CREATED);
    }

    private function applyPayrollYtd(MigrationRow $row, int $tenantId): TargetWriteResult
    {
        $data = $row->data;
        $now = CarbonImmutable::now('UTC');
        $employmentId = $this->employmentId($tenantId, (string) $data['employment_no']);
        $existing = DB::table('payroll_ytd')
            ->where('tenant_id', $tenantId)
            ->where('employment_id', $employmentId)
            ->where('tax_year', $data['tax_year'])
            ->first();

        $attributes = [
            'tenant_id' => $tenantId,
            'employment_id' => $employmentId,
            'tax_year' => $data['tax_year'],
            'taxable_income_cents' => $data['taxable_cents'],
            'tax_withheld_cents' => $data['tax_withheld_cents'],
            'social_insurance_cents' => $data['si_cents'],
            'housing_fund_cents' => $data['hf_cents'],
            'gross_cents' => $data['gross_cents'],
            'net_cents' => $data['net_cents'],
            'last_run_id' => null,
            'source_type' => 'MIGRATION',
            'source_id' => $row->stgId,
            'updated_at' => $now,
        ];

        if ($existing instanceof stdClass) {
            if ($this->sameAttributes($existing, $attributes, ['taxable_income_cents', 'tax_withheld_cents', 'social_insurance_cents', 'housing_fund_cents', 'gross_cents', 'net_cents'])) {
                return new TargetWriteResult((int) $existing->id, TargetWriteResult::SKIPPED, true);
            }

            DB::table('payroll_ytd')->where('id', $existing->id)->update($attributes);

            return new TargetWriteResult((int) $existing->id, TargetWriteResult::UPDATED);
        }

        $id = (int) DB::table('payroll_ytd')->insertGetId($attributes);

        return new TargetWriteResult($id, TargetWriteResult::CREATED);
    }

    private function legalEntityId(int $tenantId, string $code): int
    {
        $id = DB::table('legal_entities')->where('tenant_id', $tenantId)->where('code', $code)->value('id');

        if ($id === null) {
            throw new RuntimeException("Legal entity '{$code}' is not configured for tenant {$tenantId}.");
        }

        return (int) $id;
    }

    private function employmentId(int $tenantId, string $employeeNo): int
    {
        $id = DB::table('employments')->where('tenant_id', $tenantId)->where('employee_no', $employeeNo)->value('id');

        if ($id === null) {
            throw new RuntimeException("Employment '{$employeeNo}' is not configured for tenant {$tenantId}.");
        }

        return (int) $id;
    }

    private function organizationId(int $tenantId, string $code): ?int
    {
        if ($code === '') {
            return null;
        }

        $id = DB::table('org_units')->where('tenant_id', $tenantId)->where('code', $code)->value('id');

        return $id === null ? null : (int) $id;
    }

    private function positionId(int $tenantId, string $code): ?int
    {
        if ($code === '' || ! Schema::hasTable('positions')) {
            return null;
        }

        $id = DB::table('positions')->where('tenant_id', $tenantId)->where('code', $code)->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $compare
     */
    private function sameAttributes(stdClass $existing, array $attributes, array $compare): bool
    {
        foreach ($compare as $field) {
            $existingValue = $existing->{$field} ?? null;
            $newValue = $attributes[$field] ?? null;

            if ((string) $existingValue !== (string) $newValue) {
                return false;
            }
        }

        return true;
    }

    private function rebuildOrganizationClosure(int $tenantId): void
    {
        $units = DB::table('org_units')->where('tenant_id', $tenantId)->get()->keyBy('id');
        $now = CarbonImmutable::now('UTC');

        foreach ($units as $unit) {
            $current = $unit;
            $depth = 0;

            while ($current instanceof stdClass && $depth < 50) {
                DB::table('org_unit_closure')->updateOrInsert(
                    [
                        'ancestor_id' => $current->id,
                        'descendant_id' => $unit->id,
                        'valid_from' => $unit->valid_from,
                    ],
                    [
                        'depth' => $depth,
                        'valid_to' => $unit->valid_to,
                        'created_at' => $now,
                    ],
                );

                $parentId = $current->parent_id ?? null;
                $current = $parentId === null ? null : ($units[$parentId] ?? null);
                $depth++;
            }
        }
    }

    private function resolveManagers(int $tenantId, int $runId): void
    {
        $rows = StagingRow::forDomain(MigrationDomain::ASSIGNMENTS)
            ->newQuery()
            ->where('run_id', $runId)
            ->get();

        foreach ($rows as $stagingRow) {
            $data = $stagingRow->normalized_json ?? [];
            $managerEmployeeNo = trim((string) ($data['manager_employee_no'] ?? ''));

            if ($managerEmployeeNo === '') {
                continue;
            }

            $assignment = DB::table('assignments')->where('id', $stagingRow->target_id)->first();

            if (! $assignment instanceof stdClass) {
                continue;
            }

            $managerEmploymentId = DB::table('employments')
                ->where('tenant_id', $tenantId)
                ->where('employee_no', $managerEmployeeNo)
                ->value('id');

            if ($managerEmploymentId === null) {
                continue;
            }

            $managerAssignmentId = DB::table('assignments')
                ->where('tenant_id', $tenantId)
                ->where('employment_id', $managerEmploymentId)
                ->where('is_primary', 1)
                ->where('valid_from', '<=', $assignment->valid_from)
                ->where('valid_to', '>', $assignment->valid_from)
                ->value('id');

            if ($managerAssignmentId !== null) {
                DB::table('assignments')->where('id', $assignment->id)->update([
                    'manager_assignment_id' => $managerAssignmentId,
                ]);
            }
        }
    }
}
