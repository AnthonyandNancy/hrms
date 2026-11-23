<?php

declare(strict_types=1);

namespace Tests\Feature\Time;

use App\Modules\Time\Exceptions\LeaveBalanceException;
use App\Modules\Time\Services\LeaveLedgerService;
use Tests\Support\Time\TimeTestCase;

class LeaveConcurrencyTest extends TimeTestCase
{
    public function test_two_requests_for_the_last_day_produce_one_success_and_no_negative_balance(): void
    {
        $type = $this->leaveType();
        $this->leaveAccount($type);
        app(LeaveLedgerService::class)->grant(1, 1, $type->id, 480, '2026-01-01', 'ACCRUAL', '2026', 'One day');

        $successes = 0;
        $rejections = 0;

        foreach (['case-a', 'case-b'] as $case) {
            try {
                app(LeaveLedgerService::class)->reserve(
                    tenantId: 1,
                    employmentId: 1,
                    leaveTypeId: $type->id,
                    minutes: 480,
                    businessDate: '2026-11-23',
                    sourceType: 'LEAVE_REQUEST',
                    sourceId: $case,
                    reason: 'Last day',
                );
                $successes++;
            } catch (LeaveBalanceException) {
                $rejections++;
            }
        }

        $this->assertSame(1, $successes);
        $this->assertSame(1, $rejections);
        $this->assertSame(0, app(\App\Modules\Time\Services\LeaveBalanceService::class)->available(1, 1, $type->id, '2026-11-23'));
        $this->assertGreaterThanOrEqual(0, app(\App\Modules\Time\Services\LeaveBalanceService::class)->available(1, 1, $type->id, '2026-11-23'));
    }

    public function test_ledger_unique_source_prevents_double_reserve(): void
    {
        $type = $this->leaveType();
        $this->leaveAccount($type);
        app(LeaveLedgerService::class)->grant(1, 1, $type->id, 4800, '2026-01-01', 'ACCRUAL', '2026', 'Opening');
        $ledger = app(LeaveLedgerService::class);

        $ledger->reserve(1, 1, $type->id, 480, '2026-11-23', 'LEAVE_REQUEST', 'case-same', 'First');
        $ledger->reserve(1, 1, $type->id, 480, '2026-11-23', 'LEAVE_REQUEST', 'case-same', 'First');

        $this->assertSame(1, \App\Modules\Time\Models\LeaveLedgerEntry::query()->where('account', 'RESERVE')->count());
    }
}
