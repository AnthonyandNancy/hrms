<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Contracts;

/**
 * Self-scoped notice projection for the personal workspace. IAM never reads
 * Engagement tables; it resolves unread acknowledgements through this port.
 */
interface NoticeWorkspaceDirectory
{
    /**
     * @return list<array{id: int, title: string, publishedAt: string|null, requiresAck: bool, acknowledged: bool, authorName: string|null}>
     */
    public function forUser(int $tenantId, int $userId, int $limit = 5): array;
}
