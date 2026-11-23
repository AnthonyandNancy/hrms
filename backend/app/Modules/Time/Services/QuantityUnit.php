<?php

declare(strict_types=1);

namespace App\Modules\Time\Services;

use App\Shared\Exceptions\ValidationException;
use Carbon\CarbonImmutable;

/**
 * Canonical leave quantity is signed integer minutes. Day-based inputs are
 * converted with the legal entity's standard day (default 480 minutes) and
 * displayed back as decimal days. No floats are used.
 */
final class QuantityUnit
{
    public static function fromDisplayDays(string $days, int $standardDayMinutes): int
    {
        self::assertStandardDay($standardDayMinutes);

        if (preg_match('/^-?\d+(\.\d+)?$/', $days) !== 1) {
            throw new ValidationException(['quantity' => ['Quantity must be a decimal number.']]);
        }

        $negative = str_starts_with($days, '-');
        $unsigned = ltrim($days, '-');
        [$whole, $fraction] = array_pad(explode('.', $unsigned, 2), 2, '');
        $fraction = rtrim($fraction, '0');
        $scale = strlen($fraction);

        if ($scale > 4) {
            throw new ValidationException(['quantity' => ['Quantity supports at most four decimal places.']]);
        }

        $numerator = (int) $whole * $standardDayMinutes;
        $fractionNumerator = $fraction === '' ? 0 : (int) $fraction * $standardDayMinutes;
        $divisor = $fraction === '' ? 1 : 10 ** $scale;
        $numerator += $fractionNumerator;

        if ($numerator % $divisor !== 0) {
            throw new ValidationException([
                'quantity' => ['Quantity converts to a fraction of a minute; use a smaller precision.'],
            ]);
        }

        $minutes = intdiv($numerator, $divisor);

        return $negative ? -$minutes : $minutes;
    }

    public static function toDisplayDays(int $minutes, int $standardDayMinutes): string
    {
        self::assertStandardDay($standardDayMinutes);

        $sign = $minutes < 0 ? '-' : '';
        $absolute = abs($minutes);
        $whole = intdiv($absolute, $standardDayMinutes);
        $remainder = $absolute % $standardDayMinutes;

        if ($remainder === 0) {
            return $sign.$whole.'.00';
        }

        $fraction = str_pad((string) intdiv($remainder * 100, $standardDayMinutes), 2, '0', STR_PAD_LEFT);

        return $sign.$whole.'.'.$fraction;
    }

    public static function displayDaysAsDecimalString(int $minutes, int $standardDayMinutes): string
    {
        return self::toDisplayDays($minutes, $standardDayMinutes);
    }

    /**
     * Whole days inside a half-open date range, used for daily leave requests.
     */
    public static function workingDayMinutes(CarbonImmutable $from, CarbonImmutable $to, int $standardDayMinutes): int
    {
        self::assertStandardDay($standardDayMinutes);
        $days = (int) $from->startOfDay()->diffInDays($to->startOfDay());

        return $days * $standardDayMinutes;
    }

    private static function assertStandardDay(int $standardDayMinutes): void
    {
        if ($standardDayMinutes <= 0 || $standardDayMinutes > 1440) {
            throw new ValidationException([
                'standard_day_minutes' => ['The legal entity standard day must be between 1 and 1440 minutes.'],
            ]);
        }
    }
}
