<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services;

use App\Modules\Payroll\Contracts\PayrollRunDirectory;
use App\Modules\Payroll\Models\PayrollRun;

final class EloquentPayrollRunDirectory implements PayrollRunDirectory
{
    public function latestRunId(): ?int
    {
        $id = PayrollRun::query()->orderByDesc('id')->value('id');

        return $id === null ? null : (int) $id;
    }
}
