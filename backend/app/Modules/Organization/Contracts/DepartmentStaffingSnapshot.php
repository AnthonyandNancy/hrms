<?php

declare(strict_types=1);

namespace App\Modules\Organization\Contracts;

/**
 * Read projection for one department's staffing status on a business date.
 *
 * `headcount_limit` comes from the append-only Organization-owned headcount
 * version ledger. `staffed` is a live People-owned assignment projection and
 * is never persisted into the headcount ledger.
 */
final readonly class DepartmentStaffingSnapshot
{
    public function __construct(
        public int $orgUnitId,
        public string $code,
        public string $name,
        public ?int $headcountLimit,
        public int $staffed,
        public ?int $vacancies,
        public bool $overstaffed,
        public ?int $version,
        public ?string $effectiveFrom,
    ) {}

    /**
     * @return array{
     *     org_unit_id: int,
     *     code: string,
     *     name: string,
     *     headcount_limit: int|null,
     *     staffed: int,
     *     vacancies: int|null,
     *     overstaffed: bool,
     *     version: int|null,
     *     effective_from: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'org_unit_id' => $this->orgUnitId,
            'code' => $this->code,
            'name' => $this->name,
            'headcount_limit' => $this->headcountLimit,
            'staffed' => $this->staffed,
            'vacancies' => $this->vacancies,
            'overstaffed' => $this->overstaffed,
            'version' => $this->version,
            'effective_from' => $this->effectiveFrom,
        ];
    }
}
