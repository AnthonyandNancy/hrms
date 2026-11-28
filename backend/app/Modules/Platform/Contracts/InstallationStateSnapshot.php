<?php

declare(strict_types=1);

namespace App\Modules\Platform\Contracts;

/**
 * Read-only installation state crossing the Platform/IAM boundary.
 */
final readonly class InstallationStateSnapshot
{
    public function __construct(
        public string $status,
        public string $adminUsername,
        public ?int $adminUserId,
        public ?string $applicationVersion,
        public ?string $catalogHash,
        public ?string $startedAt,
        public ?string $completedAt,
        public ?string $lastError,
    ) {}
}
