<?php

declare(strict_types=1);

namespace Tests\Feature\Time;

use App\Modules\Time\Contracts\PayrollPeriodLockReader;
use App\Modules\Time\Models\OvertimePolicyVersion;
use App\Modules\Time\Models\OvertimeRecord;
use App\Modules\Time\Models\OvertimeRequest;
use Illuminate\Support\Facades\Route;
use Tests\Support\Time\TimeHttpTestCase;
use Tests\Support\Time\TimeTestFixtures;

/**
 * M11 frozen payroll settlement guard.
 *
 * Time must ask Payroll through PayrollPeriodLockReader; it never reads
 * payroll_runs or payroll_run_employees. The test adapter below is the only
 * source of freeze truth in this suite.
 */
final class OvertimeFrozenPeriodTest extends TimeHttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Default open period; each test rebinds before asserting freeze state.
        $this->bindFrozen(false);

        Route::prefix('v1')->middleware(['api', 'auth.session'])->group(function (): void {
            Route::post('/overtime/records/{recordId}/settlements', [\App\Modules\Time\Http\Controllers\OvertimeController::class, 'settle'])
                ->whereNumber('recordId')
                ->middleware('capability:time.overtime.settle');
            Route::get('/time/overtime/requests', [\App\Modules\Time\Http\Controllers\OvertimeRequestController::class, 'index'])
                ->middleware('capability:time.overtime.read');
        });
    }

    public function test_frozen_target_month_rejects_fresh_pay_settlement_but_allows_timeoff(): void
    {
        $this->actAsHttp(TimeTestFixtures::actor(
            employmentId: 1,
            capabilities: ['time.overtime.read', 'time.overtime.settle'],
        ));

        $payRecord = $this->record('2026-11-20');
        $timeoffRecord = $this->record('2026-11-21');
        $this->bindFrozen(true);

        $this->postJson("/v1/overtime/records/{$payRecord->id}/settlements", [
            'method' => 'PAY',
            'reason' => 'Synthetic frozen pay',
        ], ['Idempotency-Key' => 'ot-frozen-pay'])
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'PAYROLL_PERIOD_FROZEN')
            ->assertJsonPath('data.period', '2026-11');

        $this->assertDatabaseMissing('overtime_settlements', [
            'overtime_record_id' => (int) $payRecord->id,
        ]);

        $this->postJson("/v1/overtime/records/{$timeoffRecord->id}/settlements", [
            'method' => 'TIMEOFF',
            'reason' => 'Synthetic frozen pay switched to timeoff',
        ], ['Idempotency-Key' => 'ot-frozen-timeoff'])
            ->assertCreated()
            ->assertJsonPath('data.method', 'TIMEOFF');

        $this->assertSame('2026-11', app('overtime.freeze.last_period'));
    }

    public function test_open_period_accepts_pay_once_and_a_second_method_is_409(): void
    {
        $this->actAsHttp(TimeTestFixtures::actor(
            employmentId: 1,
            capabilities: ['time.overtime.read', 'time.overtime.settle'],
        ));
        $record = $this->record('2026-11-22');
        $this->bindFrozen(false);

        $this->postJson("/v1/overtime/records/{$record->id}/settlements", [
            'method' => 'PAY',
            'reason' => 'Synthetic open-period pay',
        ], ['Idempotency-Key' => 'ot-open-pay'])->assertCreated();

        $this->postJson("/v1/overtime/records/{$record->id}/settlements", [
            'method' => 'TIMEOFF',
            'reason' => 'Synthetic second method',
        ], ['Idempotency-Key' => 'ot-open-timeoff'])
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'ALREADY_SETTLED');

        $this->assertDatabaseCount('overtime_settlements', 1);
    }

    public function test_missing_freeze_adapter_fails_closed_for_pay_but_not_timeoff(): void
    {
        $this->actAsHttp(TimeTestFixtures::actor(
            employmentId: 1,
            capabilities: ['time.overtime.read', 'time.overtime.settle'],
        ));
        $this->app->forgetInstance(PayrollPeriodLockReader::class);

        $payRecord = $this->record('2026-11-23');
        $timeoffRecord = $this->record('2026-11-24');

        $this->postJson("/v1/overtime/records/{$payRecord->id}/settlements", [
            'method' => 'PAY',
            'reason' => 'Synthetic unavailable freeze adapter',
        ], ['Idempotency-Key' => 'ot-missing-reader-pay'])
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'PAYROLL_PERIOD_FROZEN');

        $this->postJson("/v1/overtime/records/{$timeoffRecord->id}/settlements", [
            'method' => 'TIMEOFF',
            'reason' => 'Synthetic unavailable freeze adapter timeoff',
        ], ['Idempotency-Key' => 'ot-missing-reader-timeoff'])->assertCreated();
    }

    public function test_confirmed_request_reports_freeze_state_without_reading_payroll_tables(): void
    {
        OvertimePolicyVersion::query()->create([
            'version' => 1,
            'status' => OvertimePolicyVersion::STATUS_PUBLISHED,
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-12-31',
            'time_unit_minutes' => 30,
            'multiplier' => '1.500000',
            'statutory_holiday_multiplier' => '3.000000',
            'allow_pay' => true,
            'allow_timeoff' => true,
            'allocation' => 'EMPLOYEE_CHOICE',
            'rounding' => 'HALF_UP',
            'published_at' => $this->clock->now(),
            'published_by' => 10,
            'created_at' => $this->clock->now(),
        ]);

        OvertimeRequest::query()->create([
            'employment_id' => 1,
            'business_date' => '2026-11-25',
            'declared_minutes' => 60,
            'confirmed_minutes' => 60,
            'status' => OvertimeRequest::STATUS_CONFIRMED,
            'reason' => 'Synthetic freeze projection',
            'policy_version_id' => 1,
            'policy_snapshot_json' => ['version' => 1, 'multiplier' => '1.500000'],
            'recorded_at' => $this->clock->now(),
        ]);

        $this->bindFrozen(true);
        $this->actAsHttp(TimeTestFixtures::actor(
            employmentId: 1,
            capabilities: ['time.overtime.read', 'time.overtime.settle'],
        ));

        $this->getJson('/v1/time/overtime/requests?as_of=2026-11-30')
            ->assertOk()
            ->assertJsonPath('data.items.0.payroll_period_frozen', true)
            ->assertJsonPath('data.items.0.policy_snapshot.multiplier', '1.500000');
    }

    private function bindFrozen(bool $frozen): void
    {
        $this->app->instance(PayrollPeriodLockReader::class, new class($frozen) implements PayrollPeriodLockReader
        {
            public function __construct(private bool $frozen) {}

            public function isFrozen(int $employmentId, string $period): bool
            {
                app()->instance('overtime.freeze.last_period', $period);

                return $this->frozen;
            }
        });
    }

    private function record(string $businessDate): OvertimeRecord
    {
        return OvertimeRecord::query()->create([
            'employment_id' => 1,
            'business_date' => $businessDate,
            'minutes' => 120,
            'status' => 'APPROVED',
            'created_at' => $this->clock->now(),
        ]);
    }
}
