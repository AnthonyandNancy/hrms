<?php

declare(strict_types=1);

namespace App\Modules\Organization\Contracts;

final readonly class CompanyProfileInfo
{
    public function __construct(
        public string $name,
        public string $countryCode,
        public string $timezone,
        public string $currencyCode,
        /** @var array<string, mixed>|null */
        public ?array $calendarPolicy = null,
    ) {}
}
