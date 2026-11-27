<?php

declare(strict_types=1);

namespace App\Modules\Time\Http\Controllers;

use App\Modules\Time\Contracts\TimeActor;
use App\Modules\Time\Http\TimeRequestScope;
use App\Modules\Time\Models\Holiday;
use App\Modules\Time\Models\LeaveType;
use App\Modules\Time\Models\OvertimeRecord;
use App\Modules\Time\Models\OvertimeSettlement;
use App\Modules\Time\Models\ShiftTemplate;
use App\Modules\Time\Services\BusinessCalendar;
use App\Shared\ApiResponse;
use App\Shared\Exceptions\NoActiveEmploymentException;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Exceptions\ValidationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TimeCatalogController
{
    public function __construct(
        private readonly TimeRequestScope $scope,
        private readonly BusinessCalendar $calendar,
    ) {}

    public function calendar(Request $request): JsonResponse
    {
        $this->scope->actor($request);
        $year = (int) $request->query('year', 0);

        if ($year < 1970 || $year > 9999) {
            throw new ValidationException(['year' => ['A four-digit calendar year is required.']]);
        }

        $calendar = $this->calendar->calendar();

        if ($calendar === null) {
            throw new NotFoundOrForbiddenException;
        }

        $holidays = Holiday::query()
            ->where('date', '>=', $year.'-01-01')
            ->where('date', '<', ($year + 1).'-01-01')
            ->orderBy('date')
            ->get()
            ->map(static fn (Holiday $holiday): array => [
                'date' => \Carbon\CarbonImmutable::parse((string) $holiday->date)->format('Y-m-d'),
                'name' => (string) $holiday->name,
                'type' => (string) $holiday->type,
                'source' => $holiday->source,
            ])
            ->all();

        return ApiResponse::ok([
            'year' => $year,
            'timezone' => (string) $calendar->timezone,
            'workweek' => array_values((array) $calendar->workweek_json),
            'standard_day_minutes' => (int) $calendar->standard_day_minutes,
            'version' => (int) $calendar->version,
            'holidays' => $holidays,
        ]);
    }

    public function shifts(Request $request): JsonResponse
    {
        $this->scope->actor($request);

        $items = ShiftTemplate::query()
            ->orderBy('code')
            ->orderByDesc('version')
            ->get()
            ->map(static fn (ShiftTemplate $shift): array => [
                'id' => (int) $shift->id,
                'code' => (string) $shift->code,
                'name' => (string) $shift->name,
                'start_local_time' => (string) $shift->start_local_time,
                'end_local_time' => (string) $shift->end_local_time,
                'crosses_midnight' => (bool) $shift->crosses_midnight,
                'break_minutes' => (int) $shift->break_minutes,
                'late_grace_minutes' => (int) $shift->late_grace_minutes,
                'early_grace_minutes' => (int) $shift->early_grace_minutes,
                'standard_hours_decimal' => (string) $shift->standard_hours_decimal,
                'version' => (int) $shift->version,
                'valid_from' => $shift->valid_from->format('Y-m-d'),
                'valid_to' => $shift->valid_to->format('Y-m-d'),
            ])
            ->all();

        return ApiResponse::ok([
            'items' => $items,
            'total' => count($items),
        ]);
    }

    public function leaveTypes(Request $request): JsonResponse
    {
        $this->scope->actor($request);
        $items = LeaveType::query()
            ->orderBy('code')
            ->get()
            ->map(static fn (LeaveType $type): array => [
                'id' => (int) $type->id,
                'code' => (string) $type->code,
                'name' => (string) $type->name,
                'unit' => (string) $type->unit,
                'is_statutory' => (bool) $type->is_statutory,
                'is_annual' => (bool) $type->is_annual,
                'annual_entitlement_days' => $type->annual_entitlement_days !== null
                    ? number_format((float) $type->annual_entitlement_days, 2, '.', '')
                    : null,
                'requires_attachment' => (bool) $type->requires_attachment,
                'allow_negative' => (bool) $type->allow_negative,
                'version' => (int) $type->version,
            ])->all();

        return ApiResponse::ok(ApiResponse::list($items, 1, max(1, count($items)), count($items)));
    }

    public function leavePolicies(Request $request): JsonResponse
    {
        $this->scope->actor($request);
        $items = LeaveType::query()
            ->orderBy('code')
            ->get()
            ->map(static fn (LeaveType $type): array => [
                'leave_type_code' => (string) $type->code,
                'unit' => (string) $type->unit,
                'expiry_rule' => $type->expiry_rule_json,
                'legal_basis' => $type->legal_basis !== null ? (string) $type->legal_basis : null,
                'version' => (int) $type->version,
            ])->all();

        return ApiResponse::ok(ApiResponse::list($items, 1, max(1, count($items)), count($items)));
    }

    public function overtimeRecords(Request $request): JsonResponse
    {
        $actor = $this->scope->actor($request);
        $employmentId = $this->selfEmploymentId($actor);
        $items = OvertimeRecord::query()
            ->where('employment_id', $employmentId)
            ->orderByDesc('business_date')
            ->orderByDesc('id')
            ->get()
            ->map(function (OvertimeRecord $record): array {
                $settlement = OvertimeSettlement::query()
                    ->where('overtime_record_id', (int) $record->id)
                    ->first();

                return [
                    'id' => (int) $record->id,
                    'employment_id' => (int) $record->employment_id,
                    'business_date' => (string) $record->business_date,
                    'minutes' => (int) $record->minutes,
                    'status' => (string) $record->status,
                    'case_id' => $record->case_id !== null ? (int) $record->case_id : null,
                    'settlement_method' => $settlement?->method,
                    'settlement_id' => $settlement?->id !== null ? (int) $settlement->id : null,
                ];
            })->all();

        return ApiResponse::ok(ApiResponse::list($items, 1, max(1, count($items)), count($items)));
    }

    public function overtimePolicies(Request $request): JsonResponse
    {
        $this->scope->actor($request);

        return ApiResponse::ok([
            'multiplier' => (string) config('payroll.overtime_multiplier', '1.500000'),
            'allow_pay' => true,
            'allow_timeoff' => true,
            'statutory_holiday_multiplier' => '3.000000',
            'source' => 'CODE_OWNED_DEFAULT',
            'note' => 'Company policy versions override this default when configured.',
        ]);
    }

    private function selfEmploymentId(TimeActor $actor): int
    {
        if ($actor->employmentId === null || $actor->employmentId <= 0) {
            throw new NoActiveEmploymentException('SELF');
        }

        return $actor->employmentId;
    }
}
