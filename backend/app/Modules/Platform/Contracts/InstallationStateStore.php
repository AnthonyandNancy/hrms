<?php

declare(strict_types=1);

namespace App\Modules\Platform\Contracts;

/**
 * Platform-owned lifecycle for the one supported production installation.
 *
 * IAM uses this port to create the administrator; it never writes the
 * platform table directly.
 */
interface InstallationStateStore
{
    public function current(): ?InstallationStateSnapshot;

    public function begin(string $adminUsername, string $applicationVersion): InstallationStateSnapshot;

    public function complete(int $adminUserId, string $catalogHash): InstallationStateSnapshot;

    public function fail(string $reason): InstallationStateSnapshot;
}
