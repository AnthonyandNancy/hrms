<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Services;

use App\Modules\Reporting\Contracts\ExportDispatcher;
use App\Modules\Reporting\Jobs\GenerateExportJob;
use Illuminate\Support\Facades\Queue;

final class QueueExportDispatcher implements ExportDispatcher
{
    public function dispatch(int $tenantId, int $exportJobId): void
    {
        Queue::push(new GenerateExportJob($tenantId, $exportJobId));
    }
}
