<?php

declare(strict_types=1);

namespace App\Modules\Organization\Services;

use App\Modules\Organization\Models\LegalEntity;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Time\BusinessDate;
use App\Shared\Time\Clock;

/**
 * Resolves a tenant's default business date from its legal-entity timezone and
 * validates explicit as_of inputs as real calendar dates.
 */
final class TenantBusinessDate
{
    public function __construct(private readonly Clock $clock) {}

    public function today(int $tenantId): BusinessDate
    {
        $timezone = LegalEntity::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('id')
            ->value('timezone');

        return BusinessDate::today(is_string($timezone) ? $timezone : (string) config('hrms.default_timezone', 'Asia/Shanghai'), $this->clock);
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
