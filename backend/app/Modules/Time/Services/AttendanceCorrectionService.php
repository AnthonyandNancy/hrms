<?php

declare(strict_types=1);

namespace App\Modules\Time\Services;

use App\Modules\Time\Contracts\WorkflowCasePort;
use App\Modules\Time\Models\AttendanceCorrection;
use App\Modules\Time\Models\AttendanceDay;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Time\BusinessDate;
use App\Shared\Time\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

final class AttendanceCorrectionService
{
    public function __construct(
        private readonly WorkflowCasePort $workflow,
        private readonly Clock $clock,
        private readonly AttendanceCalculator $calculator,
        private readonly PayrollFreezeGuard $freezeGuard,
    ) {}

    /**
     * Submitting a correction opens a Case. Raw punches are never edited; the
     * corrected set is stored only on the correction and used by recalculation.
     *
     * @param  list<string>  $correctedPunches
     */
    public function request(
        int $employmentId,
        string $businessDate,
        int $requestedBy,
        string $reason,
        array $correctedPunches,
    ): AttendanceCorrection {
        BusinessDate::parse($businessDate);
        $this->freezeGuard->assertPeriodsOpen([substr($businessDate, 0, 7)]);

        if (trim($reason) === '') {
            throw new ValidationException(['reason' => ['A correction reason is required.']]);
        }

        foreach ($correctedPunches as $punch) {
            try {
                CarbonImmutable::parse($punch, 'UTC');
            } catch (Throwable) {
                throw new ValidationException(['corrected_punches' => ['Each punch must be an ISO-8601 UTC timestamp.']]);
            }
        }

        $existing = AttendanceCorrection::query()
            ->where('employment_id', $employmentId)
            ->where('business_date', $businessDate)
            ->whereNull('applied_at')
            ->first();

        if ($existing !== null) {
            throw new ConflictException(
                'A pending attendance correction already exists for this day.',
                ['reason' => 'CORRECTION_ALREADY_PENDING', 'correction_id' => $existing->id],
            );
        }

        return DB::transaction(function () use ($employmentId, $businessDate, $requestedBy, $reason, $correctedPunches): AttendanceCorrection {
            $caseId = $this->workflow->createCase(
                requesterUserId: $requestedBy,
                type: 'ATTENDANCE_CORRECTION',
                subjectType: 'employment',
                subjectId: $employmentId,
                payload: [
                    'employment_id' => $employmentId,
                    'business_date' => $businessDate,
                    'reason' => $reason,
                    'corrected_punches' => $correctedPunches,
                ],
                businessDate: $businessDate,
            );

            return AttendanceCorrection::query()->create([
                'employment_id' => $employmentId,
                'business_date' => $businessDate,
                'requested_by' => $requestedBy,
                'reason' => $reason,
                'corrected_punches_json' => $correctedPunches,
                'case_id' => $caseId,
                'created_at' => $this->clock->now(),
            ]);
        });
    }

    /**
     * @return array{applied: bool, business_date: string, attendance_status: string, replayed: bool}
     */
    public function applyEffect(int $caseId): array
    {
        return DB::transaction(function () use ($caseId): array {
            $case = $this->workflow->case($caseId);

            if ($case === null) {
                throw new ConflictException('The correction case was not found.');
            }

            $correction = AttendanceCorrection::query()
                ->where('case_id', $caseId)
                ->lockForUpdate()
                ->first();

            if ($correction === null) {
                throw new ConflictException('The attendance correction was not found.');
            }

            $this->freezeGuard->assertPeriodsOpen([
                $correction->business_date->format('Y-m'),
            ]);

            if (! $case->isApproved() && $correction->applied_at === null) {
                throw new ConflictException('The attendance correction has not been approved.');
            }

            if ($correction->applied_at !== null) {
                $day = AttendanceDay::query()
                    ->where('employment_id', $correction->employment_id)
                    ->where('business_date', $correction->business_date->format('Y-m-d'))
                    ->first();

                return [
                    'applied' => true,
                    'business_date' => $correction->business_date->format('Y-m-d'),
                    'attendance_status' => (string) ($day->status ?? 'INCOMPLETE'),
                    'replayed' => true,
                ];
            }

            $correction->forceFill(['applied_at' => $this->clock->now()])->save();
            $day = $this->calculator->recalculate(
                (int) $correction->employment_id,
                $correction->business_date->format('Y-m-d'),
            );

            return [
                'applied' => true,
                'business_date' => $day->business_date->format('Y-m-d'),
                'attendance_status' => $day->status,
                'replayed' => false,
            ];
        });
    }
}
