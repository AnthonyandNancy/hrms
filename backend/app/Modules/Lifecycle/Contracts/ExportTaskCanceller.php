<?php

declare(strict_types=1);

namespace App\Modules\Lifecycle\Contracts;

/**
 * Optional integration port for cancelling pending data exports when access is
 * revoked. The current reference build binds a no-op until the Reporting task
 * ships export jobs; the contract keeps the offboarding effect complete.
 */
interface ExportTaskCanceller
{
    public function cancelPendingForEmployment(int $tenantId, int $employmentId, string $reason): int;
}
