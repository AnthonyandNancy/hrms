<?php

declare(strict_types=1);

namespace App\Shared\Time;

use Carbon\CarbonImmutable;
use App\Shared\Exceptions\ValidationException;

/**
 * Half-open business date range [from, to). All date reasoning in time and
 * leave code uses this helper rather than SQL DATE() expressions.
 */
final readonly class DateRange
{
    public CarbonImmutable $from;

    public CarbonImmutable $to;

    public function __construct(CarbonImmutable $from, CarbonImmutable $to)
    {
        if ($from->greaterThanOrEqualTo($to)) {
            throw new ValidationException(['to' => ['The end date must be after the start date.']]);
        }

        $this->from = $from->startOfDay();
        $this->to = $to->startOfDay();
    }

    public static function fromInclusiveDates(string $from, string $to): self
    {
        $start = BusinessDate::parse($from);
        $end = BusinessDate::parse($to)->addDay();

        return new self($start, $end);
    }

    /**
     * @return list<CarbonImmutable>
     */
    public function days(): array
    {
        $days = [];
        $cursor = $this->from;

        while ($cursor->lessThan($this->to)) {
            $days[] = $cursor;
            $cursor = $cursor->addDay();
        }

        return $days;
    }

    public function contains(CarbonImmutable|string $date): bool
    {
        $day = is_string($date) ? BusinessDate::parse($date) : $date->startOfDay();

        return $day->greaterThanOrEqualTo($this->from) && $day->lessThan($this->to);
    }
}
