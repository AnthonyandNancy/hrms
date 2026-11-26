<?php

declare(strict_types=1);

namespace App\Modules\Time\Services;

use App\Modules\Time\Models\LeaveAccount;
use App\Modules\Time\Models\LeaveBalance;
use App\Modules\Time\Models\LeaveLedgerEntry;
use App\Modules\Time\Models\LeaveType;
use App\Shared\Time\BusinessDate;
use App\Shared\Time\Clock;

final class LeaveBalanceService
{
    public function __construct(
        private readonly Clock $clock,
        private readonly LeaveAccountService $accounts,
    ) {}

    /**
     * @return array{
     *   leave_type_id: int,
     *   leave_type_code: string,
     *   leave_type_name: string,
     *   unit: string,
     *   as_of: string,
     *   granted: int,
     *   reserved: int,
     *   consumed: int,
     *   released: int,
     *   expired: int,
     *   adjusted: int,
     *   reversed: int,
     *   balance: int,
     *   available: int,
     *   balance_minutes: int,
     *   balance_days: string,
     *   standard_day_minutes: int,
     *   unexplained_difference: int
     * }
     */
    public function forType(int $employmentId, int $leaveTypeId, string $asOf): array
    {
        $type = LeaveType::query()
            ->whereKey($leaveTypeId)
            ->firstOrFail();

        $entries = LeaveLedgerEntry::query()
            ->where('employment_id', $employmentId)
            ->where('leave_type_id', $leaveTypeId)
            ->where('effective_on', '<=', $asOf)
            ->orderBy('id')
            ->get();

        $summary = LeaveEquation::summarize($entries);
        $standardDay = $this->accounts->standardDayMinutes($employmentId, $asOf);

        return [
            'leave_type_id' => $leaveTypeId,
            'leave_type_code' => $type->code,
            'leave_type_name' => $type->name,
            'unit' => $type->unit,
            'as_of' => $asOf,
            ...$summary,
            'balance_minutes' => $summary['balance'],
            'balance_days' => QuantityUnit::toDisplayDays($summary['balance'], $standardDay),
            'standard_day_minutes' => $standardDay,
        ];
    }

    public function available(int $employmentId, int $leaveTypeId, string $asOf): int
    {
        return $this->forType($employmentId, $leaveTypeId, $asOf)['available'];
    }

    /**
     * Payroll/final-settlement input across every open account at as-of.
     *
     * @return list<array<string, mixed>>
     */
    public function outstanding(int $employmentId, string $asOf): array
    {
        BusinessDate::parse($asOf);

        $typeIds = LeaveAccount::query()
            ->where('employment_id', $employmentId)
            ->where('opened_on', '<=', $asOf)
            ->where(function ($query) use ($asOf): void {
                $query->whereNull('closed_on')->orWhere('closed_on', '>', $asOf);
            })
            ->orderBy('leave_type_id')
            ->pluck('leave_type_id')
            ->unique()
            ->values();

        $balances = [];

        foreach ($typeIds as $typeId) {
            $balances[] = $this->forType($employmentId, (int) $typeId, $asOf);
        }

        return $balances;
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    /**
     * @param  array<string, mixed>  $summary
     */
    public function project(int $employmentId, int $leaveTypeId, array $summary): LeaveBalance
    {
        return LeaveBalance::query()->updateOrCreate(
            [
                'employment_id' => $employmentId,
                'leave_type_id' => $leaveTypeId,
            ],
            [
                'as_of' => $summary['as_of'],
                'granted' => $summary['granted'],
                'reserved' => $summary['reserved'],
                'consumed' => $summary['consumed'],
                'released' => $summary['released'],
                'expired' => $summary['expired'],
                'adjusted' => $summary['adjusted'],
                'reversed' => $summary['reversed'],
                'balance' => $summary['balance'],
                'updated_at' => $this->clock->now(),
            ],
        );
    }

    /**
     * Rebuild all materialised balances from the ledger.
     *
     * @return array{rebuilt: int, mismatches: list<array<string, mixed>>}
     */
    public function rebuild(string $asOf): array
    {
        $accounts = LeaveAccount::query()
            ->orderBy('employment_id')
            ->orderBy('leave_type_id')
            ->get();

        $mismatches = [];
        $rebuilt = 0;

        foreach ($accounts as $account) {
            $summary = $this->forType((int) $account->employment_id, (int) $account->leave_type_id, $asOf);
            $existing = LeaveBalance::query()
                ->where('employment_id', $account->employment_id)
                ->where('leave_type_id', $account->leave_type_id)
                ->first();

            if ($existing !== null && (int) $existing->balance !== $summary['balance']) {
                $mismatches[] = [
                    'employment_id' => $account->employment_id,
                    'leave_type_id' => $account->leave_type_id,
                    'stored' => (int) $existing->balance,
                    'rebuilt' => $summary['balance'],
                ];
            }

            $this->project((int) $account->employment_id, (int) $account->leave_type_id, $summary);
            $rebuilt++;
        }

        return ['rebuilt' => $rebuilt, 'mismatches' => $mismatches];
    }
}
