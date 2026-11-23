<?php

declare(strict_types=1);

namespace Tests\Unit\Time;

use App\Modules\Time\Exceptions\LeaveBalanceException;
use App\Modules\Time\Models\LeaveLedgerEntry;
use App\Modules\Time\Services\LeaveEquation;
use Tests\TestCase;

class LeaveEquationTest extends TestCase
{
    public function test_equation_accounts_for_every_ledger_account(): void
    {
        $entries = [
            $this->entry('GRANT', 4800),
            $this->entry('ADJUST', -480),
            $this->entry('RESERVE', -960),
            $this->entry('CONSUME', -1440),
            $this->entry('RELEASE', 960),
            $this->entry('EXPIRE', -1200),
            $this->entry('REVERSE', 480),
        ];

        $summary = LeaveEquation::summarize($entries);

        $this->assertSame(4800, $summary['granted']);
        $this->assertSame(960, $summary['reserved']);
        $this->assertSame(1440, $summary['consumed']);
        $this->assertSame(960, $summary['released']);
        $this->assertSame(1200, $summary['expired']);
        $this->assertSame(-480, $summary['adjusted']);
        $this->assertSame(480, $summary['reversed']);
        $this->assertSame(4800 - 960 - 1440 + 960 - 1200 - 480 + 480, $summary['balance']);
        $this->assertSame($summary['balance'], $summary['available']);
        $this->assertSame(0, $summary['unexplained_difference']);
    }

    public function test_available_excludes_reserve_and_balance_is_total(): void
    {
        $summary = LeaveEquation::summarize([
            $this->entry('GRANT', 4800),
            $this->entry('RESERVE', -960),
        ]);

        $this->assertSame(3840, $summary['balance']);
        $this->assertSame(3840, $summary['available']);
    }

    public function test_negative_quantity_is_rejected(): void
    {
        $this->expectException(LeaveBalanceException::class);

        LeaveEquation::assertDirection('RESERVE', 1);
    }

    public function test_balance_can_be_asserted_exact(): void
    {
        $summary = LeaveEquation::summarize([$this->entry('GRANT', 480)]);

        LeaveEquation::assertNonNegative($summary, allowNegative: false);
        $this->assertSame(0, $summary['unexplained_difference']);
    }

    public function test_negative_available_without_permission_is_rejected(): void
    {
        $this->expectException(LeaveBalanceException::class);

        LeaveEquation::assertNonNegative(
            LeaveEquation::summarize([$this->entry('GRANT', 480), $this->entry('CONSUME', -960)]),
            allowNegative: false,
        );
    }

    private function entry(string $account, int $quantity): LeaveLedgerEntry
    {
        $entry = new LeaveLedgerEntry([
            'account' => $account,
            'quantity_minutes' => $quantity,
            'tenant_id' => 1,
            'employment_id' => 1,
            'leave_type_id' => 1,
            'business_date' => '2026-11-23',
            'effective_on' => '2026-11-23',
            'source_type' => 'TEST',
            'source_id' => '1',
            'reason' => 'test',
            'recorded_at' => '2026-11-23 09:00:00',
        ]);

        return $entry;
    }
}
