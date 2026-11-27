<?php

declare(strict_types=1);

namespace App\Shared\Projections;

/**
 * Input contract for one projection read. Filters are explicit; the company
 * scope is implicit because the instance serves exactly one company.
 */
final class ProjectionRequest
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function __construct(
        public readonly string $asOf,
        public readonly int $page,
        public readonly int $pageSize,
        public readonly array $filters = [],
    ) {}
}
