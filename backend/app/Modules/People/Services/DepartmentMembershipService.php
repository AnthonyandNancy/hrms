<?php

declare(strict_types=1);

namespace App\Modules\People\Services;

use App\Modules\People\Contracts\DepartmentMembershipPort;
use App\Modules\People\Models\Assignment;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Observability\AuditRecorder;
use App\Shared\Time\Clock;
use Illuminate\Support\Facades\DB;

final class DepartmentMembershipService implements DepartmentMembershipPort
{
    public function __construct(
        private readonly Clock $clock,
        private readonly AuditRecorder $audit,
    ) {}

    public function moveMember(int $targetOrgUnitId, int $employmentId, string $effectiveFrom, ?int $actorUserId): array
    {
        return DB::transaction(function () use ($targetOrgUnitId, $employmentId, $effectiveFrom, $actorUserId): array {
            $current = Assignment::query()
                ->where('employment_id', $employmentId)
                ->where('is_primary', true)
                ->where('valid_from', '<=', $effectiveFrom)
                ->where('valid_to', '>', $effectiveFrom)
                ->lockForUpdate()
                ->first();

            if ($current === null) {
                throw new NotFoundOrForbiddenException;
            }

            if ((int) $current->org_unit_id === $targetOrgUnitId) {
                throw new ConflictException('The employee is already a member of the target department.', ['reason' => 'ALREADY_IN_DEPARTMENT']);
            }

            $originalValidTo = (string) $current->valid_to;
            $current->forceFill(['valid_to' => $effectiveFrom])->save();

            /** @var Assignment $next */
            $next = Assignment::query()->create([
                'employment_id' => $employmentId,
                'org_unit_id' => $targetOrgUnitId,
                'position_id' => $current->position_id,
                'manager_assignment_id' => null,
                'assignment_type' => 'PRIMARY',
                'is_primary' => true,
                'valid_from' => $effectiveFrom,
                'valid_to' => $originalValidTo,
                'recorded_at' => $this->clock->now(),
                'source_type' => 'API',
            ]);

            $this->audit->record(
                actorUserId: $actorUserId,
                action: 'people.department.member_moved',
                aggregateType: 'assignment',
                aggregateId: $next->id,
                before: ['org_unit_id' => (int) $current->org_unit_id],
                after: ['org_unit_id' => $targetOrgUnitId, 'effective_from' => $effectiveFrom],
            );

            return [
                'assignment_id' => (int) $next->id,
                'employment_id' => $employmentId,
                'org_unit_id' => $targetOrgUnitId,
                'valid_from' => $effectiveFrom,
            ];
        });
    }
}
