<?php

declare(strict_types=1);

namespace App\Modules\Time\Services;

use App\Modules\Time\Models\AttendancePeriodClosure;
use App\Modules\Time\Models\TimePayrollFactVersion;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Observability\AuditRecorder;

/**
 * Month close is the boundary between operational attendance and payroll
 * inputs. Closing appends an immutable monthly fact version; reopening keeps
 * that version and allows the next close to append version + 1.
 */
final class AttendanceClosingService
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TimeFactVersionService $facts,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function close(string $month, int $actorUserId): array
    {
        $this->assertMonth($month);

        $result = $this->facts->close($month, $actorUserId);

        if (! $result['replayed']) {
            $this->audit->record($actorUserId, 'time.attendance.closed', 'attendance_period', (int) $result['version']->id, after: [
                'month' => $month,
                'fact_version' => (int) $result['version']->version,
                'source_hash' => (string) $result['version']->source_hash,
                'exception_count' => (int) $result['version']->exception_count,
            ]);
        }

        $closure = AttendancePeriodClosure::query()->where('month', $month)->firstOrFail();

        return $this->payload($closure, $result['replayed']);
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

        $reopened = $this->facts->reopen($closure, $reason, $actorUserId);

        $this->audit->record($actorUserId, 'time.attendance.reopened', 'attendance_period', $closure->id, after: [
            'month' => (string) $reopened->month,
            'reason' => $reason,
        ]);

        return $this->payload($reopened, false);
    }

    public function assertOpen(string $month): void
    {
        $closed = AttendancePeriodClosure::query()
            ->where('month', $month)
            ->where('status', AttendancePeriodClosure::STATUS_CLOSED)
            ->exists();

        if ($closed) {
            throw new ConflictException('该考勤月份已关闭，请先重开并生成新事实版本后再修改。', ['reason' => 'PERIOD_CLOSED', 'period' => $month]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(AttendancePeriodClosure $closure, bool $replayed): array
    {
        $version = $closure->current_fact_version_id !== null
            ? TimePayrollFactVersion::query()
                ->whereKey((int) $closure->current_fact_version_id)
                ->first()
            : TimePayrollFactVersion::query()
                ->where('period', (string) $closure->month)
                ->orderByDesc('version')
                ->first();

        return [
            'closure_id' => (int) $closure->id,
            'month' => (string) $closure->month,
            'status' => (string) $closure->status,
            'replayed' => $replayed,
            'fact_version' => $version?->version !== null ? (int) $version->version : null,
            'fact_version_id' => $version?->id !== null ? (int) $version->id : null,
            'source_hash' => $version?->source_hash,
            'exception_count' => $version !== null ? (int) $version->exception_count : 0,
        ];
    }

    private function assertMonth(string $month): void
    {
        if (preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
            throw new ValidationException(['month' => ['The month must use YYYY-MM format.']]);
        }
    }
}
