<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final readonly class PayrollPeriod
{
    public function __construct(
        public int $year,
        public int $month,
    ) {
        if ($year < 1900 || $year > 9999) {
            throw new InvalidArgumentException('Payroll year must be between 1900 and 9999.');
        }

        if ($month < 1 || $month > 12) {
            throw new InvalidArgumentException('Payroll month must be between 1 and 12.');
        }
    }

    public static function fromLabel(string $label): self
    {
        if (preg_match('/^(\d{4})-(\d{2})$/D', $label, $matches) !== 1) {
            throw new InvalidArgumentException('Payroll period must use YYYY-MM.');
        }

        return new self((int) $matches[1], (int) $matches[2]);
    }

    public function start(): DateTimeImmutable
    {
        return new DateTimeImmutable(
            sprintf('%04d-%02d-01 00:00:00', $this->year, $this->month),
            new DateTimeZone('UTC'),
        );
    }

    public function end(): DateTimeImmutable
    {
        return $this->start()->modify('last day of this month');
    }

    public function daysInMonth(): int
    {
        return (int) $this->end()->format('j');
    }

    public function label(): string
    {
        return sprintf('%04d-%02d', $this->year, $this->month);
    }
}
