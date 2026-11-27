<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Contracts;

/**
 * Read-only lookup used by composition/demo seeding to bind run-scoped
 * capabilities without importing Payroll models from another module.
 */
interface PayrollRunDirectory
{
    public function latestRunId(): ?int;
}
