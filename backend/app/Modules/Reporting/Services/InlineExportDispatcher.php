<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Services;

use App\Modules\Reporting\Contracts\ExportDispatcher;

/**
 * Local/test dispatcher. Production binds the queue-backed dispatcher and the
 * HTTP contract remains 202 in both cases.
 */
final class InlineExportDispatcher implements ExportDispatcher
{
    public function __construct(private readonly ExportJobRunner $runner) {}

    public function dispatch(int $tenantId, int $exportJobId): void
    {
        $this->runner->run($tenantId, $exportJobId);
    }
}
