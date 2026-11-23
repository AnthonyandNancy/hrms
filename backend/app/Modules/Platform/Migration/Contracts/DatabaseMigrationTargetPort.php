<?php

declare(strict_types=1);

namespace App\Modules\Platform\Migration\Contracts;

/**
 * Marks adapters that write real business tables. The runner enforces cutover
 * domain order for these targets and requires every referenced table to exist.
 */
interface DatabaseMigrationTargetPort extends MigrationTargetPort {}
