<?php

declare(strict_types=1);

namespace App\Shared\Authorization;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The single validity predicate for authority bindings. Bindings are written
 * with a wall-clock instant but evaluated against a business date, so the
 * window must be half-open over the whole day: [dayStart, nextDayStart).
 *
 * A grant created at 00:42 today is effective today. A grant whose valid_to
 * is exactly today 00:00:00 is not effective today.
 */
final class BindingValidity
{
    public static function dayStart(string $businessDate): string
    {
        return $businessDate.' 00:00:00';
    }

    public static function nextDayStart(string $businessDate): string
    {
        return CarbonImmutable::parse($businessDate, 'UTC')->addDay()->toDateString().' 00:00:00';
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function whereActiveOn(Builder $query, string $businessDate): Builder
    {
        return $query
            ->where('valid_from', '<', self::nextDayStart($businessDate))
            ->where('valid_to', '>', self::dayStart($businessDate));
    }

    public static function isActiveOn(string $validFrom, string $validTo, string $businessDate): bool
    {
        return $validFrom < self::nextDayStart($businessDate)
            && $validTo > self::dayStart($businessDate);
    }
}
