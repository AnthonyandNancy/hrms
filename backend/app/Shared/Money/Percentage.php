<?php

declare(strict_types=1);

namespace App\Shared\Money;

use InvalidArgumentException;

/**
 * A non-negative percentage stored at scale 1e6 where 50% = 500000.
 * The stored value is 100 times the equivalent ratio.
 */
final readonly class Percentage
{
    public const SCALE = 6;

    private function __construct(private int $scaled) {}

    public static function fromScaledInt(int $scaled): self
    {
        if ($scaled < 0) {
            throw new InvalidArgumentException('Percentage cannot be negative.');
        }

        return new self($scaled);
    }

    public static function fromPercent(string $percent): self
    {
        $value = Decimal::fromString($percent);
        if ($value->compare(Decimal::fromString('0')) < 0) {
            throw new InvalidArgumentException('Percentage cannot be negative.');
        }

        // percent / 100 * 1e6 == percent * 1e4.
        return new self($value->multiply(Decimal::fromString('10000'))->toScaledInt(0));
    }

    public static function fromRatio(Decimal $ratio): self
    {
        if ($ratio->compare(Decimal::fromString('0')) < 0) {
            throw new InvalidArgumentException('Percentage cannot be negative.');
        }

        return new self($ratio->toScaledInt(self::SCALE));
    }

    public function scaled(): int
    {
        return $this->scaled;
    }

    public function toRatio(): Decimal
    {
        return Decimal::fromScaledInt($this->scaled, self::SCALE);
    }
}
