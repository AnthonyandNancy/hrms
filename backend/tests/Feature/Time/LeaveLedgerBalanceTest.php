<?php

declare(strict_types=1);

namespace Tests\Feature\Time;

use App\Modules\Time\Exceptions\LeaveBalanceException;
use App\Modules\Time\Models\LeaveLedgerEntry;
use App\Modules\Time\Services\LeaveBalanceService;
use App\Modules\Time\Services\LeaveLedgerService;
use Tests\Support\Time\TimeTestCase;

class LeaveLedgerBalanceTest extends TimeTestCase
{
    public function test_all_ledger_accounts_are_reflected_and_equation_holds_exactly(): void
    {
        $type = $this->leaveType();
        $this->leaveAccount($type);
        $ledger = app(LeaveLedgerService::class);

        $ledger->grant(1, 1, $type->id, 4800, '2026-01-01', 'ACCRUAL', '2026', 'Opening accrual');
        $ledger->reserve(1, 1, $type->id, 960, '2026-11-23', 'LEAVE_REQUEST', 'case-1', 'Annual leave');
        $ledger->consume(1, 1, $type->id, 960, '2026-11-23', 'LEAVE_REQUEST', 'case-1', 'Approved leave');
        $ledger->expire(1, 1, $type->id, 1200, '2026-11-23', 'EXPIRY', '2026', 'Year-end expiry');
        $ledger->adjust(1, 1, $type->id, 480, '2026-11-23', 'ADJUSTMENT', 'adj-1', 'Approved adjustment');
        $ledger->reverse(
            1, 1, $type->id, -480, '2026-11-23', 'ADJUSTMENT', 'adj-1',
            'Partially reverse an earlier grant', null, 'GRANT',
        );

        $balance = app(LeaveBalanceService::class)->forType(1, 1, $type->id, '2026-11-23');

        $this->assertSame(4800, $balance['granted']);
        $this->assertSame(960, $balance['reserved']);
        $this->assertSame(960, $balance['consumed']);
        $this->assertSame(960, $balance['released']);
        $this->assertSame(1200, $balance['expired']);
        $this->assertSame(480, $balance['adjusted']);
        $this->assertSame(-480, $balance['reversed']);
        $this->assertSame(2640, $balance['balance']);
        $this->assertSame(0, $balance['unexplained_difference']);
        $this->assertSame('5.50', $balance['balance_days']);
    }

    public function test_reserve_reduces_available_and_release_restores(): void
    {
        $type = $this->leaveType();
        $this->leaveAccount($type);
        $ledger = app(LeaveLedgerService::class);
        $ledger->grant(1, 1, $type->id, 4800, '2026-01-01', 'ACCRUAL', '2026', 'Opening');

        $ledger->reserve(1, 1, $type->id, 960, '2026-11-23', 'LEAVE_REQUEST', 'case-1', 'Leave');
        $this->assertSame(3840, app(LeaveBalanceService::class)->available(1, 1, $type->id, '2026-11-23'));

        $ledger->release(1, 1, $type->id, 960, '2026-11-23', 'LEAVE_REQUEST', 'case-1', 'Rejected');
        $this->assertSame(4800, app(LeaveBalanceService::class)->available(1, 1, $type->id, '2026-11-23'));
    }

    public function test_no_negative_balance_unless_type_allows_it(): void
    {
        $type = $this->leaveType(['allow_negative' => false]);
        $this->leaveAccount($type);
        app(LeaveLedgerService::class)->grant(1, 1, $type->id, 480, '2026-01-01', 'ACCRUAL', '2026', 'Opening');

        $this->expectException(LeaveBalanceException::class);

        app(LeaveLedgerService::class)->reserve(1, 1, $type->id, 960, '2026-11-23', 'LEAVE_REQUEST', 'case-2', 'Overbook');
    }

    public function test_negative_balance_is_permitted_when_type_allows_it(): void
    {
        $type = $this->leaveType(['allow_negative' => true]);
        $this->leaveAccount($type);

        app(LeaveLedgerService::class)->reserve(1, 1, $type->id, 960, '2026-11-23', 'LEAVE_REQUEST', 'case-3', 'Overbook allowed');

        $this->assertSame(-960, app(LeaveBalanceService::class)->available(1, 1, $type->id, '2026-11-23'));
    }

    public function test_day_display_uses_legal_entity_standard_day(): void
    {
        $type = $this->leaveType();
        $this->leaveAccount($type);
        app(LeaveLedgerService::class)->grant(1, 1, $type->id, 240, '2026-01-01', 'ACCRUAL', '2026', 'Half day');

        $balance = app(LeaveBalanceService::class)->forType(1, 1, $type->id, '2026-11-23');

        $this->assertSame('0.50', $balance['balance_days']);
        $this->assertSame(240, $balance['balance_minutes']);
    }

    public function test_duplicate_source_append_is_idempotent(): void
    {
        $type = $this->leaveType();
        $this->leaveAccount($type);
        $ledger = app(LeaveLedgerService::class);

        $first = $ledger->grant(1, 1, $type->id, 4800, '2026-01-01', 'ACCRUAL', '2026', 'Opening');
        $second = $ledger->grant(1, 1, $type->id, 4800, '2026-01-01', 'ACCRUAL', '2026', 'Opening');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, LeaveLedgerEntry::query()->count());
    }
}
