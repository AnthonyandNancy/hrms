<?php

declare(strict_types=1);

namespace App\Shared\Time;

use App\Modules\Organization\Contracts\CompanyProfileReader;
use App\Shared\Exceptions\ValidationException;

/**
 * Resolves the single company's business date through the Organization
 * CompanyProfileReader contract. Shared consumes a module contract; it never
 * reads module tables.
 */
final class CompanyBusinessDate
{
    public function __construct(
        private readonly Clock $clock,
        private readonly CompanyProfileReader $profiles,
    ) {}

    public function today(): BusinessDate
    {
        return BusinessDate::today($this->profiles->current()->timezone, $this->clock);
    }

    public function resolve(?string $value, string $field = 'as_of'): BusinessDate
    {
        if ($value === null || $value === '') {
            return $this->today();
        }

        $date = BusinessDate::tryFrom($value);

        if ($date === null) {
            throw new ValidationException([$field => ["The {$field} field must be a real calendar date in YYYY-MM-DD format."]]);
        }

        return $date;
    }
}
