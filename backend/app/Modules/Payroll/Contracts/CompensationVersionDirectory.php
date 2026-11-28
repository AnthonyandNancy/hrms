<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Contracts;

/**
 * Frozen execution-compensation lookup used by Snapshot v2. Payroll owns both
 * the table and this port; Time/People never read EmployeeCompensation rows.
 */
interface CompensationVersionDirectory
{
    /**
     * @return array{
     *     compensation_id:int,
     *     employment_id:int,
     *     contract_version_id:int|null,
     *     effective_from:string,
     *     effective_to:string|null,
     *     status:string,
     *     base_salary_cents:int,
     *     currency:string,
     *     salary_scheme_code:string|null,
     *     payroll_scheme_id:int|null,
     *     payroll_scheme_version:int|null,
     *     parameter_overrides:array<string, mixed>
     * }|null
     */
    public function effectiveForEmployment(int $employmentId, string $asOf): ?array;

    /**
     * Effective-dated execution compensation segments overlapping a payroll
     * earnings period. Boundaries are clamped to the requested period.
     *
     * @return list<array{
     *     compensation_id:int,
     *     segment_from:string,
     *     segment_to:string,
     *     base_salary_cents:int,
     *     currency:string,
     *     contract_version_id:int|null,
     *     payroll_scheme_id:int|null,
     *     payroll_scheme_version:int|null,
     *     salary_scheme_code:string|null,
     *     parameter_overrides:array<string, mixed>
     * }>
     */
    public function segmentsForPeriod(int $employmentId, string $periodStart, string $periodEnd): array;
}
