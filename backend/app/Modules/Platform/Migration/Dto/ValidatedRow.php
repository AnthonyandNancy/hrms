<?php

declare(strict_types=1);

namespace App\Modules\Platform\Migration\Dto;

final class ValidatedRow
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly int $sourceRowNumber,
        public readonly array $data,
    ) {}
}
