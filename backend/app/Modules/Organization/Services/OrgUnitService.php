<?php

declare(strict_types=1);

namespace App\Modules\Organization\Services;

use App\Modules\Organization\Models\LegalEntity;
use App\Modules\Organization\Models\OrgUnit;
use App\Modules\Organization\Models\OrgUnitClosure;
use App\Modules\People\Contracts\OrgAssignmentImpactDirectory;
use App\Modules\Platform\Services\AuditService;
use App\Modules\Platform\Services\OutboxService;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Time\BusinessDate;
use App\Shared\Time\Clock;
use App\Shared\Time\DateRange;
use Illuminate\Support\Facades\DB;

final class OrgUnitService
{
    public function __construct(
        private readonly Clock $clock,
        private readonly AuditService $audit,
        private readonly OutboxService $outbox,
        private readonly OrgAssignmentImpactDirectory $assignmentImpact,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function open(int $tenantId, array $input, ?int $actorUserId): OrgUnit
    {
        return DB::transaction(function () use ($tenantId, $input, $actorUserId): OrgUnit {
            $legalEntity = LegalEntity::query()
                ->where('tenant_id', $tenantId)
                ->whereKey((int) $input['legal_entity_id'])
                ->first();

            if ($legalEntity === null) {
                throw new ValidationException(['legal_entity_id' => ['The legal entity does not exist in this tenant.']]);
            }

            $parentId = (int) ($input['parent_id'] ?? 0) ?: null;
            if (OrgUnit::query()->where('tenant_id', $tenantId)->where('code', (string) $input['code'])->exists()) {
                throw new ConflictException(
                    'Organization unit code must be unique within the tenant.',
                    ['reason' => 'ORG_UNIT_CODE_DUPLICATE'],
                );
            }

            $now = $this->clock->now();
            $validFrom = (string) ($input['valid_from'] ?? BusinessDate::today($legalEntity->timezone, $this->clock)->value());
            $range = new DateRange($validFrom, (string) ($input['valid_to'] ?? BusinessDate::OPEN_END));

            if ($parentId !== null) {
                $parent = OrgUnit::query()
                    ->where('tenant_id', $tenantId)
                    ->whereKey($parentId)
                    ->first();

                if ($parent === null || (int) $parent->legal_entity_id !== (int) $legalEntity->id) {
                    throw new ValidationException(['parent_id' => ['The parent organization must belong to the same legal entity.']]);
                }
            }

            $unit = OrgUnit::query()->create([
                'tenant_id' => $tenantId,
                'legal_entity_id' => (int) $legalEntity->id,
                'parent_id' => $parentId,
                'code' => (string) $input['code'],
                'name' => (string) $input['name'],
                'type' => (string) $input['type'],
                'status' => (string) ($input['status'] ?? 'ACTIVE'),
                'valid_from' => $range->validFrom()->value(),
                'valid_to' => $range->validTo()->value(),
                'recorded_at' => $now,
                'source_type' => 'API',
            ]);

            $this->appendClosure($tenantId, $unit, $range, $now);

            $this->audit->record(
                tenantId: $tenantId,
                actorUserId: $actorUserId,
                action: 'org.unit.created',
                aggregateType: 'org_unit',
                aggregateId: $unit->id,
                after: [
                    'code' => (string) $unit->code,
                    'legal_entity_id' => (int) $unit->legal_entity_id,
                    'parent_id' => $parentId,
                    'valid_from' => (string) $unit->valid_from,
                ],
            );

            $this->outbox->record(
                tenantId: $tenantId,
                eventType: 'organization.unit.changed.v1',
                aggregateType: 'org_unit',
                aggregateId: (string) $unit->id,
                aggregateVersion: 1,
                payload: [
                    'org_unit_id' => (int) $unit->id,
                    'legal_entity_id' => (int) $unit->legal_entity_id,
                    'valid_from' => (string) $unit->valid_from,
                ],
            );

            return $unit;
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
    public function move(int $tenantId, int $orgUnitId, array $input, ?int $actorUserId): array
    {
        return DB::transaction(function () use ($tenantId, $orgUnitId, $input, $actorUserId): array {
            $effectiveFrom = BusinessDate::from((string) $input['effective_from']);
            $newParentId = (int) ($input['new_parent_id'] ?? 0) ?: null;

            /** @var OrgUnit|null $unit */
            $unit = OrgUnit::query()
                ->where('tenant_id', $tenantId)
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
                throw new ValidationException(['new_parent_id' => ['An organization cannot be its own parent.']]);
            }

            if ($newParentId !== null) {
                $parent = OrgUnit::query()
                    ->where('tenant_id', $tenantId)
                    ->whereKey($newParentId)
                    ->where('valid_from', '<=', $effectiveFrom->value())
                    ->where('valid_to', '>', $effectiveFrom->value())
                    ->first();

                if ($parent === null) {
                    throw new ValidationException(['new_parent_id' => ['The new parent is not active on the effective date.']]);
                }

                if ((int) $parent->legal_entity_id !== (int) $unit->legal_entity_id) {
                    throw new ValidationException(['new_parent_id' => ['The new parent must belong to the same legal entity.']]);
                }

                if (in_array($orgUnitId, $this->ancestorIds($tenantId, $newParentId, $effectiveFrom->value()), true)) {
                    throw new ValidationException(['new_parent_id' => ['The move would create an organization cycle.']]);
                }
            }

            $latestClosureFrom = OrgUnitClosure::query()
                ->where('tenant_id', $tenantId)
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
                ->where('tenant_id', $tenantId)
                ->where(function ($query) use ($unit): void {
                    $query->where('ancestor_id', $unit->id)->orWhere('descendant_id', $unit->id);
                })
                ->where('valid_from', '<=', $effectiveFrom->value())
                ->where('valid_to', '>', $effectiveFrom->value())
                ->update(['valid_to' => $effectiveFrom->value(), 'superseded_at' => $now]);

            $unit->parent_id = $newParentId;
            $unit->save();

            $range = new DateRange($effectiveFrom->value(), $currentTo->value());
            $this->appendClosure($tenantId, $unit, $range, $now);

            $this->audit->record(
                tenantId: $tenantId,
                actorUserId: $actorUserId,
                action: 'org.unit.moved',
                aggregateType: 'org_unit',
                aggregateId: $unit->id,
                before: ['org_unit_id' => (int) $unit->id, 'parent_id' => $origParentId],
                after: ['org_unit_id' => (int) $unit->id, 'parent_id' => $newParentId, 'valid_from' => $effectiveFrom->value()],
            );

            $this->outbox->record(
                tenantId: $tenantId,
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

            return ['org_unit' => $unit, 'moved' => true, 'old_id' => $unit->id];
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{affected_employee_count: int, affected_manager_count: int, payroll_cost_center_count: int, cross_entity_warning: bool}
     */
    public function impact(int $tenantId, int $orgUnitId, array $input, string $on): array
    {
        $unit = OrgUnit::query()->where('tenant_id', $tenantId)->whereKey($orgUnitId)->first();

        if ($unit === null) {
            throw new NotFoundOrForbiddenException;
        }

        $newParentId = (int) ($input['new_parent_id'] ?? 0) ?: null;
        $crossEntity = false;

        if ($newParentId !== null) {
            $parent = OrgUnit::query()->where('tenant_id', $tenantId)->whereKey($newParentId)->first();

            if ($parent === null) {
                throw new NotFoundOrForbiddenException;
            }

            $crossEntity = (int) $parent->legal_entity_id !== (int) $unit->legal_entity_id;
        }

        $descendantIds = OrgUnitClosure::query()
            ->where('tenant_id', $tenantId)
            ->where('ancestor_id', $orgUnitId)
            ->where('valid_from', '<=', $on)
            ->where('valid_to', '>', $on)
            ->pluck('descendant_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $impact = $this->assignmentImpact->impactCounts(
            $tenantId,
            $descendantIds !== [] ? $descendantIds : [$orgUnitId],
            $on,
        );

        return [
            'affected_employee_count' => $impact->employeeCount,
            'affected_manager_count' => $impact->managerCount,
            'payroll_cost_center_count' => 0,
            'cross_entity_warning' => $crossEntity,
        ];
    }

    private function appendClosure(int $tenantId, OrgUnit $unit, DateRange $range, \Carbon\CarbonImmutable $now): void
    {
        $rows = [[
            'tenant_id' => $tenantId,
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
                ->where('tenant_id', $tenantId)
                ->where('descendant_id', (int) $unit->parent_id)
                ->where('valid_from', '<=', $range->validFrom()->value())
                ->where('valid_to', '>', $range->validFrom()->value())
                ->get();

            foreach ($ancestors as $ancestor) {
                $rows[] = [
                    'tenant_id' => $tenantId,
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
    private function ancestorIds(int $tenantId, int $orgId, string $on): array
    {
        return OrgUnitClosure::query()
            ->where('tenant_id', $tenantId)
            ->where('descendant_id', $orgId)
            ->where('valid_from', '<=', $on)
            ->where('valid_to', '>', $on)
            ->pluck('ancestor_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }
}
