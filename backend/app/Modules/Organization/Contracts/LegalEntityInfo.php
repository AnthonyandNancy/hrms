<?php

declare(strict_types=1);

namespace App\Modules\Organization\Contracts;

/**
 * Tenant-scoped legal-entity projection exposed to other modules without
 * leaking Organization models.
 */
final readonly class LegalEntityInfo
{
    public function __construct(
        public int $id,
        public int $tenantId,
        public string $code,
        public string $name,
        public string $countryCode,
        public string $timezone,
        public string $currencyCode,
        public string $status,
    ) {}
}
