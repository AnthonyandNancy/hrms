<?php

declare(strict_types=1);

namespace App\Shared\Projections;

/**
 * Input contract for one projection read. `tenantId` always comes from the
 * resolved principal, never from the query string.
 */
final class ProjectionRequest
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function __construct(
        public readonly int $tenantId,
        public readonly string $asOf,
        public readonly int $page,
        public readonly int $pageSize,
        public readonly array $filters = [],
    ) {}
}
