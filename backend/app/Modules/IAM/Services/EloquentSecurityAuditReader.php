<?php

declare(strict_types=1);

namespace App\Modules\IAM\Services;

use App\Modules\IAM\Contracts\SecurityAuditReader;
use App\Modules\IAM\Models\AuthEvent;
use App\Shared\Exceptions\ValidationException;
use Illuminate\Database\Eloquent\Builder;

/**
 * IAM-owned projection over append-only security events. Platform consumes the
 * contract; no other module reads the IAM model directly.
 */
final class EloquentSecurityAuditReader implements SecurityAuditReader
{
    public const EXPORT_FIELDS = ['event_type', 'result', 'user_id', 'request_id', 'occurred_at'];

    public function page(array $filters): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $pageSize = min(100, max(1, (int) ($filters['page_size'] ?? 20)));

        $query = $this->filtered($filters);
        $total = (int) (clone $query)->count();

        $items = $query
            ->orderByDesc('id')
            ->forPage($page, $pageSize)
            ->get()
            ->map(static fn (AuthEvent $event): array => self::project($event))
            ->all();

        return ['items' => $items, 'page' => $page, 'page_size' => $pageSize, 'total' => $total];
    }

    public function export(array $fields, array $filters): array
    {
        $unknown = array_values(array_diff($fields, self::EXPORT_FIELDS));

        if ($fields === [] || $unknown !== []) {
            throw new ValidationException(
                ['fields' => ['Only the documented audit export fields may be exported.']],
                'The requested export field is not allowed.',
                ['reason' => 'EXPORT_FIELD_NOT_ALLOWED', 'requested' => $unknown],
            );
        }

        $rows = $this->filtered($filters)
            ->orderByDesc('id')
            ->limit(5000)
            ->get()
            ->map(static function (AuthEvent $event) use ($fields): array {
                $projected = self::project($event);

                return array_intersect_key($projected, array_flip($fields));
            })
            ->all();

        return ['fields' => array_values($fields), 'rows' => $rows];
    }

    public function exportFields(): array
    {
        return self::EXPORT_FIELDS;
    }

    /**
     * @param  array{actor?: string|null, action?: string|null, result?: string|null, from?: string|null, to?: string|null}  $filters
     * @return Builder<AuthEvent>
     */
    private function filtered(array $filters): Builder
    {
        $query = AuthEvent::query();

        if (($filters['actor'] ?? null) !== null && $filters['actor'] !== '') {
            $query->where('user_id', (int) $filters['actor']);
        }
        if (($filters['action'] ?? null) !== null && $filters['action'] !== '') {
            $query->where('event_type', 'like', '%'.$filters['action'].'%');
        }
        if (($filters['result'] ?? null) !== null && $filters['result'] !== '') {
            $query->where('result', (string) $filters['result']);
        }
        if (($filters['from'] ?? null) !== null && $filters['from'] !== '') {
            $query->where('occurred_at', '>=', $filters['from'].' 00:00:00');
        }
        if (($filters['to'] ?? null) !== null && $filters['to'] !== '') {
            $query->where('occurred_at', '<=', $filters['to'].' 23:59:59');
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    private static function project(AuthEvent $event): array
    {
        return [
            'id' => (int) $event->id,
            'user_id' => $event->user_id,
            'event_type' => (string) $event->event_type,
            'result' => (string) $event->result,
            'request_id' => $event->request_id,
            'occurred_at' => $event->occurred_at->toIso8601ZuluString(),
        ];
    }
}
