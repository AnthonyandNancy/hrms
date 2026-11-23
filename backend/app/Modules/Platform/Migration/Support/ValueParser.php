<?php

declare(strict_types=1);

namespace App\Modules\Platform\Migration\Support;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Throwable;

final class ValueParser
{
    public static function date(string $value): ?string
    {
        $value = trim($value);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();

        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }

        return $date->format('Y-m-d');
    }

    public static function integer(string $value): ?int
    {
        $value = trim($value);

        if ($value === '' || preg_match('/^-?\d+$/', $value) !== 1) {
            return null;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($parsed) ? $parsed : null;
    }

    public static function boolean(string $value): ?bool
    {
        return match (strtolower(trim($value))) {
            '1', 'true', 'yes', 'y' => true,
            '0', 'false', 'no', 'n' => false,
            default => null,
        };
    }

    public static function compareDates(string $left, string $right): int
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $left)->getTimestamp()
            <=> CarbonImmutable::createFromFormat('!Y-m-d', $right)->getTimestamp();
    }

    /**
     * Lexicographic Y-m-d comparison is safe after strict parsing.
     */
    public static function dateBefore(string $left, string $right): bool
    {
        return $left < $right;
    }

    public static function canonicalJson(string $value): string
    {
        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return trim($value);
        }

        return is_array($decoded) ? json_encode($decoded, JSON_THROW_ON_ERROR) : trim($value);
    }
}
