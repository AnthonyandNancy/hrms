<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Jobs;

use App\Modules\Reporting\Services\ExportJobRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class GenerateExportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $exportJobId,
    ) {}

    public function handle(ExportJobRunner $runner): void
    {
        $runner->run($this->tenantId, $this->exportJobId);
    }
}
