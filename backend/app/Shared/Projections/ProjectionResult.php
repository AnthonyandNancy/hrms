<?php

declare(strict_types=1);

namespace App\Shared\Projections;

use App\Shared\Time\BusinessDate;
use InvalidArgumentException;

/**
 * One page of a projection plus the evidence needed to trust it.
 *
 * The value object enforces only the invariants stated in ADR-0029:
 *  - freshness is LIVE, MATERIALIZED or STALE;
 *  - a partial or stale projection carries a non-blank partial_reason;
 *  - a stale projection is always partial (rows are missing);
 *  - MATERIALIZED requires a stable source hash; LIVE/STALE may have none;
 *  - as_of is a real calendar date, not merely YYYY-MM-DD shaped.
 *
 * `total` is deliberately opaque: list projections use it as a row count and
 * aggregate projections use it as a metric value (which may be negative, e.g.
 * a negative leave liability). The envelope therefore must not reinterpret it.
 */
final class ProjectionResult
{
    public const FRESHNESS_LIVE = 'LIVE';

    public const FRESHNESS_MATERIALIZED = 'MATERIALIZED';

    public const FRESHNESS_STALE = 'STALE';

    public const MAX_PAGE_SIZE = 100;

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

        if ($pageSize < 1 || $pageSize > self::MAX_PAGE_SIZE) {
            throw new InvalidArgumentException('Projection page_size must be between 1 and '.self::MAX_PAGE_SIZE.'.');
        }

        if (! in_array($freshness, [
            self::FRESHNESS_LIVE,
            self::FRESHNESS_MATERIALIZED,
            self::FRESHNESS_STALE,
        ], true)) {
            throw new InvalidArgumentException('Projection freshness must be LIVE, MATERIALIZED or STALE.');
        }

        $reason = $partialReason === null ? null : trim($partialReason);
        $isIncomplete = $partial || $freshness === self::FRESHNESS_STALE;

        if ($isIncomplete && ($reason === null || $reason === '')) {
            throw new InvalidArgumentException('A partial or stale projection must carry partial_reason.');
        }

        if ($freshness === self::FRESHNESS_STALE && ! $partial) {
            throw new InvalidArgumentException('A stale projection must set partial=true.');
        }

        if (BusinessDate::tryFrom($asOf) === null) {
            throw new InvalidArgumentException('Projection as_of must be a real YYYY-MM-DD calendar date.');
        }

        if ($freshness === self::FRESHNESS_MATERIALIZED && ! self::isSourceHash($sourceHash)) {
            throw new InvalidArgumentException('A materialized projection requires a SHA-256 source_hash (optionally prefixed with "sha256:").');
        }

        if ($sourceHash !== '' && ! self::isSourceHash($sourceHash)) {
            throw new InvalidArgumentException('Projection source_hash must be empty or a SHA-256 digest (optionally prefixed with "sha256:").');
        }
    }

    private static function isSourceHash(string $hash): bool
    {
        $hex = str_starts_with($hash, 'sha256:') ? substr($hash, 7) : $hash;

        return preg_match('/^[a-f0-9]{64}$/i', $hex) === 1;
    }
}
