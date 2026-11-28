<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Contracts;

/**
 * Payroll-owned one-time performance bonus input. T15 replaces the default
 * empty adapter with published performance results.
 */
interface PerformanceBonusDirectory
{
    /**
     * @return list<array{
     *   item_code:string,
     *   label:string,
     *   amount_cents:int,
     *   source_type:string,
     *   source_id:string,
     *   taxable:bool,
     *   sort_order:int
     * }>
     */
    public function frozenForPeriod(int $employmentId, string $period): array;
}
