<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Contracts;

/**
 * Payroll-owned outbound seam invoked only after publication commits. The
 * implementation must not expose amounts and must be idempotent by run id.
 */
interface PayslipPublicationNotifier
{
    public function notifyRunPublished(int $runId): void;
}
