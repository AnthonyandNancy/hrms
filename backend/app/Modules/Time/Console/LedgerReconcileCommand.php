<?php

declare(strict_types=1);

namespace App\Modules\Time\Console;

use App\Modules\Time\Models\LeaveAccount;
use App\Modules\Time\Models\LeaveBalance;
use App\Modules\Time\Models\LeaveLedgerEntry;
use App\Modules\Time\Services\LeaveBalanceService;
use App\Modules\Time\Services\LeaveEquation;
use App\Shared\Time\BusinessDate;
use App\Shared\Time\Clock;
use Illuminate\Console\Command;

/**
 * Recompute the materialised leave balance projection from the append-only
 * ledger and compare it with the stored row. The ledger is never mutated.
 */
final class LedgerReconcileCommand extends Command
{
    protected $signature = 'ledger:reconcile
        {--as-of= : Business date to reconcile (YYYY-MM-DD, default today)}
        {--date= : Backwards-compatible alias for --as-of}
        {--tenant= : Restrict to one tenant id}
        {--rebuild : Repair mismatched LeaveBalance projections and re-check}';

    protected $description = 'Reconcile leave balance projections against the append-only ledger';

    public function handle(LeaveBalanceService $balances, Clock $clock): int
    {
        $asOf = (string) ($this->option('as-of') ?: $this->option('date') ?: $clock->now()->toDateString());

        if (BusinessDate::tryFrom($asOf) === null) {
            $this->error("Invalid --as-of date: {$asOf}. Expected a real YYYY-MM-DD calendar date.");

            return 2;
        }

        $tenantOption = $this->option('tenant');
        $tenantIds = [];

        if ($tenantOption !== null && $tenantOption !== '') {
            if (! is_string($tenantOption) || preg_match('/^[1-9]\d*$/', $tenantOption) !== 1) {
                $this->error("Invalid --tenant: {$tenantOption}. Expected a positive integer tenant id.");

                return 2;
            }

            $tenantIds = [(int) $tenantOption];
        } else {
            $tenantIds = LeaveAccount::query()
                ->distinct()
                ->orderBy('tenant_id')
                ->pluck('tenant_id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();
        }

        $checked = 0;
        $differences = [];
        $rebuilt = 0;
        $mismatches = [];

        foreach ($tenantIds as $tenantId) {
            $accounts = LeaveAccount::query()
                ->where('tenant_id', $tenantId)
                ->orderBy('employment_id')
                ->orderBy('leave_type_id')
                ->get();

            foreach ($accounts as $account) {
                $checked++;
                $summary = $balances->forType(
                    $tenantId,
                    (int) $account->employment_id,
                    (int) $account->leave_type_id,
                    $asOf,
                );
                $stored = LeaveBalance::query()
                    ->where('tenant_id', $tenantId)
                    ->where('employment_id', $account->employment_id)
                    ->where('leave_type_id', $account->leave_type_id)
                    ->first();
                $ledgerEntries = LeaveLedgerEntry::query()
                    ->where('tenant_id', $tenantId)
                    ->where('employment_id', $account->employment_id)
                    ->where('leave_type_id', $account->leave_type_id)
                    ->where('effective_on', '<=', $asOf)
                    ->get();
                $recomputed = LeaveEquation::summarize($ledgerEntries);
                $projectionBalance = $stored !== null ? (int) $stored->balance : null;

                if ($summary['unexplained_difference'] !== 0) {
                    $differences[] = [
                        'tenant_id' => $tenantId,
                        'employment_id' => (int) $account->employment_id,
                        'leave_type_id' => (int) $account->leave_type_id,
                        'reason' => 'LEDGER_EQUATION_UNEXPLAINED',
                        'ledger_balance' => $recomputed['balance'],
                        'recomputed_balance' => $summary['balance'],
                        'stored_balance' => $projectionBalance,
                    ];
                }

                if ($projectionBalance !== $summary['balance']) {
                    $mismatches[] = [
                        'tenant_id' => $tenantId,
                        'employment_id' => (int) $account->employment_id,
                        'leave_type_id' => (int) $account->leave_type_id,
                        'stored' => $projectionBalance,
                        'expected' => $summary['balance'],
                        'difference' => $projectionBalance === null ? null : $projectionBalance - $summary['balance'],
                    ];

                    if ((bool) $this->option('rebuild')) {
                        $balances->project($tenantId, (int) $account->employment_id, (int) $account->leave_type_id, $summary);
                        $rebuilt++;
                    }
                }
            }
        }

        $this->line("As of: {$asOf}");
        $this->line('Accounts checked: '.$checked);
        $this->line('Ledger entries: '.LeaveLedgerEntry::query()->where('effective_on', '<=', $asOf)->count());
        $this->line('Projection mismatches: '.count($mismatches));
        $this->line('Ledger equation differences: '.count($differences));

        foreach ($mismatches as $mismatch) {
            $this->line(sprintf(
                'DIFF tenant=%d employment=%d leave_type=%d stored=%s expected=%d difference=%s',
                $mismatch['tenant_id'],
                $mismatch['employment_id'],
                $mismatch['leave_type_id'],
                $mismatch['stored'] === null ? 'missing' : (string) $mismatch['stored'],
                $mismatch['expected'],
                $mismatch['difference'] === null ? 'n/a' : (string) $mismatch['difference'],
            ));
        }

        foreach ($differences as $difference) {
            $this->line(sprintf(
                'EQUATION tenant=%d employment=%d leave_type=%d ledger=%d recomputed=%d',
                $difference['tenant_id'],
                $difference['employment_id'],
                $difference['leave_type_id'],
                $difference['ledger_balance'],
                $difference['recomputed_balance'],
            ));
        }

        if ((bool) $this->option('rebuild')) {
            $this->line('Rebuilt projections: '.$rebuilt);
            $remaining = 0;

            foreach ($mismatches as $mismatch) {
                $expected = $balances->forType($mismatch['tenant_id'], $mismatch['employment_id'], $mismatch['leave_type_id'], $asOf)['balance'];
                $storedNow = LeaveBalance::query()
                    ->where('tenant_id', $mismatch['tenant_id'])
                    ->where('employment_id', $mismatch['employment_id'])
                    ->where('leave_type_id', $mismatch['leave_type_id'])
                    ->value('balance');

                if ($storedNow === null || (int) $storedNow !== $expected) {
                    $remaining++;
                }
            }

            $differenceCount = $remaining + count($differences);
            $this->line('Differences: '.$differenceCount);

            if ($differenceCount === 0) {
                return 0;
            }

            $this->line('ledger entries are append-only; no ledger entry was changed.');

            return 1;
        }

        $differenceCount = count($mismatches) + count($differences);
        $this->line('Differences: '.$differenceCount);

        if ($differenceCount > 0) {
            $this->line('ledger entries are append-only; no ledger entry was changed.');

            return 1;
        }

        return 0;
    }
}
