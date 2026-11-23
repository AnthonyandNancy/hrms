<?php

declare(strict_types=1);

namespace App\Modules\Platform\Migration\Dto;

final class CsvRow
{
    /**
     * @param  array<string, string>  $values
     */
    public function __construct(
        public readonly int $sourceRowNumber,
        public readonly array $values,
    ) {}
}
