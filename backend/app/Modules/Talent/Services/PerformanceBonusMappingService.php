<?php

declare(strict_types=1);

namespace App\Modules\Talent\Services;

use App\Modules\Talent\Models\PerformanceBonusMapping;
use App\Modules\Talent\Models\PerformanceResultSnapshot;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Observability\AuditRecorder;
use App\Shared\Time\Clock;
use Illuminate\Support\Facades\DB;

final class PerformanceBonusMappingService
{
    public function __construct(
        private readonly Clock $clock,
        private readonly AuditRecorder $audit,
    ) {}

    public function createDraft(
        int $actorUserId,
        int $snapshotId,
        string $payrollItemCode,
        string $period,
        int $amountCents,
        string $mappingVersion = 'V1',
    ): PerformanceBonusMapping {
        $payrollItemCode = strtoupper(trim($payrollItemCode));
        $period = trim($period);
        $mappingVersion = strtoupper(trim($mappingVersion));

        if (preg_match('/^[A-Z][A-Z0-9_]{2,63}$/D', $payrollItemCode) !== 1) {
            throw new ValidationException(['payroll_item_code' => ['The payroll item code is invalid.']]);
        }
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $period) !== 1) {
            throw new ValidationException(['period' => ['The bonus period must be YYYY-MM.']]);
        }
        if ($amountCents < 0) {
            throw new ValidationException(['amount_cents' => ['The bonus amount must be a non-negative integer minor amount.']]);
        }
        if ($mappingVersion === '') {
            throw new ValidationException(['mapping_version' => ['The mapping version is required.']]);
        }

        return DB::transaction(function () use ($actorUserId, $snapshotId, $payrollItemCode, $period, $amountCents, $mappingVersion): PerformanceBonusMapping {
            $snapshot = PerformanceResultSnapshot::query()
                ->whereKey($snapshotId)
                ->lockForUpdate()
                ->first();

            if ($snapshot === null || $snapshot->published_at === null) {
                throw new NotFoundOrForbiddenException;
            }

            if (PerformanceBonusMapping::query()
                ->where('performance_snapshot_id', $snapshotId)
                ->where('period', $period)
                ->where('mapping_version', $mappingVersion)
                ->exists()) {
                throw new ConflictException('This performance bonus mapping already exists.', [
                    'reason' => 'PERFORMANCE_BONUS_MAPPING_DUPLICATE',
                ]);
            }

            $mapping = PerformanceBonusMapping::query()->create([
                'performance_snapshot_id' => $snapshotId,
                'cycle_id' => (int) $snapshot->cycle_id,
                'employment_id' => (int) $snapshot->employment_id,
                'payroll_item_code' => $payrollItemCode,
                'period' => $period,
                'amount_cents' => $amountCents,
                'mapping_version' => $mappingVersion,
                'status' => 'DRAFT',
                'approved_by' => null,
                'approved_at' => null,
                'source_revision' => (string) ($snapshot->source_revision ?? ''),
                'created_at' => $this->clock->now(),
                'updated_at' => $this->clock->now(),
            ]);

            $this->audit->record(
                actorUserId: $actorUserId,
                action: 'talent.performance_bonus.drafted',
                aggregateType: 'performance_bonus_mapping',
                aggregateId: (int) $mapping->id,
                after: [
                    'performance_snapshot_id' => $snapshotId,
                    'period' => $period,
                    'amount_cents' => $amountCents,
                    'mapping_version' => $mappingVersion,
                ],
            );

            return $mapping;
        });
    }

    public function approve(int $actorUserId, int $mappingId): PerformanceBonusMapping
    {
        return DB::transaction(function () use ($actorUserId, $mappingId): PerformanceBonusMapping {
            $mapping = PerformanceBonusMapping::query()
                ->whereKey($mappingId)
                ->lockForUpdate()
                ->first();

            if ($mapping === null) {
                throw new NotFoundOrForbiddenException;
            }

            if ((string) $mapping->status === 'APPROVED') {
                return $mapping;
            }

            if ((string) $mapping->status !== 'DRAFT') {
                throw new ConflictException('Only a draft performance bonus mapping can be approved.', [
                    'reason' => 'PERFORMANCE_BONUS_MAPPING_NOT_DRAFT',
                ]);
            }

            $snapshot = PerformanceResultSnapshot::query()
                ->whereKey((int) $mapping->performance_snapshot_id)
                ->first();

            if ($snapshot === null || $snapshot->published_at === null) {
                throw new ConflictException('The performance result must be published before bonus approval.', [
                    'reason' => 'PERFORMANCE_RESULT_NOT_PUBLISHED',
                ]);
            }

            $mapping->forceFill([
                'status' => 'APPROVED',
                'approved_by' => $actorUserId,
                'approved_at' => $this->clock->now(),
                'source_revision' => (string) ($snapshot->source_revision ?? $mapping->source_revision),
                'updated_at' => $this->clock->now(),
            ])->save();

            $this->audit->record(
                actorUserId: $actorUserId,
                action: 'talent.performance_bonus.approved',
                aggregateType: 'performance_bonus_mapping',
                aggregateId: (int) $mapping->id,
                after: [
                    'performance_snapshot_id' => (int) $mapping->performance_snapshot_id,
                    'period' => (string) $mapping->period,
                    'amount_cents' => (int) $mapping->amount_cents,
                    'source_revision' => (string) $mapping->source_revision,
                ],
            );

            return $mapping->refresh();
        });
    }
}
