<?php

declare(strict_types=1);

namespace App\Modules\Time\Services;

use App\Modules\Time\Models\LeaveAccount;
use App\Modules\Time\Models\LeaveType;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Time\BusinessDate;
use App\Shared\Time\Clock;

final class LeaveAccountService
{
    public function __construct(
        private readonly Clock $clock,
        private readonly BusinessCalendar $calendar,
        private readonly StatutoryAnnualLeaveFloor $floors,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function createType(array $data): LeaveType
    {
        $code = strtoupper(trim((string) ($data['code'] ?? '')));

        if ($code === '') {
            throw new ValidationException(['code' => ['A leave type code is required.']]);
        }

        $unit = strtoupper((string) ($data['unit'] ?? 'DAY'));

        if (! in_array($unit, ['DAY', 'HOUR'], true)) {
            throw new ValidationException(['unit' => ['Leave unit must be DAY or HOUR.']]);
        }

        $isStatutory = (bool) ($data['is_statutory'] ?? false);
        $isAnnual = (bool) ($data['is_annual'] ?? false);
        $entitlement = (string) ($data['annual_entitlement_days'] ?? '0.00');

        if ($isStatutory && $isAnnual) {
            $asOf = (string) ($data['as_of'] ?? $this->clock->now()->format('Y-m-d'));
            $seniority = (string) ($data['seniority_start_date'] ?? $asOf);
            $this->floors->assertEntitlementAtLeastFloor($isStatutory, $entitlement, $seniority, $asOf);
        }

        return LeaveType::query()->create([
            'code' => $code,
            'name' => (string) ($data['name'] ?? $code),
            'is_statutory' => $isStatutory,
            'is_annual' => $isAnnual,
            'unit' => $unit,
            'annual_entitlement_days' => $entitlement,
            'requires_attachment' => (bool) ($data['requires_attachment'] ?? false),
            'allow_negative' => (bool) ($data['allow_negative'] ?? false),
            'expiry_rule_json' => $data['expiry_rule_json'] ?? null,
            'legal_basis' => isset($data['legal_basis']) ? (string) $data['legal_basis'] : null,
            'version' => 1,
            'created_at' => $this->clock->now(),
            'updated_at' => $this->clock->now(),
        ]);
    }

    public function ensureAccount(
        int $employmentId,
        int $leaveTypeId,
        ?string $openedOn = null,
    ): LeaveAccount {
        $openedOn ??= $this->clock->now()->format('Y-m-d');
        BusinessDate::parse($openedOn);

        $type = LeaveType::query()->whereKey($leaveTypeId)->first();

        if ($type === null) {
            throw new ConflictException('The leave type does not exist in this tenant.');
        }

        $existing = LeaveAccount::query()
            ->where('employment_id', $employmentId)
            ->where('leave_type_id', $leaveTypeId)
            ->whereNull('closed_on')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return LeaveAccount::query()->create([
            'employment_id' => $employmentId,
            'leave_type_id' => $leaveTypeId,
            'opened_on' => $openedOn,
            'created_at' => $this->clock->now(),
            'updated_at' => $this->clock->now(),
        ]);
    }

    public function standardDayMinutes(int $employmentId, ?string $businessDate = null): int
    {
        $businessDate ??= $this->clock->now()->format('Y-m-d');
        $snapshot = app(\App\Modules\Time\Contracts\EmploymentDirectory::class)
            ->snapshot($employmentId, $businessDate);

        if ($snapshot === null) {
            return 480;
        }

        return $this->calendar->standardDayMinutes();
    }
}
