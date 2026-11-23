<?php

declare(strict_types=1);

namespace App\Shared\Time;

use App\Shared\Exceptions\ValidationException;
use Carbon\CarbonImmutable;

/**
 * Half-open business date range [from, to). Business code uses this helper
 * rather than SQL DATE() expressions; open end is 9999-12-31.
 */
final readonly class DateRange
{
    private BusinessDate $from;

    private BusinessDate $to;

    public function __construct(BusinessDate|string $validFrom, BusinessDate|string $validTo)
    {
        $this->from = $validFrom instanceof BusinessDate ? $validFrom : BusinessDate::from($validFrom);
        $this->to = $validTo instanceof BusinessDate ? $validTo : BusinessDate::from($validTo);

        if (! $this->from->isBefore($this->to)) {
            throw new ValidationException(['to' => ['The end date must be after the start date (half-open range).']]);
        }
    }

    public static function fromInclusiveDates(string $from, string $to): self
    {
        return new self(BusinessDate::from($from), BusinessDate::from($to)->addDays(1));
    }

    public static function openEnded(BusinessDate|string $validFrom): self
    {
        return new self($validFrom, BusinessDate::OPEN_END);
    }

    public function validFrom(): BusinessDate
    {
        return $this->from;
    }

    public function validTo(): BusinessDate
    {
        return $this->to;
    }

    /**
     * @return list<CarbonImmutable>
     */
    public function days(): array
    {
        $days = [];
        $cursor = BusinessDate::parse($this->from->value());
        $end = BusinessDate::parse($this->to->value());

        while ($cursor->lessThan($end)) {
            $days[] = $cursor;
            $cursor = $cursor->addDay();
        }

        return $days;
    }

    public function contains(BusinessDate|string $on): bool
    {
        $date = $on instanceof BusinessDate ? $on : BusinessDate::from($on);

        return $date->compareTo($this->from) >= 0 && $date->isBefore($this->to);
    }

    public function overlaps(self $other): bool
    {
        return $this->from->isBefore($other->to) && $other->from->isBefore($this->to);
    }

    public function isOpenEnded(): bool
    {
        return $this->to->isOpenEnd();
    }

    public function endingAt(BusinessDate|string $validTo): self
    {
        return new self($this->from, $validTo);
    }

    /**
     * @return array{valid_from: string, valid_to: string}
     */
    public function toArray(): array
    {
        return ['valid_from' => $this->from->value(), 'valid_to' => $this->to->value()];
    }
}
