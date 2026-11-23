<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Shared\Authorization\Contracts\SensitiveAccessAuditor;

final class SpySensitiveAccessAuditor implements SensitiveAccessAuditor
{
    /** @var list<array<string, mixed>> */
    public array $records = [];

    public function record(
        int $tenantId,
        int $userId,
        string $resourceType,
        string $resourceId,
        string $capability,
        array $fields,
        array $context = [],
    ): void {
        $this->records[] = [
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'capability' => $capability,
            'fields' => $fields,
            'context' => $context,
        ];
    }
}
