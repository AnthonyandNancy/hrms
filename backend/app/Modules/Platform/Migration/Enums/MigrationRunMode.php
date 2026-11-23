<?php

declare(strict_types=1);

namespace App\Modules\Platform\Migration\Enums;

enum MigrationRunMode: string
{
    case DRY_RUN = 'DRY_RUN';
    case APPLY = 'APPLY';
    case VALIDATE = 'VALIDATE';
}
