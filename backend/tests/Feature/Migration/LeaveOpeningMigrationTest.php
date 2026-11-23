<?php

declare(strict_types=1);

namespace Tests\Feature\Migration;

use App\Modules\Platform\Migration\Adapters\DatabaseMigrationTarget;
use App\Modules\Platform\Migration\Dto\MigrationRow;
use App\Modules\Platform\Migration\Enums\MigrationDomain;
use App\Modules\Time\Models\LeaveLedgerEntry;
use App\Modules\Time\Models\LeaveType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PeopleScenario;
use Tests\TestCase;

/**
 * Live migration of leave opening balances. The adapter previously referenced a
 * non-existent `leave_ledger_entries.quantity_units` column, so the target
 * reported itself unavailable and `demo:seed --live` silently stayed in SHADOW
 * mode. It also used the quantity as an idempotency key, which would treat two
 * genuinely different openings as the same row.
 */
final class LeaveOpeningMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_leave_opening_target_is_available_and_writes_integer_minutes(): void
    {
        [$employmentNo, $leaveTypeId] = $this->fixture();

        $target = new DatabaseMigrationTarget(MigrationDomain::LEAVE_OPENINGS);

        $this->assertTrue($target->isAvailable(), 'leave_opening target should be available: '.$target->unavailableReason());

        $result = $target->apply($this->stagingRow([
            'employment_no' => $employmentNo,
            'leave_type' => 'ANNUAL',
            'quantity_minutes' => 4800,
            'business_date' => '2026-01-01',
            'effective_on' => '2026-01-01',
        ]), 1, 1);

        $this->assertSame('CREATED', $result->status);

        $entry = LeaveLedgerEntry::query()->whereKey($result->targetId)->firstOrFail();
        $this->assertSame(4800, (int) $entry->quantity_minutes);
        $this->assertSame('GRANT', (string) $entry->account);
        $this->assertSame('MIGRATION_OPENING', (string) $entry->source_type);
        $this->assertSame((int) $leaveTypeId, (int) $entry->leave_type_id);
    }

    public function test_replaying_the_same_opening_is_idempotent(): void
    {
        [$employmentNo] = $this->fixture();

        $target = new DatabaseMigrationTarget(MigrationDomain::LEAVE_OPENINGS);
        $row = $this->stagingRow([
            'employment_no' => $employmentNo,
            'leave_type' => 'ANNUAL',
            'quantity_minutes' => 4800,
            'business_date' => '2026-01-01',
            'effective_on' => '2026-01-01',
        ]);

        $first = $target->apply($row, 1, 1);
        // A second migration run gets a different staging id; the natural key
        // must still make the write idempotent.
        $second = $target->apply(
            $this->stagingRow([
                'employment_no' => $employmentNo,
                'leave_type' => 'ANNUAL',
                'quantity_minutes' => 4800,
                'business_date' => '2026-01-01',
                'effective_on' => '2026-01-01',
            ], 999),
            1,
            2,
        );

        $this->assertSame('CREATED', $first->status);
        $this->assertSame('SKIPPED', $second->status);
        $this->assertSame($first->targetId, $second->targetId);
        $this->assertSame(1, LeaveLedgerEntry::query()->count());
    }

    public function test_two_openings_with_different_expiry_dates_are_both_kept(): void
    {
        [$employmentNo] = $this->fixture();

        $target = new DatabaseMigrationTarget(MigrationDomain::LEAVE_OPENINGS);
        $base = [
            'employment_no' => $employmentNo,
            'leave_type' => 'ANNUAL',
            'business_date' => '2026-01-01',
        ];

        $first = $target->apply($this->stagingRow($base + [
            'quantity_minutes' => 4800,
            'effective_on' => '2026-12-31',
        ], 1), 1, 1);
        $second = $target->apply($this->stagingRow($base + [
            'quantity_minutes' => 2400,
            'effective_on' => '2027-12-31',
        ], 2), 1, 1);

        $this->assertSame('CREATED', $first->status);
        $this->assertSame('CREATED', $second->status);
        $this->assertNotSame($first->targetId, $second->targetId);
        $this->assertSame(2, LeaveLedgerEntry::query()->count());
    }

    public function test_metrics_sum_integer_minutes_for_migration_openings(): void
    {
        [$employmentNo] = $this->fixture();

        $target = new DatabaseMigrationTarget(MigrationDomain::LEAVE_OPENINGS);

        $target->apply($this->stagingRow([
            'employment_no' => $employmentNo,
            'leave_type' => 'ANNUAL',
            'quantity_minutes' => 4800,
            'business_date' => '2026-01-01',
            'effective_on' => '2026-12-31',
        ], 1), 1, 1);

        $metrics = $target->metrics(1);

        $this->assertSame(1, $metrics->count);
        $this->assertSame(4800, $metrics->sums['quantity_minutes']);
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function fixture(): array
    {
        $scenario = new PeopleScenario(1, app(\App\Shared\Time\Clock::class));
        $entity = $scenario->legalEntity();
        $unit = $scenario->orgUnit($entity, 'MIG-UNIT');
        $employment = $scenario->employment($scenario->person(), $entity, 'MIG-0001');
        $scenario->assignment($employment, $unit);

        $type = LeaveType::query()->create([
            'tenant_id' => 1,
            'code' => 'ANNUAL',
            'name' => 'Annual leave',
            'is_statutory' => true,
            'is_annual' => true,
            'unit' => 'DAY',
            'annual_entitlement_days' => '15.00',
            'requires_attachment' => false,
            'allow_negative' => false,
            'expiry_rule_json' => null,
            'legal_basis' => 'State Council Order 514',
            'created_at' => now('UTC'),
        ]);

        return ['MIG-0001', (int) $type->id];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function stagingRow(array $data, int $stgId = 1): MigrationRow
    {
        return new MigrationRow(stgId: $stgId, sourceRowNumber: $stgId, data: $data);
    }
}
