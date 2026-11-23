<?php

declare(strict_types=1);

namespace App\Modules\Platform\Migration\Services;

use App\Modules\Platform\Migration\Contracts\MigrationTargetPort;
use App\Modules\Platform\Migration\Enums\MigrationDomain;
use App\Modules\Platform\Migration\Exceptions\TargetUnavailableException;

/**
 * Holds the narrow target port for each domain. Real database adapters register
 * themselves when their module exists; an explicitly requested shadow target is
 * used only for rehearsals and is labelled as such in the run report.
 */
final class MigrationTargetRegistry
{
    /** @var array<string, MigrationTargetPort> */
    private array $targets = [];

    /** @var array<string, MigrationTargetPort> */
    private array $shadowTargets = [];

    public function register(MigrationTargetPort $target, bool $shadow = false): void
    {
        if ($shadow) {
            $this->shadowTargets[$target->domain()->value] = $target;

            return;
        }

        $this->targets[$target->domain()->value] = $target;
    }

    /**
     * @throws TargetUnavailableException
     */
    public function target(MigrationDomain $domain, bool $allowShadow = false, bool $preferShadow = false): MigrationTargetPort
    {
        if ($preferShadow && isset($this->shadowTargets[$domain->value])) {
            return $this->shadowTargets[$domain->value];
        }

        $target = $this->targets[$domain->value] ?? null;

        if ($target !== null && $target->isAvailable()) {
            return $target;
        }

        if ($allowShadow) {
            $shadow = $this->shadowTargets[$domain->value] ?? null;

            if ($shadow !== null) {
                return $shadow;
            }
        }

        $reason = $target?->unavailableReason() ?? 'no adapter registered for this domain';

        throw TargetUnavailableException::forDomain($domain->value, $reason);
    }

    public function has(MigrationDomain $domain): bool
    {
        return isset($this->targets[$domain->value]) && $this->targets[$domain->value]->isAvailable();
    }

    /**
     * @return array<string, array{available: bool, shadow: bool, reason: string|null}>
     */
    public function status(): array
    {
        $status = [];

        foreach (MigrationDomain::cases() as $domain) {
            $target = $this->targets[$domain->value] ?? null;
            $status[$domain->value] = [
                'available' => $target !== null && $target->isAvailable(),
                'shadow' => ! ($target !== null && $target->isAvailable()) && isset($this->shadowTargets[$domain->value]),
                'reason' => $target?->unavailableReason() ?? 'No database adapter registered.',
            ];
        }

        return $status;
    }

    public function clear(): void
    {
        $this->targets = [];
        $this->shadowTargets = [];
    }
}
