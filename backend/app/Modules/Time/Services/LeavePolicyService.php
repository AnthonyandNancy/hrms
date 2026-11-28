<?php

declare(strict_types=1);

namespace App\Modules\Time\Services;

use App\Modules\Time\Models\LeavePolicyVersion;
use App\Modules\Time\Models\LeaveRequestProjection;
use App\Modules\Time\Models\LeaveType;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Observability\AuditRecorder;
use App\Shared\Time\BusinessDate;
use App\Shared\Time\Clock;
use Illuminate\Support\Facades\DB;

/**
 * Sole writer and reader for effective-dated leave rules.
 *
 * A ring-fenced invariant: append locks the identity row, allocates
 * max(version)+1 and rejects any overlap with a live version in the same
 * transaction. Retiring only stamps a version that has not started; history is
 * never deleted or edited.
 */
final class LeavePolicyService
{
    public function __construct(
        private readonly Clock $clock,
        private readonly AuditRecorder $audit,
    ) {}

    public function effectiveFor(int $leaveTypeId, string $businessDate): ?LeavePolicyVersion
    {
        $date = BusinessDate::from($businessDate)->value();

        return LeavePolicyVersion::query()
            ->where('leave_type_id', $leaveTypeId)
            ->where('status', 'PUBLISHED')
            ->whereNull('retired_at')
            ->where('effective_from', '<=', $date)
            ->where('effective_to', '>=', $date)
            ->orderByDesc('version')
            ->first();
    }

