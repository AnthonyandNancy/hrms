<?php

declare(strict_types=1);

namespace Tests\Feature\Time\Console;

use App\Modules\Time\Models\LeaveBalance;
use App\Modules\Time\Services\LeaveBalanceService;
use App\Modules\Time\Services\LeaveLedgerService;
use Tests\Support\Time\TimeTestCase;

final class LedgerReconcileCommandTest extends TimeTestCase
{
    public function test_clean_projection_exits_zero_and_reports_accounts(): void
    {
        $type = $this->leaveType();
        $this->leaveAccount($type);
        app(LeaveLedgerService::class)->grant(1, $type->id, 4800, '2026-01-01', 'ACCRUAL', '2026', 'Opening');

        $this->artisan('ledger:reconcile', ['--as-of' => '2026-11-23'])
            ->expectsOutputToContain('Accounts checked: 1')
            ->expectsOutputToContain('Differences: 0')
            ->assertExitCode(0);
    }

    public function test_corrupted_projection_exits_one_without_changing_ledger(): void
    {
        $type = $this->leaveType();
        $this->leaveAccount($type);
        app(LeaveLedgerService::class)->grant(1, $type->id, 4800, '2026-01-01', 'ACCRUAL', '2026', 'Opening');

        LeaveBalance::query()->where('employment_id', 1)->where('leave_type_id', $type->id)->update([
            'balance' => 1,
            'granted' => 1,
        ]);
        $before = \App\Modules\Time\Models\LeaveLedgerEntry::query()->orderBy('id')->get()->toArray();

        $this->artisan('ledger:reconcile', ['--as-of' => '2026-11-23'])
            ->expectsOutputToContain('Differences: 1')
            ->expectsOutputToContain('ledger entries are append-only')
            ->assertExitCode(1);

        $this->assertSame($before, \App\Modules\Time\Models\LeaveLedgerEntry::query()->orderBy('id')->get()->toArray());
    }

    public function test_invalid_date_exits_two(): void
    {
        $this->artisan('ledger:reconcile', ['--as-of' => '2026-02-30'])
            ->expectsOutputToContain('Invalid --as-of date')
            ->assertExitCode(2);
    }

    public function test_rebuild_repairs_projection_and_rechecks_clean(): void
    {
        $type = $this->leaveType();
        $this->leaveAccount($type);
        app(LeaveLedgerService::class)->grant(1, $type->id, 4800, '2026-01-01', 'ACCRUAL', '2026', 'Opening');
        LeaveBalance::query()->where('employment_id', 1)->where('leave_type_id', $type->id)->update([
            'balance' => 0,
            'as_of' => '2026-11-22',
        ]);

        $this->artisan('ledger:reconcile', [
            '--as-of' => '2026-11-23',
            '--rebuild' => true,
        ])
            ->expectsOutputToContain('Rebuilt projections: 1')
            ->expectsOutputToContain('Differences: 0')
            ->assertExitCode(0);

        $rebuilt = app(LeaveBalanceService::class)->forType(1, $type->id, '2026-11-23');
        $this->assertSame(4800, $rebuilt['balance']);
        $this->assertDatabaseHas('leave_balances', [
            'employment_id' => 1,
            'leave_type_id' => $type->id,
            'balance' => 4800,
            'as_of' => '2026-11-23',
        ]);
    }

    public function test_empty_company_reconciles_zero_accounts(): void
    {
        $this->artisan('ledger:reconcile', ['--as-of' => '2026-11-23'])
            ->expectsOutputToContain('Accounts checked: 0')
            ->assertExitCode(0);
    }
}
