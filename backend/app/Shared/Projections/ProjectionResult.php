<?php

declare(strict_types=1);

namespace App\Shared\Projections;

use InvalidArgumentException;

/**
 * One page of a projection plus the evidence needed to trust it.
 *
 * The value object enforces the invariants of ADR-0029 at construction time so
 * no controller can emit a STALE or partial payload without an explanation, a
 * materialized payload without a stable source hash, or an invalid page shape.
 */
final class ProjectionResult
{
    public const FRESHNESS_LIVE = 'LIVE';

    public const FRESHNESS_MATERIALIZED = 'MATERIALIZED';

    public const FRESHNESS_STALE = 'STALE';

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>|null  $drilldown
     */
    public function __construct(
        public readonly array $items,
        public readonly int $page,
        public readonly int $pageSize,
        public readonly int $total,
        public readonly string $asOf,
        public readonly string $freshness,
        public readonly string $sourceHash,
        public readonly bool $partial,
        public readonly ?string $partialReason,
        public readonly ?array $drilldown,
    ) {
        if ($page < 1) {
            throw new InvalidArgumentException('Projection page must be at least 1.');
        }

        if ($pageSize < 1) {
            throw new InvalidArgumentException('Projection page_size must be at least 1.');
        }

        if ($total < 0 || $total < count($items)) {
            throw new InvalidArgumentException('Projection total cannot be smaller than the returned items.');
        }

        if (! in_array($freshness, [
            self::FRESHNESS_LIVE,
            self::FRESHNESS_MATERIALIZED,
            self::FRESHNESS_STALE,
        ], true)) {
            throw new InvalidArgumentException('Projection freshness must be LIVE, MATERIALIZED or STALE.');
        }

        $reason = $partialReason === null ? null : trim($partialReason);

        if (($partial || $freshness === self::FRESHNESS_STALE) && ($reason === null || $reason === '')) {
            throw new InvalidArgumentException('A partial or stale projection must carry partial_reason.');
        }

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf)) {
            throw new InvalidArgumentException('Projection as_of must be a date in YYYY-MM-DD format.');
        }

        if ($freshness !== self::FRESHNESS_LIVE || $sourceHash !== '') {
            if (preg_match('/^[a-f0-9]{64}$/', $sourceHash) !== 1) {
                throw new InvalidArgumentException('Projection source_hash must be a SHA-256 hex digest.');
            }
        }
    }
}
