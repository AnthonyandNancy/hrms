<?php

declare(strict_types=1);

namespace App\Modules\Platform\Migration\Dto;

final class TargetWriteResult
{
    public const CREATED = 'CREATED';

    public const UPDATED = 'UPDATED';

    public const SKIPPED = 'SKIPPED';

    public function __construct(
        public readonly int $targetId,
        public readonly string $status,
        public readonly bool $idempotentReplay = false,
    ) {}
}
