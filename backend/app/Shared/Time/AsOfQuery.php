<?php

declare(strict_types=1);

namespace App\Shared\Time;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Centralises the two temporal predicates used by every effective-dated read
 * model. Business code must not hand-roll whereDate() comparisons.
 */
final class AsOfQuery
{
    /**
     * @param  Builder<*>  $query
     * @return Builder<*>
     */
    public static function whereValidOn(Builder $query, BusinessDate|string $on, string $fromColumn = 'valid_from', string $toColumn = 'valid_to'): Builder
    {
        $date = $on instanceof BusinessDate ? $on->value() : BusinessDate::from($on)->value();

        return $query
            ->where(function (Builder $builder) use ($fromColumn, $toColumn, $date): void {
                $builder->where($fromColumn, '<=', $date)
                    ->where($toColumn, '>', $date);
            });
    }

    /**
     * Filter to rows that the system knew at the supplied UTC instant. A row
     * superseded exactly at known_at is no longer known (half-open).
     *
     * @param  Builder<*>  $query
     * @return Builder<*>
     */
    public static function whereKnownAt(Builder $query, CarbonImmutable $knownAt, string $recordedColumn = 'recorded_at', string $supersededColumn = 'superseded_at'): Builder
    {
        $timestamp = $knownAt->utc()->format('Y-m-d H:i:s.u');

        return $query
            ->where($recordedColumn, '<=', $timestamp)
            ->where(function (Builder $builder) use ($supersededColumn, $timestamp): void {
                $builder->whereNull($supersededColumn)
                    ->orWhere($supersededColumn, '>', $timestamp);
            });
    }
}
