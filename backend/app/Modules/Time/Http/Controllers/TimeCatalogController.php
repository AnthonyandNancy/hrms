<?php

declare(strict_types=1);

namespace App\Modules\Time\Http\Controllers;

use App\Modules\Time\Contracts\TimeActor;
use App\Modules\Time\Http\TimeRequestScope;
use App\Modules\Time\Models\Holiday;
use App\Modules\Time\Models\LeavePolicyVersion;
use App\Modules\Time\Models\LeaveType;
use App\Modules\Time\Models\OvertimePolicyVersion;
use App\Modules\Time\Models\OvertimeRecord;
use App\Modules\Time\Models\OvertimeSettlement;
use App\Modules\Time\Models\ShiftTemplate;
use App\Modules\Time\Services\BusinessCalendar;
use App\Modules\Time\Services\LeavePolicyService;
use App\Modules\Time\Services\OvertimeRequestService;
use App\Shared\ApiResponse;
use App\Shared\Authorization\AnchorType;
use App\Shared\Commands\CommandDefinition;
use App\Shared\Commands\CommandResult;
use App\Shared\Commands\CommandRunner;
use App\Shared\Exceptions\NoActiveEmploymentException;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Validation\BusinessDateRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TimeCatalogController
{
    public function __construct(
        private readonly TimeRequestScope $scope,
        private readonly BusinessCalendar $calendar,
        private readonly OvertimeRequestService $overtime,
        private readonly LeavePolicyService $leavePolicies,
        private readonly CommandRunner $commands,
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
        $latest = $this->leavePolicies->latestPublishedByType();
        $items = LeaveType::query()
            ->orderBy('code')
            ->get()
            ->map(static function (LeaveType $type) use ($latest): array {
                $policy = $latest[(int) $type->id] ?? null;

                return [
                    'id' => (int) $type->id,
                    'code' => (string) $type->code,
                    'name' => (string) $type->name,
                    'unit' => (string) $type->unit,
                    'is_statutory' => (bool) $type->is_statutory,
                    'is_annual' => (bool) $type->is_annual,
                    'status' => (string) ($type->status ?? 'ACTIVE'),
                    'policy_version' => $policy?->version,
                    'entitlement_minutes' => $policy !== null
                        ? (int) ($policy->accrual_json['minutes_per_year'] ?? 0)
                        : null,
                    'requires_attachment' => (bool) $type->requires_attachment,
                    'allow_negative' => (bool) $type->allow_negative,
                    'version' => (int) $type->version,
                ];
            })->all();

        return ApiResponse::ok(ApiResponse::list($items, 1, max(1, count($items)), count($items)));
    }

    /**
     * Policy read is a projection of the version table; the legacy
     * leave_types.expiry_rule_json blob is intentionally not exposed.
     */
    public function leavePolicies(Request $request): JsonResponse
    {
        $this->scope->actor($request);
        $types = LeaveType::query()->get()->keyBy('id');
        $items = LeavePolicyVersion::query()
            ->orderBy('leave_type_id')
            ->orderByDesc('version')
            ->get()
            ->map(fn (LeavePolicyVersion $policy): array => $this->leavePolicies->row(
                $policy,
                $types->get((int) $policy->leave_type_id),
            ))
            ->all();

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
                    'business_date' => $record->business_date->format('Y-m-d'),
                    'minutes' => (int) $record->minutes,
                    'status' => (string) $record->status,
                    'case_id' => $record->case_id !== null ? (int) $record->case_id : null,
                    'settlement_method' => $settlement?->method,
                    'settlement_id' => $settlement?->id !== null ? (int) $settlement->id : null,
                ];
            })->all();

        return ApiResponse::ok(ApiResponse::list($items, 1, max(1, count($items)), count($items)));
    }

    /**
     * Company overtime rules are versioned facts. A company with no configured
     * version receives an empty list and `current: null`; the former code-owned
     * default is deliberately not fabricated here.
     */
    public function overtimePolicies(Request $request): JsonResponse
    {
        $this->scope->actor($request);
        $data = $request->validate([
            'as_of' => ['sometimes', 'nullable', new BusinessDateRule],
        ]);
        $asOf = $this->scope->resolveDate(isset($data['as_of']) ? (string) $data['as_of'] : null);

        return ApiResponse::ok($this->overtime->policyList($asOf));
    }

    public function storeOvertimePolicy(Request $request): JsonResponse
    {
        $actor = $this->scope->actor($request);
        $data = $request->validate([
            'effective_from' => ['required', new BusinessDateRule],
            'effective_to' => ['required', new BusinessDateRule],
            'time_unit_minutes' => ['sometimes', 'integer', 'in:1,5,10,15,30,60'],
            'multiplier' => ['sometimes', 'string', 'regex:'.OvertimeRequestService::MULTIPLIER_PATTERN],
            'statutory_holiday_multiplier' => ['sometimes', 'string', 'regex:'.OvertimeRequestService::MULTIPLIER_PATTERN],
            'allow_pay' => ['sometimes', 'boolean'],
            'allow_timeoff' => ['sometimes', 'boolean'],
            'allocation' => ['sometimes', 'string', 'in:EMPLOYEE_CHOICE,PAY,TIMEOFF,EMPLOYER_DECIDES'],
            'rounding' => ['sometimes', 'string', 'in:HALF_UP,HALF_EVEN,DOWN,UP'],
            'legal_basis' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        // A client-supplied version is intentionally ignored. The service
        // allocates max(version)+1 while holding the policy-row lock.
        $payload = [
            'effective_from' => (string) $data['effective_from'],
            'effective_to' => (string) $data['effective_to'],
            'time_unit_minutes' => (int) ($data['time_unit_minutes'] ?? 30),
            'multiplier' => (string) ($data['multiplier'] ?? '1.500000'),
            'statutory_holiday_multiplier' => (string) ($data['statutory_holiday_multiplier'] ?? '3.000000'),
            'allow_pay' => (bool) ($data['allow_pay'] ?? true),
            'allow_timeoff' => (bool) ($data['allow_timeoff'] ?? true),
            'allocation' => strtoupper((string) ($data['allocation'] ?? 'EMPLOYEE_CHOICE')),
            'rounding' => strtoupper((string) ($data['rounding'] ?? 'HALF_UP')),
            'legal_basis' => isset($data['legal_basis']) && trim((string) $data['legal_basis']) !== ''
                ? trim((string) $data['legal_basis'])
                : null,
        ];

        $result = $this->commands->run(
            new CommandDefinition(
                name: 'time.overtime.policy.append',
                capability: 'time.overtime.manage',
                anchor: AnchorType::COMPANY,
                idempotent: true,
                invariants: [],
                effect: null,
                auditAction: 'time.overtime.policy.published',
            ),
            $payload,
            function () use ($actor, $payload): array {
                $body = $this->overtime->appendPolicy($payload, $actor->userId);

                return [
                    'http_status' => 201,
                    'body' => $body,
                    'resource_type' => 'overtime_policy_version',
                    'resource_id' => (string) $body['id'],
                ];
            },
            (string) $request->header('Idempotency-Key', ''),
        );

        return $this->respondCommand($result, created: true);
    }

    public function retireOvertimePolicy(Request $request, int $id): JsonResponse
    {
        $actor = $this->scope->actor($request);
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        if (! OvertimePolicyVersion::query()->whereKey($id)->exists()) {
            throw new NotFoundOrForbiddenException;
        }

        $payload = [
            'overtime_policy_version_id' => $id,
            'reason' => (string) $data['reason'],
        ];

        $result = $this->commands->run(
            new CommandDefinition(
                name: 'time.overtime.policy.retire',
                capability: 'time.overtime.manage',
                anchor: AnchorType::COMPANY,
                idempotent: true,
                invariants: [],
                effect: null,
                auditAction: 'time.overtime.policy.retired',
            ),
            $payload,
            function () use ($actor, $payload): array {
                $body = $this->overtime->retirePolicy(
                    (int) $payload['overtime_policy_version_id'],
                    (string) $payload['reason'],
                    $actor->userId,
                );

                return [
                    'http_status' => 200,
                    'body' => $body,
                    'resource_type' => 'overtime_policy_version',
                    'resource_id' => (string) $body['id'],
                ];
            },
            (string) $request->header('Idempotency-Key', ''),
        );

        return $this->respondCommand($result, created: false);
    }

    private function respondCommand(CommandResult $result, bool $created): JsonResponse
    {
        $response = $created && ! $result->replayed
            ? ApiResponse::created($result->body)
            : ApiResponse::ok($result->body);

        if ($result->replayed) {
            $response->headers->set('Idempotency-Replayed', 'true');
        }

        return $response;
    }

    private function selfEmploymentId(TimeActor $actor): int
    {
        if ($actor->employmentId === null || $actor->employmentId <= 0) {
            throw new NoActiveEmploymentException('SELF');
        }

        return $actor->employmentId;
    }
}
