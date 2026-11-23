<?php

declare(strict_types=1);

namespace App\Shared\Time;

use App\Shared\Exceptions\ValidationException;
use Carbon\CarbonImmutable;

final class BusinessDate
{
    public static function parse(string $date): CarbonImmutable
    {
        $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date);

        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new ValidationException(['date' => ['Dates must use the YYYY-MM-DD format.']]);
        }

        return $parsed->startOfDay();
    }

    public static function today(Clock $clock, string $timezone): string
    {
        return $clock->today($timezone)->format('Y-m-d');
    }

    public static function utcDate(string $date): string
    {
        return self::parse($date)->format('Y-m-d');
    }
}
