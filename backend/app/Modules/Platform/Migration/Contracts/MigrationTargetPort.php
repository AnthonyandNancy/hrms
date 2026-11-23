<?php

declare(strict_types=1);

namespace App\Modules\Platform\Migration\Contracts;

use App\Modules\Platform\Migration\Dto\MigrationRow;
use App\Modules\Platform\Migration\Dto\TargetMetrics;
use App\Modules\Platform\Migration\Dto\TargetWriteResult;
use App\Modules\Platform\Migration\Enums\MigrationDomain;

/**
 * Narrow write + reconciliation boundary between the migration framework and
 * the owning business module. Adapters must be idempotent by the domain's
 * natural source key and stamp source_type + source_id=<stg id> on every row.
 */
interface MigrationTargetPort
{
    public function domain(): MigrationDomain;

    public function isAvailable(): bool;

    public function unavailableReason(): ?string;

    public function apply(MigrationRow $row, int $tenantId, int $runId): TargetWriteResult;

    /**
     * Run domain-specific second-pass work (manager resolution, closure
     * rebuild) after all rows are applied and before the transaction commits.
     */
    public function finalize(int $tenantId, int $runId): void;

    public function metrics(int $tenantId): TargetMetrics;

    /**
     * Plan-only statements identifying rows written by a run. The framework
     * never executes these automatically.
     *
     * @return list<string>
     */
    public function rollbackStatements(int $tenantId, int $runId): array;
}
