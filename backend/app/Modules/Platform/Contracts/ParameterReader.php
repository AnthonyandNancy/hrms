<?php

declare(strict_types=1);

namespace App\Modules\Platform\Contracts;

/**
 * Read port for typed tenant parameters. Missing or malformed values fall back
 * to the caller's default instead of throwing, so a bad parameter can never
 * take an entire module offline.
 */
interface ParameterReader
{
    public function get(int $tenantId, string $key, mixed $default = null): mixed;
}
