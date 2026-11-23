<?php

declare(strict_types=1);

namespace App\Modules\Talent\Contracts;

final readonly class RecruitmentFunnelRow
{
    public function __construct(
        public string $stage,
        public int $count,
        public ?int $orgUnitId,
    ) {}
}
