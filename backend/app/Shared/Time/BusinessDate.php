<?php

declare(strict_types=1);

namespace App\Shared\Time;

use App\Shared\Exceptions\ValidationException;
use DateTimeImmutable;
use DateTimeZone;
use JsonSerializable;
use Stringable;

/**
 * A calendar date with no time or timezone component. Instances are immutable
 * and always use the ISO YYYY-MM-DD representation stored by DATE columns.
 */
final readonly class BusinessDate implements JsonSerializable, Stringable
{
    public const OPEN_END = '9999-12-31';

    private function __construct(private string $date) {}

    public static function from(string $date): self
    {
        $parsed = self::tryFrom($date);

        if ($parsed === null) {
            throw new ValidationException(['date' => ['Dates must use a real YYYY-MM-DD calendar date.']]);
        }

        return $parsed;
    }

    public static function tryFrom(string $date): ?self
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('UTC'));

        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            return null;
        }

        return new self($date);
    }

    /**
     * Parse a date and return the start-of-day instant for code that needs a
     * Carbon value (for example to add days or compare instants).
     */
    public static function parse(string $date): \Carbon\CarbonImmutable
    {
        return \Carbon\CarbonImmutable::parse(self::from($date)->value(), 'UTC')->startOfDay();
    }

    public static function today(string $timezone, Clock $clock): self
    {
        try {
            $zone = new DateTimeZone($timezone);
        } catch (\Exception) {
            throw new ValidationException(['timezone' => ["Unsupported timezone [{$timezone}]."]]);
        }

        return self::from($clock->now()->setTimezone($zone)->format('Y-m-d'));
    }

    public static function openEnd(): self
    {
        return new self(self::OPEN_END);
    }

    public function value(): string
    {
        return $this->date;
    }

    public function isOpenEnd(): bool
    {
        return $this->date === self::OPEN_END;
    }

    public function isBefore(self $other): bool
    {
        return $this->date < $other->date;
    }

    public function isAfter(self $other): bool
    {
        return $this->date > $other->date;
    }

    public function compareTo(self $other): int
    {
        return $this->date <=> $other->date;
    }

    public function addDays(int $days): self
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $this->date, new DateTimeZone('UTC'));

        if ($date === false) {
            throw new ValidationException(['date' => ['Invalid calendar date.']]);
        }

        return new self($date->modify(sprintf('%+d days', $days))->format('Y-m-d'));
    }

    public function jsonSerialize(): string
    {
        return $this->date;
    }

    public function __toString(): string
    {
        return $this->date;
    }
}
