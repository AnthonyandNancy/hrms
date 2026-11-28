<?php

declare(strict_types=1);

namespace Tests\Feature\Time;

use App\Modules\Time\Contracts\PayrollPeriodLockReader;
use App\Modules\Time\Models\OvertimePolicyVersion;
use App\Modules\Time\Models\OvertimeRequest;
use Illuminate\Support\Facades\Route;
use Tests\Support\Time\TimeHttpTestCase;
use Tests\Support\Time\TimeTestFixtures;

/**
 * M11 policy versioning and confirmation snapshot closure.
 *
 * routes/api.php is owned outside this module's write boundary, so the test
 * registers only the M11 overtime routes under test. This still exercises the
 * real controller, CommandRunner, validation, transaction and error envelope.
 */
final class OvertimePolicyVersionTest extends TimeHttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(PayrollPeriodLockReader::class, new class implements PayrollPeriodLockReader
        {
            public function isFrozen(int $employmentId, string $period): bool
            {
                return false;
            }
        });

        $this->people->add(TimeTestFixtures::employment(2));

        Route::prefix('v1')->middleware(['api', 'auth.session'])->group(function (): void {
            Route::get('/time/overtime/policies', [\App\Modules\Time\Http\Controllers\TimeCatalogController::class, 'overtimePolicies'])
                ->middleware('capability:time.overtime.read');
            Route::post('/time/overtime/policies', [\App\Modules\Time\Http\Controllers\TimeCatalogController::class, 'storeOvertimePolicy'])
                ->middleware('capability:time.overtime.manage');
            Route::post('/time/overtime/policies/{id}/retire', [\App\Modules\Time\Http\Controllers\TimeCatalogController::class, 'retireOvertimePolicy'])
                ->whereNumber('id')
                ->middleware('capability:time.overtime.manage');
            Route::get('/time/overtime/requests', [\App\Modules\Time\Http\Controllers\OvertimeRequestController::class, 'index'])
                ->middleware('capability:time.overtime.read');
            Route::post('/time/overtime/requests', [\App\Modules\Time\Http\Controllers\OvertimeRequestController::class, 'store'])
                ->middleware('capability:time.overtime.request');
            Route::post('/time/overtime/requests/{id}/confirm', [\App\Modules\Time\Http\Controllers\OvertimeRequestController::class, 'confirm'])
                ->whereNumber('id')
                ->middleware('capability:time.overtime.confirm');
        });
    }

    public function test_policy_append_is_versioned_locked_and_overlap_is_409(): void
    {
        $this->actAsHttp(TimeTestFixtures::actor(
            scope: 'COMPANY',
            capabilities: ['time.overtime.manage', 'time.overtime.read'],
        ));

        $created = $this->postJson('/v1/time/overtime/policies', [
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-12-31',
            'time_unit_minutes' => 30,
            'multiplier' => '1.500000',
            'statutory_holiday_multiplier' => '3.000000',
            'allow_pay' => true,
            'allow_timeoff' => true,
            'allocation' => 'EMPLOYEE_CHOICE',
            'rounding' => 'HALF_UP',
            'legal_basis' => 'Synthetic company overtime policy',
            'version' => 99,
        ], ['Idempotency-Key' => 'op-1'])->assertCreated()
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.multiplier', '1.500000')
            ->assertJsonPath('data.source', 'COMPANY_VERSION');

        $id = (int) $created->json('data.id');

        $this->postJson('/v1/time/overtime/policies', [
            'effective_from' => '2026-06-01',
            'effective_to' => '2027-06-01',
        ], ['Idempotency-Key' => 'op-2'])
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'OVERTIME_POLICY_RANGE_OVERLAP');

        $this->postJson('/v1/time/overtime/policies', [
            'effective_from' => '2027-01-01',
            'effective_to' => '2027-12-31',
        ], ['Idempotency-Key' => 'op-3'])->assertCreated()
            ->assertJsonPath('data.version', 2);

        $this->assertSame(2, OvertimePolicyVersion::query()->count());
        $this->assertDatabaseHas('overtime_policy_versions', [
            'id' => $id,
            'version' => 1,
            'status' => OvertimePolicyVersion::STATUS_PUBLISHED,
        ]);
    }

    public function test_retire_only_accepts_a_future_version_and_keeps_history(): void
    {
        $this->actAsHttp(TimeTestFixtures::actor(
            scope: 'COMPANY',
            capabilities: ['time.overtime.manage', 'time.overtime.read'],
        ));

        $effective = $this->postJson('/v1/time/overtime/policies', [
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-12-31',
        ], ['Idempotency-Key' => 'op-effective'])->assertCreated()->json('data.id');

        $this->postJson("/v1/time/overtime/policies/{$effective}/retire", [
            'reason' => 'Synthetic already effective',
        ], ['Idempotency-Key' => 'op-effective-retire'])
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'OVERTIME_POLICY_ALREADY_EFFECTIVE');

        $future = $this->postJson('/v1/time/overtime/policies', [
            'effective_from' => '2027-01-01',
            'effective_to' => '2027-12-31',
        ], ['Idempotency-Key' => 'op-future'])->assertCreated()->json('data.id');

        $retired = $this->postJson("/v1/time/overtime/policies/{$future}/retire", [
            'reason' => '制度换版',
        ], ['Idempotency-Key' => 'op-future-retire'])->assertOk()
            ->assertJsonPath('data.status', OvertimePolicyVersion::STATUS_RETIRED)
            ->assertJsonPath('data.retire_reason', '制度换版');

        $this->assertNotNull($retired->json('data.retired_at'));
        $this->assertDatabaseHas('overtime_policy_versions', [
            'id' => $effective,
            'status' => OvertimePolicyVersion::STATUS_PUBLISHED,
        ]);
    }

    public function test_policy_commands_require_manage_capability_and_idempotency_key(): void
    {
        $this->actAsHttp(TimeTestFixtures::actor(
            scope: 'COMPANY',
            capabilities: ['time.overtime.read'],
        ));

        $this->postJson('/v1/time/overtime/policies', [
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-12-31',
        ], ['Idempotency-Key' => 'op-forbidden'])->assertForbidden();

        $this->actAsHttp(TimeTestFixtures::actor(
            scope: 'COMPANY',
            capabilities: ['time.overtime.manage'],
        ));

        $this->postJson('/v1/time/overtime/policies', [
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-12-31',
        ])->assertStatus(422)
            ->assertJsonPath('data.reason', 'IDEMPOTENCY_KEY_REQUIRED');
    }

    public function test_confirm_snapshots_the_effective_company_policy_and_is_stable(): void
    {
        $this->seedPolicy('2026-01-01', '2026-12-31');
        $this->actingAsTimeEmployee();

        $requestId = (int) $this->postJson('/v1/time/overtime/requests', [
            'business_date' => '2026-11-20',
            'declared_minutes' => 120,
            'reason' => 'Synthetic support',
        ], ['Idempotency-Key' => 'ot-snap'])->assertCreated()->json('data.id');
        $this->postJson("/v1/time/overtime/requests/{$requestId}/approve", [
            'decision' => 'APPROVE',
            'comment' => 'Synthetic snapshot approval.',
        ], ['Idempotency-Key' => 'ot-snap-approve'])->assertOk();

        $confirmed = $this->postJson("/v1/time/overtime/requests/{$requestId}/confirm", [
            'confirmed_minutes' => 90,
        ], ['Idempotency-Key' => 'ot-snap-confirm'])->assertOk()
            ->assertJsonPath('data.policy_snapshot.version', 1)
            ->assertJsonPath('data.policy_snapshot.multiplier', '1.500000')
            ->assertJsonPath('data.policy_snapshot.statutory_holiday_multiplier', '3.000000')
            ->assertJsonPath('data.policy_snapshot.time_unit_minutes', 30)
            ->assertJsonPath('data.policy_snapshot.allocation', 'EMPLOYEE_CHOICE');

        $policyId = (int) $confirmed->json('data.policy_version_id');
        $this->assertGreaterThan(0, $policyId);

        // A later policy change must never rewrite the confirmation snapshot.
        // The service correctly refuses to retire an already-effective version,
        // so exercise the historical-immutability edge by appending a newer row
        // and verifying the request still points at its original version.
        OvertimePolicyVersion::query()->create([
            'version' => 2,
            'status' => OvertimePolicyVersion::STATUS_PUBLISHED,
            'effective_from' => '2027-01-01',
            'effective_to' => '2027-12-31',
            'time_unit_minutes' => 60,
            'multiplier' => '2.000000',
            'statutory_holiday_multiplier' => '3.000000',
            'allow_pay' => true,
            'allow_timeoff' => true,
            'allocation' => 'EMPLOYEE_CHOICE',
            'rounding' => 'HALF_UP',
            'legal_basis' => 'Synthetic newer policy',
            'published_at' => $this->clock->now(),
            'published_by' => 10,
            'created_at' => $this->clock->now(),
        ]);

        $this->postJson("/v1/time/overtime/requests/{$requestId}/confirm", [
            'confirmed_minutes' => 90,
        ], ['Idempotency-Key' => 'ot-snap-confirm-2'])
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'ALREADY_CONFIRMED');

        $row = OvertimeRequest::query()->findOrFail($requestId);
        $this->assertSame(1, (int) $row->policy_version_id);
        $this->assertSame('1.500000', $row->policy_snapshot_json['multiplier']);
    }

    public function test_confirm_without_a_company_policy_is_409_and_writes_no_record(): void
    {
        $this->actingAsTimeEmployee();

        $request = OvertimeRequest::query()->create([
            'employment_id' => 1,
            'business_date' => '2026-11-20',
            'declared_minutes' => 60,
            'confirmed_minutes' => null,
            'status' => OvertimeRequest::STATUS_APPROVED,
            'reason' => 'Synthetic unconfigured policy',
            'recorded_at' => $this->clock->now(),
        ]);

        $this->postJson("/v1/time/overtime/requests/{$request->id}/confirm", [
            'confirmed_minutes' => 60,
        ], ['Idempotency-Key' => 'ot-unconfigured'])
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'OVERTIME_POLICY_UNCONFIGURED');

        $this->assertDatabaseCount('overtime_records', 0);
        $this->assertDatabaseHas('overtime_requests', [
            'id' => $request->id,
            'status' => OvertimeRequest::STATUS_APPROVED,
            'policy_version_id' => null,
        ]);
    }

    public function test_team_queue_is_scope_derived_and_a_self_actor_cannot_request_it(): void
    {
        $this->seedPolicy('2026-01-01', '2026-12-31');

        foreach ([1, 2] as $employmentId) {
            OvertimeRequest::query()->create([
                'employment_id' => $employmentId,
                'business_date' => '2026-11-'.str_pad((string) (10 + $employmentId), 2, '0', STR_PAD_LEFT),
                'declared_minutes' => 60,
                'confirmed_minutes' => null,
                'status' => OvertimeRequest::STATUS_APPROVED,
                'reason' => 'Synthetic team queue row',
                'recorded_at' => $this->clock->now(),
            ]);
        }

        $this->actAsHttp(TimeTestFixtures::actor(
            employmentId: 1,
            scope: 'MANAGED',
            managedEmploymentIds: [2],
            capabilities: ['time.overtime.read'],
        ));

        $this->getJson('/v1/time/overtime/requests?view=team')
            ->assertOk()
            ->assertJsonPath('data.view', 'team')
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.employment_id', 2);

        $this->getJson('/v1/time/overtime/requests?view=team&employment_id=1')
            ->assertNotFound();

        $this->actAsHttp(TimeTestFixtures::actor(
            employmentId: 1,
            scope: 'SELF',
            capabilities: ['time.overtime.read'],
        ));

        $this->getJson('/v1/time/overtime/requests?view=team')->assertNotFound();
    }

    public function test_company_actor_team_queue_filters_dates_status_and_pagination(): void
    {
        $this->seedPolicy('2026-01-01', '2026-12-31');

        OvertimeRequest::query()->create([
            'employment_id' => 1,
            'business_date' => '2026-11-20',
            'declared_minutes' => 60,
            'confirmed_minutes' => null,
            'status' => OvertimeRequest::STATUS_APPROVED,
            'reason' => 'Synthetic approved row',
            'recorded_at' => $this->clock->now(),
        ]);
        OvertimeRequest::query()->create([
            'employment_id' => 2,
            'business_date' => '2026-11-21',
            'declared_minutes' => 60,
            'confirmed_minutes' => null,
            'status' => OvertimeRequest::STATUS_SUBMITTED,
            'reason' => 'Synthetic submitted row',
            'recorded_at' => $this->clock->now(),
        ]);

        $this->actAsHttp(TimeTestFixtures::actor(
            employmentId: 1,
            scope: 'COMPANY',
            capabilities: ['time.overtime.read'],
        ));

        $this->getJson('/v1/time/overtime/requests?view=team&status=APPROVED&from=2026-11-01&to=2026-11-30&page=1&page_size=10')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.status', OvertimeRequest::STATUS_APPROVED)
            ->assertJsonPath('data.page', 1)
            ->assertJsonPath('data.page_size', 10);
    }

    private function seedPolicy(string $from, string $to): OvertimePolicyVersion
    {
        return OvertimePolicyVersion::query()->create([
            'version' => 1,
            'status' => OvertimePolicyVersion::STATUS_PUBLISHED,
            'effective_from' => $from,
            'effective_to' => $to,
            'time_unit_minutes' => 30,
            'multiplier' => '1.500000',
            'statutory_holiday_multiplier' => '3.000000',
            'allow_pay' => true,
            'allow_timeoff' => true,
            'allocation' => 'EMPLOYEE_CHOICE',
            'rounding' => 'HALF_UP',
            'legal_basis' => 'Synthetic overtime policy',
            'published_at' => $this->clock->now(),
            'published_by' => 10,
            'created_at' => $this->clock->now(),
        ]);
    }
}
