<?php

declare(strict_types=1);

namespace App\Modules\Time\Services;

use App\Modules\Time\Models\AttendancePeriodClosure;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Observability\AuditRecorder;
use App\Shared\Time\Clock;

/**
 * Month close is the boundary between operational attendance and payroll
 * inputs. While a period is CLOSED, ordinary recalculation is refused; only
 * time.attendance.reopen can reopen it, with an audited reason. The audit
 * recorder still keys evidence by the platform-wide tenant column.
 */
final class AttendanceClosingService
{
    public function __construct(
        private readonly Clock $clock,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function close(string $month, int $actorUserId): array
    {
        $this->assertMonth($month);

        $closure = AttendancePeriodClosure::query()->firstOrCreate(
            ['month' => $month],
            ['status' => AttendancePeriodClosure::STATUS_CLOSED, 'closed_by' => $actorUserId, 'closed_at' => $this->clock->now()],
        );

        if (! $closure->wasRecentlyCreated && (string) $closure->status === AttendancePeriodClosure::STATUS_CLOSED) {
            return $this->payload($closure, true);
        }

        if (! $closure->wasRecentlyCreated) {
            $closure->forceFill([
                'status' => AttendancePeriodClosure::STATUS_CLOSED,
                'closed_by' => $actorUserId,
                'closed_at' => $this->clock->now(),
            ])->save();
        }

        $this->audit->record($actorUserId, 'time.attendance.closed', 'attendance_period', $closure->id, after: [
            'month' => $month,
        ]);

        return $this->payload($closure, false);
    }

    /**
     * @return array<string, mixed>
     */
    public function reopen(int $closureId, string $reason, int $actorUserId): array
    {
        $closure = AttendancePeriodClosure::query()
            ->whereKey($closureId)
            ->first();

        if ($closure === null) {
            throw new NotFoundOrForbiddenException;
        }

        if ((string) $closure->status === AttendancePeriodClosure::STATUS_REOPENED) {
            return $this->payload($closure, true);
        }

        $closure->forceFill([
            'status' => AttendancePeriodClosure::STATUS_REOPENED,
            'reopened_by' => $actorUserId,
            'reopened_at' => $this->clock->now(),
            'reopen_reason' => $reason,
        ])->save();

        $this->audit->record($actorUserId, 'time.attendance.reopened', 'attendance_period', $closure->id, after: [
            'month' => (string) $closure->month,
            'reason' => $reason,
        ]);

        return $this->payload($closure, false);
    }

    public function assertOpen(string $month): void
    {
        $closed = AttendancePeriodClosure::query()
            ->where('month', $month)
            ->where('status', AttendancePeriodClosure::STATUS_CLOSED)
            ->exists();

        if ($closed) {
            throw new ConflictException('This attendance period is closed; reopen it before recalculating.', ['reason' => 'PERIOD_CLOSED']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(AttendancePeriodClosure $closure, bool $replayed): array
    {
        return [
            'closure_id' => (int) $closure->id,
            'month' => (string) $closure->month,
            'status' => (string) $closure->status,
            'replayed' => $replayed,
        ];
    }

    private function assertMonth(string $month): void
    {
        if (preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
            throw new ValidationException(['month' => ['The month must use YYYY-MM format.']]);
        }
    }
}