    /**
     * The latest live version per leave type, used by catalog projections.
     *
     * @return array<int, LeavePolicyVersion>
     */
    public function latestPublishedByType(): array
    {
        $rows = LeavePolicyVersion::query()
            ->where('status', 'PUBLISHED')
            ->whereNull('retired_at')
            ->orderBy('leave_type_id')
            ->orderBy('version')
            ->get();

        $latest = [];

        foreach ($rows as $row) {
            $latest[(int) $row->leave_type_id] = $row;
        }

        return $latest;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function append(array $input, int $actorUserId): array
    {
        $leaveTypeId = (int) $input['leave_type_id'];
        $effectiveFrom = BusinessDate::from((string) $input['effective_from'])->value();
        $effectiveTo = BusinessDate::from((string) $input['effective_to'])->value();

        if ($effectiveTo < $effectiveFrom) {
            throw new ValidationException(['effective_to' => ['The policy end date must not precede the start date.']]);
        }

        $appliesTo = $this->appliesTo($input['applies_to'] ?? ['scope' => 'COMPANY']);
        $accrual = $this->accrual($input['accrual'] ?? []);
        [$payrollTreatment, $paidRatioScaled] = $this->payrollTreatment($input);
        $carryover = $this->nullableJsonObject($input['carryover'] ?? null, 'carryover');
        $minNoticeDays = $this->nonNegativeInteger($input['min_notice_days'] ?? 0, 'min_notice_days');
        $maxConsecutiveDays = $this->nullablePositiveInteger($input['max_consecutive_days'] ?? null, 'max_consecutive_days');
        $attachmentOverDays = $this->nullablePositiveInteger(
            $input['requires_attachment_over_days'] ?? null,
            'requires_attachment_over_days',
        );

        return DB::transaction(function () use (
            $leaveTypeId,
            $effectiveFrom,
            $effectiveTo,
            $appliesTo,
            $accrual,
            $payrollTreatment,
            $paidRatioScaled,
            $carryover,
            $minNoticeDays,
            $maxConsecutiveDays,
            $attachmentOverDays,
            $input,
            $actorUserId,
        ): array {
            /** @var LeaveType|null $type */
            $type = LeaveType::query()->lockForUpdate()->whereKey($leaveTypeId)->first();

            if ($type === null) {
                throw new NotFoundOrForbiddenException;
            }

            if ((string) ($type->status ?? 'ACTIVE') !== 'ACTIVE') {
                throw new ConflictException('The leave type is inactive.', ['reason' => 'LEAVE_TYPE_INACTIVE']);
            }

            $overlap = LeavePolicyVersion::query()
                ->where('leave_type_id', $leaveTypeId)
                ->whereNull('retired_at')
                ->where('status', 'PUBLISHED')
                ->where('effective_from', '<=', $effectiveTo)
                ->where('effective_to', '>=', $effectiveFrom)
                ->lockForUpdate()
                ->exists();

            if ($overlap) {
                throw new ConflictException(
                    'A published leave policy version already overlaps the requested effective window.',
                    ['reason' => 'LEAVE_POLICY_RANGE_OVERLAP'],
                );
            }

            $version = ((int) LeavePolicyVersion::query()
                ->where('leave_type_id', $leaveTypeId)
                ->max('version')) + 1;

            $policy = LeavePolicyVersion::query()->create([
                'leave_type_id' => $leaveTypeId,
                'version' => $version,
                'status' => 'PUBLISHED',
                'effective_from' => $effectiveFrom,
                'effective_to' => $effectiveTo,
                'applies_to_json' => $appliesTo,
                'accrual_json' => $accrual,
                'payroll_treatment' => $payrollTreatment,
                'paid_ratio_scaled' => $paidRatioScaled,
                'carryover_json' => $carryover,
                'min_notice_days' => $minNoticeDays,
                'max_consecutive_days' => $maxConsecutiveDays,
                'requires_attachment_over_days' => $attachmentOverDays,
                'allow_half_day' => (bool) ($input['allow_half_day'] ?? true),
                'legal_basis' => isset($input['legal_basis']) && trim((string) $input['legal_basis']) !== ''
                    ? trim((string) $input['legal_basis'])
                    : null,
                'published_at' => $this->clock->now(),
                'published_by' => $actorUserId,
                'created_at' => $this->clock->now(),
            ]);

            $row = $this->row($policy, $type);

            $this->audit->record(
                $actorUserId,
                'time.leave.policy.published',
                'leave_policy_version',
                (int) $policy->id,
                after: $row,
            );

            return $row;
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function retire(int $policyId, string $reason, int $actorUserId): array
    {
        $today = $this->clock->now()->toDateString();

        return DB::transaction(function () use ($policyId, $reason, $actorUserId, $today): array {
            /** @var LeavePolicyVersion|null $policy */
            $policy = LeavePolicyVersion::query()->lockForUpdate()->whereKey($policyId)->first();

            if ($policy === null) {
                throw new NotFoundOrForbiddenException;
            }

            $type = LeaveType::query()->whereKey($policy->leave_type_id)->firstOrFail();
            $before = $this->row($policy, $type);

            if ($policy->retired_at !== null || $policy->status === 'RETIRED') {
                return [...$before, 'replayed' => true];
            }

            if ($policy->effective_from->format('Y-m-d') <= $today) {
                throw new ConflictException(
                    'An effective leave policy cannot be retired; append a later version instead.',
                    ['reason' => 'LEAVE_POLICY_ALREADY_EFFECTIVE'],
                );
            }

            $inUse = LeaveRequestProjection::query()
                ->where('leave_policy_version_id', $policyId)
                ->whereIn('state', ['SUBMITTED', 'APPROVED'])
                ->where('from_date', '>=', $today)
                ->lockForUpdate()
                ->exists();

            if ($inUse) {
                throw new ConflictException(
                    'The leave policy version is referenced by an open future request.',
                    ['reason' => 'LEAVE_POLICY_IN_USE'],
                );
            }

            $policy->forceFill([
                'status' => 'RETIRED',
                'retired_at' => $this->clock->now(),
                'retire_reason' => trim($reason) !== '' ? trim($reason) : 'Retired by administrator',
            ])->save();

            $after = [...$this->row($policy->refresh(), $type), 'replayed' => false];

            $this->audit->record(
                $actorUserId,
                'time.leave.policy.retired',
                'leave_policy_version',
                (int) $policy->id,
                before: $before,
                after: $after,
            );

            return $after;
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function row(LeavePolicyVersion $policy, ?LeaveType $type = null): array
    {
        $type ??= LeaveType::query()->whereKey($policy->leave_type_id)->first();

        return [
            'id' => (int) $policy->id,
            'leave_type_id' => (int) $policy->leave_type_id,
            'leave_type_code' => $type?->code,
            'leave_type_name' => $type?->name,
            'unit' => $type?->unit,
            'version' => (int) $policy->version,
            'status' => (string) $policy->status,
            'effective_from' => $policy->effective_from->format('Y-m-d'),
            'effective_to' => $policy->effective_to->format('Y-m-d'),
            'applies_to' => (array) $policy->applies_to_json,
            'accrual' => (array) $policy->accrual_json,
            'payroll_treatment' => $this->storedPayrollTreatment($policy),
            'paid_ratio_scaled' => $this->storedPaidRatioScaled($policy),
            'carryover' => $policy->carryover_json,
            'min_notice_days' => (int) $policy->min_notice_days,
            'max_consecutive_days' => $policy->max_consecutive_days,
            'requires_attachment_over_days' => $policy->requires_attachment_over_days,
            'allow_half_day' => (bool) $policy->allow_half_day,
            'legal_basis' => $policy->legal_basis,
            'published_at' => $policy->published_at->toIso8601ZuluString(),
            'published_by' => (int) $policy->published_by,
            'retired_at' => $policy->retired_at?->toIso8601ZuluString(),
            'retire_reason' => $policy->retire_reason,
        ];
    }

    /**
     * @return array{scope: string}
     */
    private function appliesTo(mixed $value): array
    {
        if (! is_array($value) || strtoupper((string) ($value['scope'] ?? '')) !== 'COMPANY') {
            throw new ValidationException(['applies_to.scope' => ['Only COMPANY-scoped leave policies are supported.']]);
        }

        return ['scope' => 'COMPANY'];
    }

    /**
     * @return array{mode: string, minutes_per_year: int, payroll_treatment?: string, paid_ratio_scaled?: int}
     */
    private function accrual(mixed $value): array
    {
        if (! is_array($value)) {
            throw new ValidationException(['accrual' => ['The accrual object is required.']]);
        }

        $mode = strtoupper((string) ($value['mode'] ?? 'ANNUAL_FIXED'));

        if (! in_array($mode, ['ANNUAL_FIXED', 'NONE', 'MONTHLY_FIXED'], true)) {
            throw new ValidationException(['accrual.mode' => ['Unsupported accrual mode.']]);
        }

        $minutes = $mode === 'NONE'
            ? 0
            : $this->nonNegativeInteger($value['minutes_per_year'] ?? 0, 'accrual.minutes_per_year');

        return ['mode' => $mode, 'minutes_per_year' => $minutes];
    }

    /**
     * Resolve the first-class input, accepting the legacy nested accrual shape
     * for one rollout window. New writes never put treatment back into
     * accrual_json.
     *
     * @param  array<string, mixed>  $input
     * @return array{0: string|null, 1: int|null}
     */
    private function payrollTreatment(array $input): array
    {
        $legacy = is_array($input['accrual'] ?? null) ? (array) $input['accrual'] : [];
        $rawTreatment = $input['payroll_treatment'] ?? $legacy['payroll_treatment'] ?? null;
        $rawRatio = $input['paid_ratio_scaled'] ?? $legacy['paid_ratio_scaled'] ?? null;

        if ($rawTreatment === null || trim((string) $rawTreatment) === '') {
            return [null, null];
        }

        $treatment = strtoupper(trim((string) $rawTreatment));

        if (! in_array($treatment, ['PAID', 'PARTIAL', 'UNPAID', 'UNRESOLVED'], true)) {
            throw new ValidationException([
                'payroll_treatment' => ['Payroll treatment must be PAID, PARTIAL, UNPAID or UNRESOLVED.'],
            ]);
        }

        if ($treatment !== 'PARTIAL') {
            return [$treatment, null];
        }

        $ratio = $this->nonNegativeInteger($rawRatio ?? 0, 'paid_ratio_scaled');

        if ($ratio < 1 || $ratio > 999_999) {
            throw new ValidationException([
                'paid_ratio_scaled' => ['A partial treatment requires a paid ratio between 1 and 999999.'],
            ]);
        }

        return [$treatment, $ratio];
    }

    private function storedPayrollTreatment(LeavePolicyVersion $policy): string
    {
        $column = is_string($policy->payroll_treatment) ? strtoupper(trim($policy->payroll_treatment)) : '';

        if ($column !== '') {
            return $column;
        }

        $legacy = is_array($policy->accrual_json) ? (array) $policy->accrual_json : [];

        return strtoupper((string) ($legacy['payroll_treatment'] ?? 'UNRESOLVED'));
    }

    private function storedPaidRatioScaled(LeavePolicyVersion $policy): ?int
    {
        if ($policy->paid_ratio_scaled !== null) {
            return (int) $policy->paid_ratio_scaled;
        }

        $legacy = is_array($policy->accrual_json) ? (array) $policy->accrual_json : [];

        return isset($legacy['paid_ratio_scaled']) ? (int) $legacy['paid_ratio_scaled'] : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function nullableJsonObject(mixed $value, string $field): ?array
    {
        if ($value === null) {
            return null;
        }

        if (! is_array($value)) {
            throw new ValidationException([$field => ['The value must be an object.']]);
        }

        return $value;
    }

    private function nonNegativeInteger(mixed $value, string $field): int
    {
        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            throw new ValidationException([$field => ['A non-negative integer is required.']]);
        }

        $integer = (int) $value;

        if ($integer < 0) {
            throw new ValidationException([$field => ['A non-negative integer is required.']]);
        }

        return $integer;
    }

    private function nullablePositiveInteger(mixed $value, string $field): ?int
    {
        if ($value === null) {
            return null;
        }

        $integer = $this->nonNegativeInteger($value, $field);

        if ($integer === 0) {
            throw new ValidationException([$field => ['A positive integer is required.']]);
        }

        return $integer;
    }
}
