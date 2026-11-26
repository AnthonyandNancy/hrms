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
     */
    public function __construct(
        public int $userId,
        public ?int $employmentId,
        public string $scope,
        public array $capabilities = [],
        public array $managedEmploymentIds = [],
    ) {}

    public function hasCapability(string $capability): bool
    {
        return in_array($capability, $this->capabilities, true);
    }

    public function canReadEmployment(int $employmentId): bool
    {
        return match ($this->scope) {
            'SELF' => $this->employmentId === $employmentId,
            'MANAGED' => in_array($employmentId, $this->managedEmploymentIds, true),
            // The company is the only data scope left: a company-wide grant
            // sees every employment in it.
            'COMPANY', 'HR', 'ADMIN' => true,
            default => $this->employmentId === $employmentId,
        };
    }
}
