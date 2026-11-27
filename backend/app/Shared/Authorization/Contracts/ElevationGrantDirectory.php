<?php

declare(strict_types=1);

namespace App\Shared\Authorization\Contracts;

/**
 * Break-glass elevation lookup. A grant is only trusted when active, bound to
 * the same user and not expired or revoked.
 */
interface ElevationGrantDirectory
{
    /**
     * Capability codes (or ['*']) granted by the active elevation.
     *
     * @return list<string>
     */
    public function activeCapabilities(int $userId, int $grantId): array;
}
