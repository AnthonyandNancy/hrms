<?php

declare(strict_types=1);

namespace App\Modules\Time\Contracts;

/**
 * Actor resolved from the authenticated principal. Time does not import IAM
 * models; the HTTP adapter maps the IAM principal into this narrow view.
 */
final readonly class TimeActor
{
    /**
     * @param  list<string>  $capabilities
     * @param  list<int>  $managedEmploymentIds
     * @param  list<int>  $legalEntityIds
     */
    public function __construct(
        public int $userId,
        public int $tenantId,
        public ?int $employmentId,
        public string $scope,
        public array $capabilities = [],
        public array $managedEmploymentIds = [],
        public array $legalEntityIds = [],
    ) {}

    public function hasCapability(string $capability): bool
    {
        return in_array($capability, $this->capabilities, true);
    }

    public function canReadEmployment(int $employmentId, ?int $legalEntityId = null): bool
    {
        return match ($this->scope) {
            'SELF' => $this->employmentId === $employmentId,
            'MANAGED' => in_array($employmentId, $this->managedEmploymentIds, true),
            'COMPANY', 'HR', 'ADMIN' => $legalEntityId !== null
                ? in_array($legalEntityId, $this->legalEntityIds, true)
                : $this->employmentId !== null && $this->legalEntityIds !== [],
            default => $this->employmentId === $employmentId,
        };
    }
}
