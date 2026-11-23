<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Contracts;

/**
 * Cross-module case command/query port. Lifecycle and Talent create approval
 * cases without importing Workflow models, services or tables.
 */
interface WorkflowCaseGateway
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function createCase(
        int $tenantId,
        int $requesterUserId,
        string $type,
        string $subjectType,
        ?int $subjectId,
        string $title,
        array $payload,
        string $templateCode,
        string $businessDate,
        bool $submit,
    ): WorkflowCaseRecord;

    /**
     * Create or reuse a published, code-owned template version.
     *
     * @param  array<string, mixed>  $definition
     */
    public function ensureTemplate(int $tenantId, string $code, string $name, array $definition): int;

    public function caseForTenant(int $tenantId, int $caseId): ?WorkflowCaseRecord;

    /**
     * Domain projection for a case. Lifecycle applies its own capability and
     * anchor checks before calling this method.
     *
     * @return array<string, mixed>|null
     */
    public function projection(int $tenantId, int $caseId): ?array;
}
