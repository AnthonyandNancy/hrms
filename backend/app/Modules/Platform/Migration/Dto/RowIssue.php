<?php

declare(strict_types=1);

namespace App\Modules\Platform\Migration\Dto;

use App\Modules\Platform\Migration\Enums\IssueCode;
use App\Modules\Platform\Migration\Enums\IssueSeverity;

final class RowIssue
{
    public function __construct(
        public readonly IssueSeverity $severity,
        public readonly IssueCode $code,
        public readonly string $message,
        public readonly ?int $stgId = null,
        public readonly ?int $sourceRowNumber = null,
        public readonly ?string $field = null,
    ) {}
}
