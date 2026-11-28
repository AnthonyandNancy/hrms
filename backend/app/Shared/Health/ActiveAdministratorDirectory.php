<?php

declare(strict_types=1);

namespace App\Shared\Health;

/**
 * Read-only readiness fact owned by IAM.
 */
interface ActiveAdministratorDirectory
{
    public function count(): int;
}
