<?php

declare(strict_types=1);

namespace App\Modules\IAM\Authorization;

use App\Modules\Platform\Models\AuditEvent;
use App\Shared\Authorization\Contracts\SensitiveAccessAuditor;
use App\Shared\RequestContext;
use App\Shared\Time\Clock;
use Illuminate\Support\Facades\Log;

/**
 * Persists sensitive field access evidence without values. A failure here is
 * fatal for the request: sensitive data must not be returned unaudited.
 */
final class PlatformSensitiveAccessAuditor implements SensitiveAccessAuditor
{
    public function __construct(private readonly Clock $clock) {}

    public function record(
        int $tenantId,
        int $userId,
        string $resourceType,
        string $resourceId,
        string $capability,
        array $fields,
        array $context = [],
    ): void {
        AuditEvent::query()->create([
            'tenant_id' => $tenantId,
            'actor_user_id' => $userId,
            'actor_type' => 'USER',
            'action' => 'sensitive_field_access',
            'aggregate_type' => $resourceType,
            'aggregate_id' => $resourceId,
            'metadata_json' => [
                'capability' => $capability,
                'fields' => array_values($fields),
                ...$context,
            ],
            'request_id' => RequestContext::requestId(),
            'occurred_at' => $this->clock->now(),
        ]);

        // Log field names only; values are never logged.
        Log::info('sensitive_field_access', [
            'capability' => $capability,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'fields' => array_values($fields),
        ]);
    }
}
