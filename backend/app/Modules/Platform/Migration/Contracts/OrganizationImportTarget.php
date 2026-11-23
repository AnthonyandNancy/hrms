<?php

declare(strict_types=1);

namespace App\Modules\Platform\Migration\Contracts;

use App\Modules\Platform\Migration\Dto\MigrationRow;
use App\Modules\Platform\Migration\Dto\TargetWriteResult;

/**
 * Target for organization rows. The real Organization module adapter binds to
 * this port once org_units/org_unit_closure exist.
 */
interface OrganizationImportTarget
{
    public function applyOrganization(MigrationRow $row, int $tenantId, int $runId): TargetWriteResult;
}
