<?php

declare(strict_types=1);

namespace App\Modules\IAM\Console\Commands;

/**
 * One installer step. Steps are independent and idempotent so a failed install
 * can be re-run without producing duplicates.
 */
final class InstallStep
{
    public const STATUS_CREATED = 'CREATED';

    public const STATUS_SKIPPED = 'SKIPPED';

    public const STATUS_FAILED = 'FAILED';

    public function __construct(
        public readonly string $name,
        public string $status = self::STATUS_CREATED,
        public string $detail = '',
    ) {}
}
