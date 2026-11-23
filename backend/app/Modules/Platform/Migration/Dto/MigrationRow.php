<?php

declare(strict_types=1);

namespace App\Modules\Platform\Migration\Dto;

final class MigrationRow
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly int $stgId,
        public readonly int $sourceRowNumber,
        public readonly array $data,
    ) {}
}
