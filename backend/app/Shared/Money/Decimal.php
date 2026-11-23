<?php

declare(strict_types=1);

namespace App\Shared\Money;

use InvalidArgumentException;

/**
 * Immutable arbitrary-precision decimal backed by an integer unscaled value and
 * an explicit scale. Integer arithmetic and bcmath are used exclusively; no
 * binary floating point value is ever constructed.
 */
final readonly class Decimal
{
    private const MAX_INPUT_INTEGER_DIGITS = 18;

    private function __construct(
        private int $sign,
        private string $unscaled,
        private int $scale,
    ) {}

    public static function fromString(string $value): self
    {
        if (preg_match('/^(-)?(\d+)(?:\.(\d+))?$/D', $value, $matches) !== 1) {
            throw new InvalidArgumentException('Decimal must be a plain decimal string.');
        }

        $integerDigits = ltrim($matches[2], '0');
        if ($integerDigits === '') {
            $integerDigits = '0';
        }

        if (strlen($integerDigits) > self::MAX_INPUT_INTEGER_DIGITS) {
            throw new InvalidArgumentException('Decimal integer part exceeds 18 digits.');
        }

        $fractionDigits = $matches[3] ?? '';
        $unscaled = self::normalizeUnsigned($integerDigits.$fractionDigits);

        return new self(
            $unscaled === '0' ? 0 : ($matches[1] === '-' ? -1 : 1),
            $unscaled,
            strlen($fractionDigits),
        );
    }

    public static function fromScaledInt(int $scaled, int $scale): self
    {
        self::assertScale($scale);

        $digits = (string) $scaled;
        $sign = 1;
        if (str_starts_with($digits, '-')) {
            $sign = -1;
            $digits = substr($digits, 1);
        }

        $digits = self::normalizeUnsigned($digits);

        return new self($digits === '0' ? 0 : $sign, $digits, $scale);
    }

    public function add(self $other): self
    {
        $scale = max($this->scale, $other->scale);
        $result = bcadd(
            $this->signedUnscaledAt($scale),
            $other->signedUnscaledAt($scale),
            0,
        );

        return self::fromSignedUnscaled($result, $scale);
    }

    public function subtract(self $other): self
    {
        return $this->add($other->negate());
    }

    public function multiply(self $other): self
    {
        $unscaled = bcmul($this->unscaled, $other->unscaled, 0);
        $sign = $this->sign * $other->sign;

        return self::fromSignedUnscaled(
            $sign < 0 ? '-'.$unscaled : $unscaled,
            $this->scale + $other->scale,
        );
    }

    public function divide(self $divisor, int $scale, RoundingMode $mode = RoundingMode::HALF_UP): self
    {
        self::assertScale($scale);

        if ($divisor->isZero()) {
            throw new InvalidArgumentException('Division by zero is not allowed.');
        }

        $exponent = $scale + $divisor->scale - $this->scale;
        $numerator = $this->unscaled;
        $denominator = $divisor->unscaled;

        if ($exponent >= 0) {
            $numerator .= str_repeat('0', $exponent);
        } else {
            $denominator .= str_repeat('0', -$exponent);
        }

        $quotient = bcdiv($numerator, $denominator, 0);
        $remainder = bcmod($numerator, $denominator, 0);

        if ($this->shouldRoundUp($quotient, $remainder, $denominator, $mode)) {
            $quotient = bcadd($quotient, '1', 0);
        }

        $sign = $this->sign * $divisor->sign;

        return self::fromSignedUnscaled($sign < 0 ? '-'.$quotient : $quotient, $scale);
    }

    public function compare(self $other): int
    {
        $scale = max($this->scale, $other->scale);

        return bccomp(
            $this->signedUnscaledAt($scale),
            $other->signedUnscaledAt($scale),
            0,
        );
    }

    public function equals(self $other): bool
    {
        return $this->compare($other) === 0;
    }

    public function roundTo(int $scale, RoundingMode $mode = RoundingMode::HALF_UP): self
    {
        self::assertScale($scale);

        if ($scale >= $this->scale) {
            $unscaled = $this->unscaled.str_repeat('0', $scale - $this->scale);

            return self::fromSignedUnscaled($this->sign < 0 ? '-'.$unscaled : $unscaled, $scale);
        }

        $divisor = '1'.str_repeat('0', $this->scale - $scale);
        $quotient = bcdiv($this->unscaled, $divisor, 0);
        $remainder = bcmod($this->unscaled, $divisor, 0);

        if ($this->shouldRoundUp($quotient, $remainder, $divisor, $mode)) {
            $quotient = bcadd($quotient, '1', 0);
        }

        return self::fromSignedUnscaled(
            $this->sign < 0 ? '-'.$quotient : $quotient,
            $scale,
        );
    }

    public function toScaledInt(int $scale, RoundingMode $mode = RoundingMode::HALF_UP): int
    {
        $rounded = $this->roundTo($scale, $mode);
        $signed = $rounded->sign < 0 ? '-'.$rounded->unscaled : $rounded->unscaled;

        if (bccomp($signed, (string) PHP_INT_MAX, 0) > 0
            || bccomp($signed, (string) PHP_INT_MIN, 0) < 0) {
            throw new InvalidArgumentException('Decimal does not fit in a native integer.');
        }

        return (int) $signed;
    }

    public function toDecimalString(): string
    {
        $prefix = $this->sign < 0 ? '-' : '';

        if ($this->scale === 0) {
            return $prefix.$this->unscaled;
        }

        $digits = str_pad($this->unscaled, $this->scale + 1, '0', STR_PAD_LEFT);
        $split = strlen($digits) - $this->scale;

        return $prefix.substr($digits, 0, $split).'.'.substr($digits, $split);
    }

    public function negate(): self
    {
        if ($this->sign === 0) {
            return $this;
        }

        return new self(-$this->sign, $this->unscaled, $this->scale);
    }

    public function absolute(): self
    {
        if ($this->sign >= 0) {
            return $this;
        }

        return new self(1, $this->unscaled, $this->scale);
    }

    public function isZero(): bool
    {
        return $this->sign === 0;
    }

    public function scale(): int
    {
        return $this->scale;
    }

    private function signedUnscaledAt(int $scale): string
    {
        $digits = $this->unscaled.str_repeat('0', $scale - $this->scale);

        return $this->sign < 0 ? '-'.$digits : $digits;
    }

    private function shouldRoundUp(
        string $quotient,
        string $remainder,
        string $divisor,
        RoundingMode $mode,
    ): bool {
        if (bccomp($remainder, '0', 0) === 0) {
            return false;
        }

        $twiceRemainder = bcmul($remainder, '2', 0);
        $comparison = bccomp($twiceRemainder, $divisor, 0);

        return match ($mode) {
            RoundingMode::DOWN => false,
            RoundingMode::UP => true,
            RoundingMode::HALF_UP => $comparison >= 0,
            RoundingMode::HALF_EVEN => $comparison > 0
                || ($comparison === 0 && bcmod($quotient, '2', 0) !== '0'),
        };
    }

    private static function fromSignedUnscaled(string $signed, int $scale): self
    {
        self::assertScale($scale);

        $sign = 1;
        if (str_starts_with($signed, '-')) {
            $sign = -1;
            $signed = substr($signed, 1);
        }

        $unscaled = self::normalizeUnsigned($signed);

        return new self($unscaled === '0' ? 0 : $sign, $unscaled, $scale);
    }

    private static function normalizeUnsigned(string $digits): string
    {
        $normalized = ltrim($digits, '0');

        return $normalized === '' ? '0' : $normalized;
    }

    private static function assertScale(int $scale): void
    {
        if ($scale < 0) {
            throw new InvalidArgumentException('Decimal scale cannot be negative.');
        }
    }
}
