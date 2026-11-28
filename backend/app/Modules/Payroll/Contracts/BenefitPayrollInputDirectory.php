<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Contracts;

/**
 * Payroll-owned input port for benefit amounts effective in one payroll
 * period. The default adapter is empty until T14 publishes real enrollments;
 * replacing it must not change the snapshot schema.
 */
interface BenefitPayrollInputDirectory
{
    /**
     * @return list<array{
     *   item_code:string,
     *   label:string,
     *   employee_amount_cents:int,
     *   employer_amount_cents:int,
     *   source_type:string,
     *   source_id:string,
     *   taxable:bool,
     *   sort_order:int
     * }>
     */
    public function frozenForPeriod(int $employmentId, string $period): array;
}
