<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Services;

use App\Modules\Engagement\Models\Notice;
use App\Modules\Engagement\Models\NoticeAcknowledgement;
use App\Modules\Engagement\Models\NoticeAudienceSnapshot;
use App\Modules\IAM\Contracts\Principal;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Observability\AuditRecorder;
use App\Shared\Time\Clock;
use App\Shared\Time\CompanyBusinessDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class NoticeService
{
    public function __construct(
        private readonly NoticeAudienceService $audience,
        private readonly NoticeAccessPolicy $policy,
        private readonly NotificationService $notifications,
        private readonly CompanyBusinessDate $businessDates,
        private readonly Clock $clock,
        private readonly ?AuditRecorder $audit = null,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function create(Principal $principal, array $input, ?string $businessDate = null): array
    {
        $today = $this->businessDates->resolve($businessDate)->value();
        $resolved = $this->audience->resolve($input, $today);

        if (! $this->policy->canManageAudience($principal, $resolved['audience_json'], $today)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }

        $publishAt = $this->instant((string) ($input['publishAt'] ?? $input['publish_at'] ?? $this->clock->now()->toIso8601ZuluString()));
        $title = trim((string) ($input['title'] ?? ''));
        $body = trim((string) ($input['body'] ?? ''));
        $approvalRequired = (bool) ($input['approval_required'] ?? $input['approvalRequired'] ?? false);

        if ($title === '' || $body === '') {
            throw new ValidationException(
                ['title' => ['Title and body are required.']],
                'The given data was invalid.',
                ['reason' => 'NOTICE_CONTENT_REQUIRED'],
            );
        }

        $notice = DB::transaction(function () use ($principal, $resolved, $publishAt, $title, $body, $input, $approvalRequired): Notice {
            $notice = Notice::query()->create([
                'title' => mb_substr($title, 0, 200),
                'body' => $body,
                'audience_json' => $resolved['audience_json'],
                'publish_at' => $publishAt,
                'retract_at' => null,
                'created_by' => $principal->userId(),
                'status' => $approvalRequired ? Notice::STATUS_DRAFT : Notice::STATUS_PUBLISHED,
                'requires_ack' => (bool) ($input['requiresAck'] ?? $input['requires_ack'] ?? false),
                'audience_count' => $resolved['audience_count'],
                'template_code' => isset($input['template_code']) ? (string) $input['template_code'] : null,
            ]);

            if (! $approvalRequired) {
                $publishedAt = $notice->publish_at ?? $this->clock->now();
                foreach (array_values(array_unique($resolved['recipients'])) as $userId) {
                    NoticeAudienceSnapshot::query()->create([
                        'notice_id' => (int) $notice->id,
                        'user_id' => (int) $userId,
                        'recorded_at' => $publishedAt,
                    ]);
                }

                $this->notifications->notify(
                    userIds: $resolved['recipients'],
                    type: 'NOTICE_PUBLISHED',
                    payload: [
                        'notice_id' => (int) $notice->id,
                        'requires_ack' => (bool) $notice->requires_ack,
                    ],
                );
            }

            $this->audit()->record(
                actorUserId: $principal->userId(),
                action: 'engagement.notice.created',
                aggregateType: 'notice',
                aggregateId: (int) $notice->id,
                after: [
                    'status' => (string) $notice->status,
                    'audience_type' => (string) $resolved['audience_json']['type'],
                    'audience_count' => (int) $resolved['audience_count'],
                ],
            );

            return $notice;
        });

        return $this->payload($principal, $notice, $today);
    }

    /**
     * Resolve a proposed audience before the notice exists. This is a read-only
     * preview: it returns counts and labels, never the recipient user IDs.
     *
     * @param  array<string, mixed>  $input
     * @return array{audienceType: string, audienceLabel: string, orgUnitIds: list<int>, roleCodes: list<string>, recipientCount: int, resolvedOn: string}
     */
    public function previewAudience(Principal $principal, array $input, ?string $businessDate = null): array
    {
        $today = $this->businessDates->resolve($businessDate)->value();
        $resolved = $this->audience->resolve($input, $today);

        if (! $this->policy->canManageAudience($principal, $resolved['audience_json'], $today)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Forbidden.');
        }

        return [
            'audienceType' => (string) $resolved['audience_json']['type'],
            'audienceLabel' => (string) $resolved['audience_json']['label'],
            'orgUnitIds' => array_values(array_map('intval', (array) $resolved['audience_json']['org_unit_ids'])),
            'roleCodes' => array_values(array_map('strval', (array) $resolved['audience_json']['role_codes'])),
            'recipientCount' => (int) $resolved['audience_count'],
            'resolvedOn' => $today,
        ];
    }

    /**
     * @param  array{filter?: string|null}  $filters
     * @return list<array<string, mixed>>
     */
    public function list(Principal $principal, array $filters): array
    {
        $today = $this->businessDates->today()->value();
        $filter = (string) ($filters['filter'] ?? 'all');

        if (! in_array($filter, ['all', 'unread', 'acknowledged', 'managed'], true)) {
            throw new ValidationException(
                ['filter' => ['Filter must be all, unread, acknowledged or managed.']],
                'The given data was invalid.',
                ['reason' => 'INVALID_NOTICE_FILTER'],
            );
        }

        $query = Notice::query()
            ->when($filter !== 'managed', fn ($builder) => $builder
                ->where('status', Notice::STATUS_PUBLISHED)
                ->where('publish_at', '<=', $this->clock->now()))
            ->when($filter === 'managed', fn ($builder) => $builder->whereIn('status', [
                Notice::STATUS_DRAFT,
                Notice::STATUS_PENDING_APPROVAL,
                Notice::STATUS_APPROVED,
                Notice::STATUS_REJECTED,
                Notice::STATUS_PUBLISHED,
                Notice::STATUS_RETRACTED,
                // Managers must still see archived notices: restore is an
                // authoring action on exactly those rows (V3-B5).
                Notice::STATUS_ARCHIVED,
            ]))
            ->orderByDesc('publish_at')
            ->orderByDesc('id');

        // Route-level permits() already established feature access. Row scope
        // is the audience policy below; constrain() would incorrectly narrow
        // an ALL-audience notice to the caller's own employment row.

        $acknowledgedIds = NoticeAcknowledgement::query()
            ->where('user_id', $principal->userId())
            ->pluck('notice_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        if ($filter === 'acknowledged') {
            $query->whereIn('id', $acknowledgedIds === [] ? [-1] : $acknowledgedIds);
        } elseif ($filter === 'unread') {
            $query->whereNotIn('id', $acknowledgedIds === [] ? [-1] : $acknowledgedIds);
        }

        $items = [];

        foreach ($query->get() as $notice) {
            /** @var Notice $notice */
            if ($this->policy->canRead($principal, $notice, $today)) {
                $items[] = $this->payload($principal, $notice, $today);
            }
        }

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    public function show(Principal $principal, int $noticeId): array
    {
        $today = $this->businessDates->today()->value();

        return $this->payload($principal, $this->findVisible($principal, $noticeId, $today), $today);
    }

    /**
     * @return array<string, mixed>
     */
    public function acknowledge(Principal $principal, int $noticeId): array
    {
        $today = $this->businessDates->today()->value();
        $notice = $this->findVisible($principal, $noticeId, $today);

        if (! $notice->requires_ack) {
            throw new ConflictException('This notice does not require acknowledgement.', ['reason' => 'ACK_NOT_REQUIRED']);
        }

        $acknowledgement = NoticeAcknowledgement::query()->firstOrCreate(
            ['notice_id' => (int) $notice->id, 'user_id' => $principal->userId()],
            [
                'acknowledged_at' => $this->clock->now(),
            ],
        );

        $this->audit()->record(
            actorUserId: $principal->userId(),
            action: 'engagement.notice.acknowledged',
            aggregateType: 'notice',
            aggregateId: (int) $notice->id,
            metadata: ['acknowledgement_id' => (int) $acknowledgement->id],
        );

        return $this->payload($principal, $notice->refresh(), $today);
    }

    /**
     * @return array<string, mixed>
     */
    public function retract(Principal $principal, int $noticeId): array
    {
        $today = $this->businessDates->today()->value();
        $notice = $this->findVisible($principal, $noticeId, $today);

        if (! $this->policy->canManage($principal, $notice, $today)) {
            throw new NotFoundOrForbiddenException;
        }

        if ($notice->status !== Notice::STATUS_RETRACTED) {
            $previous = (string) $notice->status;
            $notice->forceFill([
                'status' => Notice::STATUS_RETRACTED,
                'retract_at' => $this->clock->now(),
            ])->save();
            $notice->refresh();

            $this->audit()->record(
                actorUserId: $principal->userId(),
                action: 'engagement.notice.retracted',
                aggregateType: 'notice',
                aggregateId: (int) $notice->id,
                before: ['status' => $previous],
                after: ['status' => Notice::STATUS_RETRACTED],
            );
        }

        return $this->payload($principal, $notice, $today);
    }

    public function findVisible(Principal $principal, int $noticeId, string $today): Notice
    {
        $notice = Notice::query()
            ->whereKey($noticeId)
            ->first();

        if ($notice === null || ! $this->policy->canRead($principal, $notice, $today)) {
            throw new NotFoundOrForbiddenException;
        }

        return $notice;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(Principal $principal, Notice $notice, string $today): array
    {
        $acknowledgementCount = NoticeAcknowledgement::query()
            ->where('notice_id', $notice->id)
            ->count();
        $acknowledged = NoticeAcknowledgement::query()
            ->where('notice_id', $notice->id)
            ->where('user_id', $principal->userId())
            ->exists();
        $audience = (array) $notice->audience_json;

        return [
            'id' => (int) $notice->id,
            'title' => (string) $notice->title,
            'summary' => mb_substr(trim(preg_replace('/\s+/', ' ', (string) $notice->body) ?? ''), 0, 120),
            'body' => array_values(array_filter(array_map('trim', preg_split('/\R/u', (string) $notice->body) ?: []))),
            'status' => (string) $notice->status,
            'audience' => (string) ($audience['type'] ?? 'ALL'),
            'audienceLabel' => (string) ($audience['label'] ?? ''),
            'orgUnitIds' => array_values(array_map('intval', (array) ($audience['org_unit_ids'] ?? []))),
            'roleCodes' => array_values(array_map('strval', (array) ($audience['role_codes'] ?? []))),
            'publishedAt' => $notice->publish_at?->toIso8601ZuluString(),
            'retractedAt' => $notice->retract_at?->toIso8601ZuluString(),
            'authorName' => null,
            'requiresAck' => (bool) $notice->requires_ack,
            'acknowledged' => $acknowledged,
            'acknowledgementCount' => $acknowledgementCount,
            'audienceCount' => (int) $notice->audience_count,
            'approvalCaseId' => $notice->approval_case_id !== null ? (int) $notice->approval_case_id : null,
            'approval_case_id' => $notice->approval_case_id !== null ? (int) $notice->approval_case_id : null,
            'approvedAt' => $notice->approved_at?->toIso8601ZuluString(),
            'archivedAt' => $notice->archived_at?->toIso8601ZuluString(),
            'templateCode' => $notice->template_code !== null ? (string) $notice->template_code : null,
            'templateVersion' => $notice->template_version !== null ? (int) $notice->template_version : null,
        ];
    }

    private function audit(): AuditRecorder
    {
        return $this->audit ?? app(AuditRecorder::class);
    }

    private function instant(string $value): CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($value, 'UTC')->utc();
        } catch (\Throwable) {
            throw new ValidationException(
                ['publishAt' => ['publishAt must be a valid ISO-8601 timestamp.']],
                'The given data was invalid.',
                ['reason' => 'INVALID_PUBLISH_AT'],
            );
        }
    }
}
