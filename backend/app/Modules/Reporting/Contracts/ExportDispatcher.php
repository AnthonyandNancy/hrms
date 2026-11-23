<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Contracts;

/**
 * Async export dispatch seam. The reference implementation runs on the queue;
 * tests and local installations may bind the synchronous adapter.
 */
interface ExportDispatcher
{
    public function dispatch(int $tenantId, int $exportJobId): void;
}
