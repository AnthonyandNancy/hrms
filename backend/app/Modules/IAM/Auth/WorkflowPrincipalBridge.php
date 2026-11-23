<?php

declare(strict_types=1);

namespace App\Modules\IAM\Auth;

use App\Modules\IAM\Contracts\PrincipalAuthorityReader;
use App\Modules\Workflow\Contracts\WorkflowPrincipal;
use App\Modules\Workflow\Contracts\WorkflowPrincipalResolver;
use App\Shared\Time\BusinessDate;
use App\Shared\Time\Clock;

/**
 * IAM-owned bridge to Workflow's narrow principal contract.
 */
final class WorkflowPrincipalBridge implements WorkflowPrincipalResolver
{
    public function __construct(
        private readonly AuthenticationContext $context,
        private readonly PrincipalAuthorityReader $authorities,
        private readonly Clock $clock,
    ) {}

    public function resolve(): ?WorkflowPrincipal
    {
        $principal = $this->context->principalOrNull();

        if ($principal === null) {
            return null;
        }

        $businessDate = BusinessDate::today(
            (string) config('hrms.default_timezone', 'Asia/Shanghai'),
            $this->clock,
        )->value();
        $grants = $this->authorities->grants($principal, $businessDate);

        return new WorkflowPrincipal(
            tenantId: $principal->tenantId(),
            userId: $principal->userId(),
            employmentId: $principal->employmentId(),
            authVersion: $principal->authVersion(),
            isPrivileged: $principal->isPrivileged(),
            capabilities: array_values(array_unique(array_column($grants, 'capability'))),
        );
    }
}
