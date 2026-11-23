<?php

declare(strict_types=1);

namespace App\Modules\Time\Http\Controllers;

use App\Modules\Time\Http\TimeRequestScope;
use App\Modules\Time\Models\AttendanceDay;
use App\Modules\Time\Services\AttendanceCorrectionService;
use App\Modules\Time\Services\BusinessCalendar;
use App\Modules\Time\Services\PunchService;
use App\Shared\ApiResponse;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Time\BusinessDate;
use App\Shared\Time\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AttendanceController
{
    public function __construct(
        private readonly TimeRequestScope $scope,
        private readonly PunchService $punches,
        private readonly AttendanceCorrectionService $corrections,
        private readonly BusinessCalendar $calendar,
        private readonly Clock $clock,
    ) {}

    public function importPunches(Request $request): JsonResponse
    {
        $actor = $this->scope->actor($request);
        $data = $request->validate([
            'employment_id' => ['required', 'integer', 'min:1'],
            'punches' => ['required', 'array', 'min:1'],
            'punches.*.punched_at_utc' => ['required', 'date'],
            'punches.*.source' => ['nullable', 'string', 'in:DEVICE,MANUAL,IMPORT'],
            'punches.*.device_id' => ['nullable', 'string', 'max:64'],
            'punches.*.source_local_offset' => ['nullable', 'string', 'max:8'],
            'punches.*.raw_payload_hash' => ['nullable', 'string', 'max:255'],
        ]);

        $today = BusinessDate::today((string) config('hrms.default_timezone', 'Asia/Shanghai'), $this->clock)->value();
        $this->scope->employment($actor, (int) $data['employment_id'], $today);

        $records = $this->punches->import($actor->tenantId, (int) $data['employment_id'], (array) $data['punches']);
        $created = count(array_filter($records, static fn ($record): bool => (bool) $record->wasRecentlyCreated));

        return ApiResponse::ok([
            'employment_id' => (int) $data['employment_id'],
            'total' => count($records),
            'created' => $created,
            'replayed' => count($records) - $created,
            'items' => array_map(static fn ($record): array => [
                'id' => (int) $record->id,
                'punched_at_utc' => $record->punched_at_utc->toIso8601ZuluString(),
                'device_id' => $record->device_id,
                'source' => (string) $record->source,
            ], $records),
        ]);
    }

    public function month(Request $request): JsonResponse
    {
        $actor = $this->scope->actor($request);
        $start = $this->monthStart((string) $request->query('month', ''));
        $employment = $this->scope->employment($actor, (int) ($actor->employmentId ?? 0), $start->format('Y-m-d'));
        $timezone = $this->calendar->timezone($actor->tenantId, $employment->legalEntityId)
            ?? (string) config('hrms.default_timezone', 'Asia/Shanghai');
        $end = $start->endOfMonth();

        $rows = AttendanceDay::query()
            ->where('tenant_id', $actor->tenantId)
            ->where('employment_id', $employment->employmentId)
            ->where('business_date', '>=', $start->format('Y-m-d'))
            ->where('business_date', '<=', $end->format('Y-m-d'))
            ->get()
            ->keyBy(static fn (AttendanceDay $day): string => $day->business_date->format('Y-m-d'));

        $days = [];
        $summary = ['expectedDays' => 0, 'presentDays' => 0, 'lateCount' => 0, 'absentCount' => 0, 'leaveDays' => 0, 'remoteDays' => 0];

        for ($date = $start; $date->lessThanOrEqualTo($end); $date = $date->addDay()) {
            $key = $date->format('Y-m-d');
            $row = $rows->get($key);
            $isRest = $this->calendar->isRestDay($actor->tenantId, $employment->legalEntityId, $key);
            $status = $row?->status;

            if ($status === null) {
                $status = $isRest ? 'REST' : 'ABSENT';
            }

            $status = $status === 'NORMAL' ? 'PRESENT' : $status;

            if (! $isRest) {
                $summary['expectedDays']++;
            }
            if (in_array($status, ['PRESENT', 'LATE', 'EARLY', 'INCOMPLETE'], true)) {
                $summary['presentDays']++;
            }
            if ($status === 'LATE') {
                $summary['lateCount']++;
            }
            if ($status === 'ABSENT') {
                $summary['absentCount']++;
            }
            if ($status === 'LEAVE') {
                $summary['leaveDays']++;
            }

            $days[] = [
                'date' => $key,
                'weekday' => $date->dayName,
                'status' => $status,
                'scheduledStart' => null,
                'scheduledEnd' => null,
                'firstIn' => null,
                'lastOut' => null,
                'workedMinutes' => (int) ($row?->worked_minutes ?? 0),
                'exceptionLabel' => $row !== null && $row->late_minutes > 0 ? 'Late' : null,
                'scheduled_minutes' => (int) ($row?->scheduled_minutes ?? 0),
            ];
        }

        return ApiResponse::ok([
            'month' => $start->format('Y-m'),
            'timezone' => $timezone,
            'employeeName' => $employment->employeeNo,
            'days' => $days,
            'summary' => $summary,
            'exceptions' => array_values(array_filter($days, static fn (array $day): bool => $day['exceptionLabel'] !== null)),
        ]);
    }

    public function days(Request $request, int $employmentId): JsonResponse
    {
        $actor = $this->scope->actor($request);
        $from = $this->scope->validateDate((string) $request->query('from', ''), 'from');
        $to = $this->scope->validateDate((string) $request->query('to', ''), 'to');

        if ($to < $from) {
            throw new ValidationException(['to' => ['The end date must not precede the start date.']]);
        }

        $this->scope->employment($actor, $employmentId, $from);
        $toExclusive = CarbonImmutable::parse($to, 'UTC')->addDay()->format('Y-m-d');

        $items = AttendanceDay::query()
            ->where('tenant_id', $actor->tenantId)
            ->where('employment_id', $employmentId)
            ->where('business_date', '>=', $from)
            ->where('business_date', '<', $toExclusive)
            ->orderBy('business_date')
            ->get()
            ->map(static fn (AttendanceDay $day): array => [
                'id' => (int) $day->id,
                'employment_id' => (int) $day->employment_id,
                'business_date' => $day->business_date->format('Y-m-d'),
                'shift_template_id' => $day->shift_template_id,
                'scheduled_minutes' => (int) $day->scheduled_minutes,
                'worked_minutes' => (int) $day->worked_minutes,
                'overtime_minutes' => (int) $day->overtime_minutes,
                'late_minutes' => (int) $day->late_minutes,
                'early_leave_minutes' => (int) $day->early_leave_minutes,
                'absence_minutes' => (int) $day->absence_minutes,
                'status' => (string) $day->status,
                'rule_version' => (string) $day->rule_version,
                'calculated_at' => $day->calculated_at?->toIso8601ZuluString(),
            ])
            ->all();

        return ApiResponse::ok(ApiResponse::list($items, 1, count($items), count($items)));
    }

    public function correct(Request $request): JsonResponse
    {
        $actor = $this->scope->actor($request);
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'correctionType' => ['nullable', 'string', 'in:MISSING_PUNCH,WRONG_TIME,WRONG_STATUS'],
            'firstIn' => ['nullable', 'date_format:H:i'],
            'lastOut' => ['nullable', 'date_format:H:i'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'employment_id' => ['nullable', 'integer', 'min:1'],
            'business_date' => ['nullable', 'date_format:Y-m-d'],
            'corrected_punches' => ['nullable', 'array'],
        ]);

        $employmentId = (int) ($data['employment_id'] ?? $actor->employmentId ?? 0);
        $businessDate = (string) ($data['business_date'] ?? $data['date']);
        $employment = $this->scope->employment($actor, $employmentId, $businessDate);
        $timezone = $this->calendar->timezone($actor->tenantId, $employment->legalEntityId)
            ?? (string) config('hrms.default_timezone', 'Asia/Shanghai');
        $punches = (array) ($data['corrected_punches'] ?? []);

        if ($punches === []) {
            foreach (['firstIn', 'lastOut'] as $field) {
                if (isset($data[$field])) {
                    $punches[] = CarbonImmutable::parse($businessDate.' '.$data[$field].':00', $timezone)
                        ->utc()
                        ->toIso8601ZuluString();
                }
            }
        }

        $correction = $this->corrections->request(
            tenantId: $actor->tenantId,
            employmentId: $employmentId,
            businessDate: $businessDate,
            requestedBy: $actor->userId,
            reason: (string) $data['reason'],
            correctedPunches: $punches,
        );

        return ApiResponse::ok([
            'accepted' => true,
            'requestNo' => 'COR-'.str_pad((string) $correction->case_id, 6, '0', STR_PAD_LEFT),
            'caseId' => (int) $correction->case_id,
            'correctionId' => (int) $correction->id,
            'businessDate' => $correction->business_date->format('Y-m-d'),
        ]);
    }

    private function monthStart(string $month): CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}$/', $month) !== 1) {
            throw new ValidationException(['month' => ['The month must use YYYY-MM format.']]);
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $month.'-01', 'UTC');

        if ($date === false || $date->format('Y-m') !== $month) {
            throw new ValidationException(['month' => ['The month must be a real calendar month.']]);
        }

        return $date;
    }
}
