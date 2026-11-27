<?php

declare(strict_types=1);

namespace App\Modules\IAM\Authorization;

use App\Shared\Authorization\Contracts\SensitiveAccessAuditor;
use App\Shared\Observability\AuditRecorder;
use Illuminate\Support\Facades\Log;

/**
 * Persists sensitive field access evidence without values. A failure here is
 * fatal for the request: sensitive data must not be returned unaudited.
 */
final class PlatformSensitiveAccessAuditor implements SensitiveAccessAuditor
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function record(
        int $userId,
        string $capabilityCode,
        string $resourceType,
        ?int $resourceId = null,
        array $fields = [],
        array $context = [],
    ): void {
        $this->audit->record(
            actorUserId: $userId,
            action: 'sensitive_field_access',
            aggregateType: $resourceType,
            aggregateId: $resourceId ?? 0,
            metadata: [
                'capability' => $capabilityCode,
                'fields' => array_values($fields),
                ...$context,
            ],
        );

        // Log field names only; values are never logged.
        Log::info('sensitive_field_access', [
            'capability' => $capabilityCode,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'fields' => array_values($fields),
        ]);
    }
}
