<?php

declare(strict_types=1);

namespace App\Modules\IAM\Contracts;

/**
 * Frozen authentication principal contract owned by IAM. Other modules consume
 * only these accessor methods and never IAM models or internals.
 */
interface Principal
{
    public function userId(): int;

    public function tenantId(): int;

    public function employmentId(): ?int;

    public function authVersion(): int;

    public function isPrivileged(): bool;

    public function hasMfa(): bool;
}
