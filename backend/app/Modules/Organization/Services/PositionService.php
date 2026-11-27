<?php

declare(strict_types=1);

namespace App\Modules\Organization\Services;

use App\Modules\Organization\Models\Position;
use App\Modules\People\Contracts\PositionOccupancyDirectory;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Observability\AuditRecorder;
use App\Shared\Time\Clock;
use Illuminate\Support\Facades\DB;

/**
 * Effective-dated position catalogue. Disabling a position with active
 * incumbents is a business conflict, never a silent deactivation.
 */
final class PositionService
{
    public function __construct(
        private readonly Clock $clock,
        private readonly AuditRecorder $audit,
        private readonly PositionOccupancyDirectory $occupancy,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function open(array $input, ?int $actorUserId): Position
    {
        return DB::transaction(function () use ($input, $actorUserId): Position {
            $now = $this->clock->now();
            $validFrom = (string) ($input['valid_from'] ?? $now->toDateString());

            /** @var Position $position */
            $position = Position::query()->create([
                'org_unit_id' => isset($input['org_unit_id']) ? (int) $input['org_unit_id'] : null,
                'code' => (string) $input['code'],
                'title' => (string) $input['title'],
                'grade' => isset($input['grade']) ? (string) $input['grade'] : null,
                'headcount_limit' => isset($input['headcount_limit']) ? (int) $input['headcount_limit'] : null,
                'valid_from' => $validFrom,
                'valid_to' => (string) ($input['valid_to'] ?? '9999-12-31'),
                'recorded_at' => $now,
                'source_type' => 'API',
            ]);

            $this->audit->record(
                actorUserId: $actorUserId,
                action: 'org.position.created',
                aggregateType: 'position',
                aggregateId: $position->id,
                after: ['code' => (string) $position->code],
            );

            return $position;
        });
    }

    /**
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

            if ($status === 'DISABLED') {
                $occupancy = $this->occupancy->occupancy([$positionId], $this->clock->now()->toDateString());
                if (($occupancy[$positionId] ?? 0) > 0) {
                    throw new ConflictException(
                        'A position with active incumbents cannot be disabled.',
                        ['reason' => 'POSITION_STAFFED', 'position_id' => $positionId],
                    );
                }
            }

            $before = $position->only(['title', 'grade', 'headcount_limit']);
            $position->forceFill(array_filter([
                'title' => isset($input['title']) ? (string) $input['title'] : null,
                'grade' => array_key_exists('grade', $input) ? ($input['grade'] !== null ? (string) $input['grade'] : null) : null,
                'headcount_limit' => isset($input['headcount_limit']) ? (int) $input['headcount_limit'] : null,
                'status' => $status,
            ], static fn (mixed $value): bool => $value !== null))->save();

            $this->audit->record(
                actorUserId: $actorUserId,
                action: 'org.position.updated',
                aggregateType: 'position',
                aggregateId: $position->id,
                before: $before,
                after: $position->only(['title', 'grade', 'headcount_limit', 'status']),
            );

            return $position;
        });
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
}
