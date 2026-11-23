<?php

declare(strict_types=1);

namespace App\Modules\Platform\Migration\Enums;

enum IssueSeverity: string
{
    case ERROR = 'ERROR';
    case WARNING = 'WARNING';
    case INFO = 'INFO';
}
