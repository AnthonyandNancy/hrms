<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Shared\Authorization\Contracts\SensitiveAccessAuditor;

final class SpySensitiveAccessAuditor implements SensitiveAccessAuditor
{
    /** @var list<array<string, mixed>> */
    public array $records = [];

    public function record(
        int $userId,
        string $capabilityCode,
        string $resourceType,
        ?int $resourceId = null,
        array $fields = [],
        array $context = [],
    ): void {
        $this->records[] = [
            'user_id' => $userId,
            'capability' => $capabilityCode,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'fields' => $fields,
            'context' => $context,
        ];
    }
}
