<?php

declare(strict_types=1);

namespace App\Modules\Platform\Migration\Dto;

final class TargetMetrics
{
    /**
     * @param  array<string, int>  $sums
     */
    public function __construct(
        public readonly int $count,
        public readonly array $sums,
    ) {}
}
