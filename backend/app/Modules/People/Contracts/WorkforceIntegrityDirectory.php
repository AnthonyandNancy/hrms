<?php

declare(strict_types=1);

namespace App\Modules\People\Contracts;

/**
 * Read-only integrity diagnostics over People-owned effective-dated facts.
 *
 * Lifecycle's integrity command used to run raw SQL over `assignments` and
 * `employments`, which crosses the one-writer/module-boundary rule. These
 * narrow diagnostics keep the SQL inside People, which owns the tables, and
 * expose only the findings Lifecycle needs.
 */
interface WorkforceIntegrityDirectory
{
    /**
     * Primary assignments that overlap in validity for the same employment.
     *
     * @return list<array{employment_id: int, assignment_ids: list<int>}>
     */
    public function overlappingPrimaryAssignments(): array;

    /**
     * Assignments whose validity extends past a terminated employment.
     *
     * @return list<array{employment_id: int, assignment_id: int}>
     */
    public function assignmentsOnTerminatedEmployments(): array;
}
