<?php

declare(strict_types=1);

namespace App\Modules\Lifecycle\Services;

use App\Modules\Lifecycle\Contracts\ExportTaskCanceller;

final class NullExportTaskCanceller implements ExportTaskCanceller
{
    public function cancelPendingForEmployment(int $tenantId, int $employmentId, string $reason): int
    {
        return 0;
    }
}
