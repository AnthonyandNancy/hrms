<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Services;

use App\Modules\Engagement\Contracts\NoticeWorkspaceDirectory;
use App\Modules\Engagement\Models\Notice;
use App\Modules\Engagement\Models\NoticeAcknowledgement;
use App\Shared\Time\Clock;

/**
 * Bounded, self-scoped notice projection. Audience authorisation is still
 * enforced by the full notice list/show endpoints; this port only supplies the
 * workspace teaser, limited to company-wide and role-wide notices for the
 * caller plus anything already acknowledged.
 */
final class EloquentNoticeWorkspaceDirectory implements NoticeWorkspaceDirectory
{
    public function __construct(private readonly Clock $clock) {}

    /**
     * @return list<array{id: int, title: string, publishedAt: string|null, requiresAck: bool, acknowledged: bool, authorName: string|null}>
     */
    public function forUser(int $userId, int $limit = 5): array
    {
        $acknowledgedIds = NoticeAcknowledgement::query()
            ->where('user_id', $userId)
            ->pluck('notice_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $notices = Notice::query()
            ->where('status', Notice::STATUS_PUBLISHED)
            ->where('publish_at', '<=', $this->clock->now())
            ->where(function ($query) use ($acknowledgedIds): void {
                $query->where('audience_json->type', 'ALL')
                    ->orWhereIn('id', $acknowledgedIds === [] ? [-1] : $acknowledgedIds);
            })
            ->orderByDesc('publish_at')
            ->orderByDesc('id')
            ->limit(max(1, min(20, $limit)))
            ->get();

        return $notices->map(fn (Notice $notice): array => [
            'id' => (int) $notice->id,
            'title' => (string) $notice->title,
            'publishedAt' => $notice->publish_at?->toIso8601ZuluString(),
            'requiresAck' => (bool) $notice->requires_ack,
            'acknowledged' => in_array((int) $notice->id, $acknowledgedIds, true),
            'authorName' => null,
        ])->all();
    }
}
