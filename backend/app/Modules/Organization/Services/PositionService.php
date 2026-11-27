<?php

declare(strict_types=1);

namespace App\Modules\Organization\Services;

use App\Modules\IAM\Contracts\Principal;
use App\Modules\Organization\Models\Position;
use App\Modules\People\Contracts\PositionOccupancyDirectory;
use App\Shared\Authorization\Authorizer;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Observability\AuditRecorder;
use App\Shared\Time\Clock;
use App\Shared\Time\DateRange;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Effective-dated position catalogue. Disabling a position with active
 * incumbents is a business conflict, never a silent deactivation.
 *
 * List reads are scoped with Authorizer::constrain() before filtering,
 * counting and pagination, so neither total nor page rows can leak a position
 * outside the caller's organization grant. Occupancy is a People projection.
 */
final class PositionService
{
    public function __construct(
        private readonly Clock $clock,
        private readonly AuditRecorder $audit,
        private readonly PositionOccupancyDirectory $occupancy,
        private readonly Authorizer $authorizer,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function open(array $input, ?int $actorUserId): Position
    {
        return DB::transaction(function () use ($input, $actorUserId): Position {
            $code = trim((string) $input['code']);

            if (Position::query()->where('code', $code)->exists()) {
                throw new ConflictException(
                    'Position code must be unique within the company.',
                    ['reason' => 'POSITION_CODE_DUPLICATE'],
                );
            }

            $now = $this->clock->now();
            $validFrom = (string) ($input['valid_from'] ?? $now->toDateString());
            $validTo = isset($input['valid_to']) && (string) $input['valid_to'] !== ''
                ? (string) $input['valid_to']
                : DateRange::openEnded($validFrom)->validTo()->value();
            $range = new DateRange($validFrom, $validTo);

            /** @var Position $position */
            $position = Position::query()->create([
                'org_unit_id' => isset($input['org_unit_id']) ? (int) $input['org_unit_id'] : null,
                'code' => $code,
                'title' => trim((string) $input['title']),
                'grade' => isset($input['grade']) && (string) $input['grade'] !== '' ? (string) $input['grade'] : null,
                'headcount_limit' => isset($input['headcount_limit']) ? (int) $input['headcount_limit'] : null,
                'valid_from' => $range->validFrom()->value(),
                'valid_to' => $range->validTo()->value(),
                'recorded_at' => $now,
                'source_type' => 'API',
            ]);

            $this->audit->record(
                actorUserId: $actorUserId,
                action: 'org.position.created',
                aggregateType: 'position',
                aggregateId: $position->id,
                after: $this->auditPayload($position),
            );

            return $position;
        });
    }

    /**
     * Position update is single-row: historical position facts are not
     * rewritten, and the caller cannot move code, organization or validity
     * through this command. The write is audited in the same transaction.
     *
     * @param  array<string, mixed>  $input
     */
    public function revise(int $positionId, array $input, ?int $actorUserId): Position
    {
        return DB::transaction(function () use ($positionId, $input, $actorUserId): Position {
            /** @var Position|null $position */
            $position = Position::query()
                ->whereKey($positionId)
                ->lockForUpdate()
                ->first();

            if ($position === null) {
                throw new NotFoundOrForbiddenException;
            }

            $status = isset($input['status']) ? (string) $input['status'] : null;

            if ($status === 'DISABLED' && (string) $position->status !== 'DISABLED') {
                $occupancy = $this->occupancy->occupancy([$positionId], $this->clock->now()->toDateString());
                if (($occupancy[$positionId] ?? 0) > 0) {
                    throw new ConflictException(
                        'A position with active incumbents cannot be disabled.',
                        ['reason' => 'POSITION_STAFFED', 'position_id' => $positionId],
                    );
                }
            }

            $before = $this->auditPayload($position);
            $changes = [];

            if (array_key_exists('title', $input)) {
                $changes['title'] = trim((string) $input['title']);
            }

            if (array_key_exists('grade', $input)) {
                $changes['grade'] = $input['grade'] !== null && (string) $input['grade'] !== ''
                    ? (string) $input['grade']
                    : null;
            }

            if (array_key_exists('headcount_limit', $input)) {
                $changes['headcount_limit'] = $input['headcount_limit'] !== null
                    ? (int) $input['headcount_limit']
                    : null;
            }

            if ($status !== null) {
                $changes['status'] = $status;
            }

            $position->forceFill($changes)->save();

            $this->audit->record(
                actorUserId: $actorUserId,
                action: $status === 'DISABLED' && (string) $before['status'] !== 'DISABLED'
                    ? 'org.position.disabled'
                    : 'org.position.updated',
                aggregateType: 'position',
                aggregateId: $position->id,
                before: $before,
                after: $this->auditPayload($position),
            );

            return $position;
        });
    }

    /**
     * Return one position only when it is inside the caller's read scope.
     * Out-of-scope ids deliberately render as 404 rather than 403.
     */
    public function findVisible(
        Principal $principal,
        int $positionId,
        string $on,
        string $capability = 'org.position.read',
    ): Position {
        /** @var Position|null $position */
        $position = $this->authorizer->constrain(
            Position::query()->whereKey($positionId),
            $principal,
            $capability,
            $on,
        )->first();

        if ($position === null) {
            throw new NotFoundOrForbiddenException;
        }

        return $position;
    }

    /**
     * @param  array{org_unit_id?: int|null, status?: string|null, q?: string|null, sort?: string|null}  $filters
     * @return array{items: list<array<string, mixed>>, page: int, page_size: int, total: int}
     */
    public function paginate(
        Principal $principal,
        string $on,
        array $filters,
        int $page,
        int $pageSize,
    ): array {
        $query = Position::query()
            ->where('valid_from', '<=', $on)
            ->where('valid_to', '>', $on);

        if (($filters['org_unit_id'] ?? null) !== null) {
            $query->where('org_unit_id', (int) $filters['org_unit_id']);
        }

        if (($filters['status'] ?? null) !== null) {
            $query->where('status', (string) $filters['status']);
        }

        $term = isset($filters['q']) && is_string($filters['q']) ? trim($filters['q']) : '';

        if ($term !== '') {
            $pattern = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
            $query->where(function (Builder $inner) use ($pattern): void {
                $inner->where('code', 'like', $pattern)
                    ->orWhere('title', 'like', $pattern);
            });
        }

        $query = $this->authorizer->constrain($query, $principal, 'org.position.read', $on);

        $total = (int) (clone $query)->count();

        [$column, $direction] = match ((string) ($filters['sort'] ?? 'code')) {
            'title' => ['title', 'asc'],
            'level' => ['grade', 'asc'],
            'code_desc' => ['code', 'desc'],
            default => ['code', 'asc'],
        };

        $positions = $query
            ->orderBy($column, $direction)
            ->orderBy('id')
            ->forPage($page, $pageSize)
            ->get()
            ->all();

        return [
            'items' => $this->payloads($positions, $on),
            'page' => $page,
            'page_size' => $pageSize,
            'total' => $total,
        ];
    }

    /**
     * @param  list<Position>  $positions
     * @return list<array<string, mixed>>
     */
    public function payloads(array $positions, string $on): array
    {
        $ids = array_map(static fn (Position $position): int => (int) $position->id, $positions);
        $occupancy = $this->occupancy->occupancy($ids, $on);

        return array_map(static fn (Position $position): array => [
            'id' => (int) $position->id,
            'org_unit_id' => $position->org_unit_id !== null ? (int) $position->org_unit_id : null,
            'code' => (string) $position->code,
            'title' => (string) $position->title,
            'grade' => $position->grade,
            'headcount_limit' => $position->headcount_limit,
            'occupancy' => $occupancy[(int) $position->id] ?? 0,
            'vacancies' => $position->headcount_limit !== null
                ? max(0, (int) $position->headcount_limit - ($occupancy[(int) $position->id] ?? 0))
                : null,
            'status' => (string) $position->status,
            'valid_from' => (string) $position->valid_from,
            'valid_to' => (string) $position->valid_to,
        ], $positions);
    }

    /**
     * @return array<string, mixed>
     */
    private function auditPayload(Position $position): array
    {
        return [
            'code' => (string) $position->code,
            'title' => (string) $position->title,
            'org_unit_id' => $position->org_unit_id !== null ? (int) $position->org_unit_id : null,
            'grade' => $position->grade !== null ? (string) $position->grade : null,
            'headcount_limit' => $position->headcount_limit !== null ? (int) $position->headcount_limit : null,
            'status' => (string) $position->status,
            'valid_from' => (string) $position->valid_from,
            'valid_to' => (string) $position->valid_to,
        ];
    }
}
