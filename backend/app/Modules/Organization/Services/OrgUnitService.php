<?php

declare(strict_types=1);

namespace App\Modules\Organization\Services;

use App\Modules\Organization\Models\OrgUnit;
use App\Modules\Organization\Models\OrgUnitClosure;
use App\Modules\People\Contracts\OrgAssignmentImpactDirectory;
use App\Modules\People\Contracts\PeopleDirectory;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Observability\AuditRecorder;
use App\Shared\Observability\OutboxRecorder;
use App\Shared\Time\BusinessDate;
use App\Shared\Time\Clock;
use App\Shared\Time\CompanyBusinessDate;
use App\Shared\Time\DateRange;
use Illuminate\Support\Facades\DB;

/**
 * The single Organization writer for `org_units` and its versioned closure.
 *
 * `open()` creates the stable unit identity; `revise()` changes non-temporal
 * facts; `move()` only ends and appends closure paths. Department endpoints are
 * the only HTTP create/update surface.
 */
final class OrgUnitService
{
    public function __construct(
        private readonly Clock $clock,
        private readonly CompanyBusinessDate $businessDates,
        private readonly AuditRecorder $audit,
        private readonly OutboxRecorder $outbox,
        private readonly OrgAssignmentImpactDirectory $assignmentImpact,
        private readonly PeopleDirectory $people,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function open(array $input, ?int $actorUserId): OrgUnit
    {
        return DB::transaction(function () use ($input, $actorUserId): OrgUnit {
            $parentId = (int) ($input['parent_id'] ?? 0) ?: null;
            $code = trim((string) $input['code']);

            if (OrgUnit::query()->where('code', $code)->exists()) {
                throw new ConflictException(
                    'Organization unit code must be unique within the company.',
                    ['reason' => 'ORG_UNIT_CODE_DUPLICATE'],
                );
            }

            $now = $this->clock->now();
            $validFrom = (string) ($input['valid_from'] ?? $this->businessDates->today()->value());
            $validTo = (string) ($input['valid_to'] ?? BusinessDate::OPEN_END);
            $range = new DateRange($validFrom, $validTo);
            $managerEmploymentId = isset($input['manager_employment_id'])
                ? (int) $input['manager_employment_id']
                : null;

            if ($parentId !== null) {
                $parent = OrgUnit::query()
                    ->whereKey($parentId)
                    ->where('status', 'ACTIVE')
                    ->where('valid_from', '<=', $range->validFrom()->value())
                    ->where('valid_to', '>', $range->validFrom()->value())
                    ->first();

                if ($parent === null) {
                    throw new ValidationException(['parent_id' => ['The parent organization is not active on the effective date.']]);
                }
            }

            $this->assertManager($managerEmploymentId, $range->validFrom()->value());

            $unit = new OrgUnit;
            $unit->forceFill([
                'manager_employment_id' => $managerEmploymentId,
                'parent_id' => $parentId,
                'code' => $code,
                'name' => trim((string) $input['name']),
                'type' => (string) $input['type'],
                'status' => (string) ($input['status'] ?? 'ACTIVE'),
                'valid_from' => $range->validFrom()->value(),
                'valid_to' => $range->validTo()->value(),
                'recorded_at' => $now,
                'source_type' => 'API',
            ])->save();

            $this->appendClosure($unit, $range, $now);

            $this->audit->record(
                actorUserId: $actorUserId,
                action: 'org.unit.created',
                aggregateType: 'org_unit',
                aggregateId: $unit->id,
                after: [
                    'code' => (string) $unit->code,
                    'parent_id' => $parentId,
                    'manager_employment_id' => $managerEmploymentId,
                    'valid_from' => (string) $unit->valid_from,
                ],
            );

            $this->outbox->record(
                eventType: 'organization.unit.changed.v1',
                aggregateType: 'org_unit',
                aggregateId: (string) $unit->id,
                aggregateVersion: 1,
                payload: [
                    'org_unit_id' => (int) $unit->id,
                    'valid_from' => (string) $unit->valid_from,
                ],
            );

            return $unit;
        });
    }

    /**
     * Revise basic department facts. Temporal validity and parent changes are
     * deliberately not rewritten here: the closure is the effective-dated
     * hierarchy fact and a parent change must go through move().
     *
     * @param  array<string, mixed>  $input
     */
    public function revise(int $orgUnitId, array $input, ?int $actorUserId): OrgUnit
    {
        return DB::transaction(function () use ($orgUnitId, $input, $actorUserId): OrgUnit {
            /** @var OrgUnit|null $unit */
            $unit = OrgUnit::query()
                ->whereKey($orgUnitId)
                ->lockForUpdate()
                ->first();

            if ($unit === null) {
                throw new NotFoundOrForbiddenException;
            }

            $before = [
                'code' => (string) $unit->code,
                'name' => (string) $unit->name,
                'type' => (string) $unit->type,
                'status' => (string) $unit->status,
                'manager_employment_id' => $unit->getAttribute('manager_employment_id') !== null
                    ? (int) $unit->getAttribute('manager_employment_id')
                    : null,
            ];

            $requestedValidFrom = array_key_exists('valid_from', $input)
                ? (string) $input['valid_from']
                : (string) $unit->valid_from;
            $requestedValidTo = array_key_exists('valid_to', $input)
                ? (string) ($input['valid_to'] ?? BusinessDate::OPEN_END)
                : (string) $unit->valid_to;

            if ($requestedValidFrom !== (string) $unit->valid_from || $requestedValidTo !== (string) $unit->valid_to) {
                throw new ValidationException([
                    'valid_from' => ['The department validity window is immutable after creation; move the department instead.'],
                ]);
            }

            if (array_key_exists('code', $input)) {
                $code = trim((string) $input['code']);

                if (OrgUnit::query()->where('code', $code)->whereKeyNot($orgUnitId)->exists()) {
                    throw new ConflictException(
                        'Organization unit code must be unique within the company.',
                        ['reason' => 'ORG_UNIT_CODE_DUPLICATE'],
                    );
                }
            }

            $managerEmploymentId = $unit->getAttribute('manager_employment_id') !== null
                ? (int) $unit->getAttribute('manager_employment_id')
                : null;

            if (array_key_exists('manager_employment_id', $input)) {
                $managerEmploymentId = $input['manager_employment_id'] !== null
                    ? (int) $input['manager_employment_id']
                    : null;
                $this->assertManager($managerEmploymentId, $this->managerValidationDate($unit));
            }

            $changes = ['manager_employment_id' => $managerEmploymentId];

            foreach (['code', 'name', 'type', 'status'] as $field) {
                if (array_key_exists($field, $input)) {
                    $changes[$field] = is_string($input[$field]) ? trim($input[$field]) : $input[$field];
                }
            }

            $unit->forceFill($changes)->save();

            $after = [
                'code' => (string) $unit->code,
                'name' => (string) $unit->name,
                'type' => (string) $unit->type,
                'status' => (string) $unit->status,
                'manager_employment_id' => $managerEmploymentId,
            ];

            $this->audit->record(
                actorUserId: $actorUserId,
                action: 'org.unit.updated',
                aggregateType: 'org_unit',
                aggregateId: (int) $unit->id,
                before: $before,
                after: $after,
            );

            return $unit->refresh();
        });
    }

    /**
     * A move ends the current closure paths at effective_from and appends new
     * paths. org_units.id stays stable so assignments and external references
     * never churn; the hierarchy fact lives in the versioned closure.
     *
     * @param  array<string, mixed>  $input
     * @return array{org_unit: OrgUnit, moved: bool, old_id: int}
     */
    public function move(int $orgUnitId, array $input, ?int $actorUserId): array
    {
        return DB::transaction(function () use ($orgUnitId, $input, $actorUserId): array {
            $effectiveFrom = BusinessDate::from((string) $input['effective_from']);
            $newParentId = (int) ($input['new_parent_id'] ?? 0) ?: null;

            /** @var OrgUnit|null $unit */
            $unit = OrgUnit::query()
                ->whereKey($orgUnitId)
                ->lockForUpdate()
                ->first();

            if ($unit === null) {
                throw new NotFoundOrForbiddenException;
            }

            $currentFrom = BusinessDate::from((string) $unit->valid_from);
            $currentTo = BusinessDate::from((string) $unit->valid_to);

            if (! $effectiveFrom->isAfter($currentFrom) || $effectiveFrom->compareTo($currentTo) >= 0) {
                throw new ValidationException(['effective_from' => ['The move must take effect inside the current organization validity window.']]);
            }

            if ($newParentId === $orgUnitId) {
                throw new ValidationException(
                    ['new_parent_id' => ['An organization cannot be its own parent.']],
                    'The move would create an organization cycle.',
                    ['reason' => 'ORG_CYCLE'],
                );
            }

            if ($newParentId !== null) {
                $parent = OrgUnit::query()
                    ->whereKey($newParentId)
                    ->where('valid_from', '<=', $effectiveFrom->value())
                    ->where('valid_to', '>', $effectiveFrom->value())
                    ->first();

                if ($parent === null) {
                    throw new ValidationException(['new_parent_id' => ['The new parent is not active on the effective date.']]);
                }

                if ($this->wouldCycle($orgUnitId, $newParentId, $effectiveFrom->value())) {
                    throw new ValidationException(
                        ['new_parent_id' => ['The move would create an organization cycle.']],
                        'The move would create an organization cycle.',
                        ['reason' => 'ORG_CYCLE'],
                    );
                }
            }

            $latestClosureFrom = OrgUnitClosure::query()
                ->where(function ($query) use ($unit): void {
                    $query->where('ancestor_id', $unit->id)->orWhere('descendant_id', $unit->id);
                })
                ->max('valid_from');

            if (is_string($latestClosureFrom) && ! $effectiveFrom->isAfter(BusinessDate::from($latestClosureFrom))) {
                throw new ValidationException(['effective_from' => ['A new organization move must start after the latest closure version.']]);
            }

            $origParentId = $unit->parent_id !== null ? (int) $unit->parent_id : null;
            $now = $this->clock->now();

            // End the historical closure paths at the effective date. The
            // closure is the effective-dated hierarchy fact; org_units keeps
            // stable identity so external references never churn on a move.
            DB::table('org_unit_closure')
                ->where(function ($query) use ($unit): void {
                    $query->where('ancestor_id', $unit->id)->orWhere('descendant_id', $unit->id);
                })
                ->where('valid_from', '<=', $effectiveFrom->value())
                ->where('valid_to', '>', $effectiveFrom->value())
                ->update(['valid_to' => $effectiveFrom->value(), 'superseded_at' => $now]);

            $unit->forceFill(['parent_id' => $newParentId])->save();

            $range = new DateRange($effectiveFrom->value(), $currentTo->value());
            $this->appendClosure($unit, $range, $now);

            $this->audit->record(
                actorUserId: $actorUserId,
                action: 'org.unit.moved',
                aggregateType: 'org_unit',
                aggregateId: $unit->id,
                before: ['org_unit_id' => (int) $unit->id, 'parent_id' => $origParentId],
                after: ['org_unit_id' => (int) $unit->id, 'parent_id' => $newParentId, 'valid_from' => $effectiveFrom->value()],
            );

            $this->outbox->record(
                eventType: 'organization.unit.moved.v1',
                aggregateType: 'org_unit',
                aggregateId: (string) $unit->id,
                aggregateVersion: 2,
                payload: [
                    'org_unit_id' => (int) $unit->id,
                    'old_parent_id' => $origParentId,
                    'new_parent_id' => $newParentId,
                    'effective_from' => $effectiveFrom->value(),
                ],
            );

            return ['org_unit' => $unit->refresh(), 'moved' => true, 'old_id' => (int) $unit->id];
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{affected_employee_count: int, affected_manager_count: int, payroll_cost_center_count: int, cross_entity_warning: bool, cycle_refused: bool}
     */
    public function impact(int $orgUnitId, array $input, string $on): array
    {
        $unit = OrgUnit::query()->whereKey($orgUnitId)->first();

        if ($unit === null) {
            throw new NotFoundOrForbiddenException;
        }

        $newParentId = (int) ($input['new_parent_id'] ?? 0) ?: null;

        if ($newParentId !== null && ! OrgUnit::query()->whereKey($newParentId)->exists()) {
            throw new NotFoundOrForbiddenException;
        }

        $descendantIds = OrgUnitClosure::query()
            ->where('ancestor_id', $orgUnitId)
            ->where('valid_from', '<=', $on)
            ->where('valid_to', '>', $on)
            ->pluck('descendant_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $impact = $this->assignmentImpact->impactCounts(
            $descendantIds !== [] ? $descendantIds : [$orgUnitId],
            $on,
        );

        return [
            'affected_employee_count' => $impact->employeeCount,
            'affected_manager_count' => $impact->managerCount,
            'payroll_cost_center_count' => 0,
            'cross_entity_warning' => false,
            'cycle_refused' => $this->wouldCycle($orgUnitId, $newParentId, $on),
        ];
    }

    /**
     * Shared preflight used by both impact preview and move confirmation.
     */
    public function wouldCycle(int $orgUnitId, ?int $newParentId, string $on): bool
    {
        if ($newParentId === null) {
            return false;
        }

        if ($newParentId === $orgUnitId) {
            return true;
        }

        return in_array($orgUnitId, $this->ancestorIds($newParentId, $on), true);
    }

    private function assertManager(?int $managerEmploymentId, string $on): void
    {
        if ($managerEmploymentId === null) {
            return;
        }

        if ($this->people->employmentSnapshot($managerEmploymentId, $on) === null) {
            throw new ValidationException([
                'manager_employment_id' => ['The manager employment must be active on the effective date.'],
            ]);
        }
    }

    private function managerValidationDate(OrgUnit $unit): string
    {
        $today = $this->businessDates->today()->value();
        $from = (string) $unit->valid_from;
        $to = (string) $unit->valid_to;

        if ($today < $from) {
            return $from;
        }

        if ($today >= $to) {
            return $from;
        }

        return $today;
    }

    private function appendClosure(OrgUnit $unit, DateRange $range, \Carbon\CarbonImmutable $now): void
    {
        $rows = [[
            'ancestor_id' => (int) $unit->id,
            'descendant_id' => (int) $unit->id,
            'depth' => 0,
            'valid_from' => $range->validFrom()->value(),
            'valid_to' => $range->validTo()->value(),
            'recorded_at' => $now,
            'source_type' => 'API',
        ]];

        if ($unit->parent_id !== null) {
            $ancestors = OrgUnitClosure::query()
                ->where('descendant_id', (int) $unit->parent_id)
                ->where('valid_from', '<=', $range->validFrom()->value())
                ->where('valid_to', '>', $range->validFrom()->value())
                ->get();

            foreach ($ancestors as $ancestor) {
                $rows[] = [
                    'ancestor_id' => (int) $ancestor->ancestor_id,
                    'descendant_id' => (int) $unit->id,
                    'depth' => (int) $ancestor->depth + 1,
                    'valid_from' => $range->validFrom()->value(),
                    'valid_to' => $range->validTo()->value(),
                    'recorded_at' => $now,
                    'source_type' => 'API',
                ];
            }
        }

        DB::table('org_unit_closure')->insert($rows);
    }

    /**
     * @return list<int>
     */
    private function ancestorIds(int $orgId, string $on): array
    {
        return OrgUnitClosure::query()
            ->where('descendant_id', $orgId)
            ->where('valid_from', '<=', $on)
            ->where('valid_to', '>', $on)
            ->pluck('ancestor_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }
}
