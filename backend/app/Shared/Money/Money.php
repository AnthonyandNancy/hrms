<?php

declare(strict_types=1);

namespace App\Shared\Money;

use InvalidArgumentException;
use JsonSerializable;

/**
 * Integer minor-unit money. The monetary scale is fixed at two decimal places
 * (fen) for the currencies supported by this slice.
 */
final readonly class Money implements JsonSerializable
{
    private const SCALE = 2;

    private function __construct(
        private int $minor,
        private string $currency,
    ) {}

    public static function fromMinor(int $minor, string $currency = 'CNY'): self
    {
        return new self($minor === 0 ? 0 : $minor, self::normalizeCurrency($currency));
    }

    public static function fromDecimalString(
        string $amount,
        string $currency = 'CNY',
        RoundingMode $mode = RoundingMode::HALF_UP,
    ): self {
        return self::fromMinor(
            Decimal::fromString($amount)->toScaledInt(self::SCALE, $mode),
            $currency,
        );
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self(
            $this->checkedMinorSum(bcadd((string) $this->minor, (string) $other->minor, 0)),
            $this->currency,
        );
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self(
            $this->checkedMinorSum(bcsub((string) $this->minor, (string) $other->minor, 0)),
            $this->currency,
        );
    }

    public function multiplyByRatio(
        Decimal|Percentage $ratio,
        RoundingMode $mode = RoundingMode::HALF_UP,
    ): self {
        $ratioDecimal = $ratio instanceof Percentage ? $ratio->toRatio() : $ratio;
        $cents = Decimal::fromScaledInt($this->minor, self::SCALE)
            ->multiply($ratioDecimal)
            ->toScaledInt(self::SCALE, $mode);

        return new self($cents, $this->currency);
    }

    /**
     * Distribute minor units without losing or creating a cent. Any remainder
     * is assigned one cent at a time to the earliest parts.
     *
     * @return list<self>
     */
    public function allocate(int $parts): array
    {
        if ($parts < 1) {
            throw new InvalidArgumentException('Allocation requires at least one part.');
        }

        $negative = $this->minor < 0;
        $absolute = $negative ? substr((string) $this->minor, 1) : (string) $this->minor;
        $base = bcdiv($absolute, (string) $parts, 0);
        $remainder = (int) bcmod($absolute, (string) $parts, 0);
        $allocated = [];

        for ($index = 0; $index < $parts; $index++) {
            $part = bcadd($base, $index < $remainder ? '1' : '0', 0);
            $minor = (int) ($negative ? '-'.$part : $part);
            $allocated[] = new self($minor === 0 ? 0 : $minor, $this->currency);
        }

        return $allocated;
    }

    public function absolute(): self
    {
        if ($this->minor >= 0) {
            return $this;
        }

        return new self($this->checkedMinorSum(bcmul((string) $this->minor, '-1', 0)), $this->currency);
    }

    public function isZero(): bool
    {
        return $this->minor === 0;
    }

    public function isNegative(): bool
    {
        return $this->minor < 0;
    }

    public function minor(): int
    {
        return $this->minor;
    }

    public function currency(): string
    {
        return $this->currency;
    }

    public function toDecimalString(): string
    {
        return Decimal::fromScaledInt($this->minor, self::SCALE)->toDecimalString();
    }

    /**
     * @return array{amount: string, currency: string, minor: int}
     */
    public function jsonSerialize(): array
    {
        return [
            'amount' => $this->toDecimalString(),
            'currency' => $this->currency,
            'minor' => $this->minor,
        ];
    }

    private function checkedMinorSum(string $value): int
    {
        if (bccomp($value, (string) PHP_INT_MAX, 0) > 0
            || bccomp($value, (string) PHP_INT_MIN, 0) < 0) {
            throw new InvalidArgumentException('Money minor units exceed the native integer range.');
        }

        return (int) $value;
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException('Currencies must match.');
        }
    }

    private static function normalizeCurrency(string $currency): string
    {
        $normalized = strtoupper($currency);
        if (preg_match('/^[A-Z]{3}$/D', $normalized) !== 1) {
            throw new InvalidArgumentException('Currency must be a three-letter ISO code.');
        }

        return $normalized;
    }
}
