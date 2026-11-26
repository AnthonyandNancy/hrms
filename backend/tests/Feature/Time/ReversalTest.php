<?php

declare(strict_types=1);

namespace Tests\Feature\Time;

use App\Modules\Time\Services\LeaveBalanceService;
use App\Modules\Time\Services\LeaveLedgerService;
use Tests\Support\Time\TimeTestCase;

class ReversalTest extends TimeTestCase
{
    public function test_consume_then_reverse_restores_balance_once(): void
    {
        $type = $this->leaveType();
        $this->leaveAccount($type);
        $ledger = app(LeaveLedgerService::class);
        $ledger->grant(1, $type->id, 480, '2026-01-01', 'ACCRUAL', '2026', 'One day');
        $reserve = $ledger->reserve(1, $type->id, 480, '2026-11-23', 'LEAVE_REQUEST', 'case-1', 'Leave');
        $consume = $ledger->consume(1, $type->id, 480, '2026-11-23', 'LEAVE_REQUEST', 'case-1', 'Approved');
        $this->assertSame(0, app(LeaveBalanceService::class)->available(1, $type->id, '2026-11-23'));

        $first = $ledger->reverse(
            employmentId: 1,
            leaveTypeId: $type->id,
            minutes: 480,
            businessDate: '2026-11-23',
            sourceType: 'LEAVE_REVERSAL',
            sourceId: 'case-1:consume',
            reason: 'Correction',
            reversedEntryId: $consume->id,
            targetAccount: 'CONSUME',
        );
        $replay = $ledger->reverse(
            employmentId: 1,
            leaveTypeId: $type->id,
            minutes: 480,
            businessDate: '2026-11-23',
            sourceType: 'LEAVE_REVERSAL',
            sourceId: 'case-1:consume',
            reason: 'Correction',
            reversedEntryId: $consume->id,
            targetAccount: 'CONSUME',
        );

        $this->assertSame($first->id, $replay->id);
        $this->assertSame(480, app(LeaveBalanceService::class)->available(1, $type->id, '2026-11-23'));
        $this->assertSame(1, \App\Modules\Time\Models\LeaveLedgerEntry::query()->where('account', 'REVERSE')->count());
    }

    public function test_reverse_entry_direction_is_opposite_of_target(): void
    {
        $type = $this->leaveType();
        $this->leaveAccount($type);
        $ledger = app(LeaveLedgerService::class);
        $ledger->grant(1, $type->id, 480, '2026-01-01', 'ACCRUAL', '2026', 'One day');
        $grant = \App\Modules\Time\Models\LeaveLedgerEntry::query()->where('account', 'GRANT')->firstOrFail();

        $ledger->reverse(1, $type->id, -480, '2026-11-23', 'LEAVE_REVERSAL', 'grant-reversal', 'Wrong grant', $grant->id, 'GRANT');

        $this->assertSame(0, app(LeaveBalanceService::class)->available(1, $type->id, '2026-11-23'));
    }
}
