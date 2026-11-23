<?php

declare(strict_types=1);

namespace App\Shared\Time;

use App\Modules\Organization\Contracts\OrganizationFacts;
use App\Shared\Exceptions\ValidationException;

/**
 * Resolves a tenant's business date through the Organization contract. Shared
 * code may consume a module contract; it never reads module tables.
 */
final class TenantBusinessDate
{
    public function __construct(
        private readonly Clock $clock,
        private readonly OrganizationFacts $organization,
    ) {}

    public function today(int $tenantId): BusinessDate
    {
        return BusinessDate::today($this->organization->defaultTimezone($tenantId), $this->clock);
    }

    public function resolve(?string $value, int $tenantId, string $field = 'as_of'): BusinessDate
    {
        if ($value === null || $value === '') {
            return $this->today($tenantId);
        }

        $date = BusinessDate::tryFrom($value);

        if ($date === null) {
            throw new ValidationException([$field => ["The {$field} field must be a real calendar date in YYYY-MM-DD format."]]);
        }

        return $date;
    }
}
