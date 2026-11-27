<?php

declare(strict_types=1);

namespace Tests\Feature\Time;

use App\Modules\Time\Models\LeavePolicyVersion;
use App\Modules\Time\Models\LeaveRequestProjection;
use App\Modules\Time\Services\LeaveLedgerService;
use App\Modules\Time\Services\LeavePolicyService;
use App\Shared\Exceptions\ConflictException;
use Illuminate\Support\Facades\Route;
use Tests\Support\Time\TimeHttpTestCase;
use Tests\Support\Time\TimeTestFixtures;

/**
 * M10 closure tests: policy append/overlap/retire, self vs on-behalf subject
 * separation, calendar overlap validation and rule-driven conflicts.
 */
final class LeavePolicyAndOnBehalfTest extends TimeHttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // routes/api.php is the route owner and is intentionally outside this
        // module's write boundary. Register only the M10 routes under test so
        // the controller/capability behaviour is still exercised end to end.
        Route::prefix('v1')->middleware(['api', 'auth.session'])->group(function (): void {
            Route::get('/time/leave/types', [\App\Modules\Time\Http\Controllers\LeaveTypeController::class, 'index'])
                ->middleware('capability:time.leave.read');
            Route::patch('/time/leave/types/{id}', [\App\Modules\Time\Http\Controllers\LeaveTypeController::class, 'update'])
                ->whereNumber('id')
                ->middleware('capability:time.leave.type.manage');
            Route::post('/time/leave/requests', [\App\Modules\Time\Http\Controllers\LeaveController::class, 'request'])
                ->middleware('capability:time.leave.request');
            Route::post('/time/leave/requests/on-behalf', [\App\Modules\Time\Http\Controllers\LeaveOnBehalfController::class, 'store'])
                ->middleware('capability:time.leave.request.on-behalf');
            Route::post('/time/leave/preview', [\App\Modules\Time\Http\Controllers\LeaveController::class, 'preview'])
                ->middleware('capability:time.leave.read');
        });
    }

    private function policy(int $leaveTypeId, string $from = '2026-01-01', string $to = '9999-12-31', int $version = 1): LeavePolicyVersion
    {
        return LeavePolicyVersion::query()->create([
            'leave_type_id' => $leaveTypeId,
            'version' => $version,
            'status' => 'PUBLISHED',
            'effective_from' => $from,
            'effective_to' => $to,
            'applies_to_json' => ['scope' => 'COMPANY'],
            'accrual_json' => ['mode' => 'ANNUAL_FIXED', 'minutes_per_year' => 4800],
            'carryover_json' => null,
            'min_notice_days' => 0,
            'max_consecutive_days' => null,
            'requires_attachment_over_days' => null,
            'allow_half_day' => true,
            'legal_basis' => null,
            'published_at' => $this->clock->now(),
            'published_by' => 10,
            'created_at' => $this->clock->now(),
        ]);
    }

    public function test_policy_append_allocates_next_version_and_rejects_overlap_atomically(): void
    {
        $type = $this->leaveType();
        app(LeavePolicyService::class)->append([
            'leave_type_id' => (int) $type->id,
            'effective_from' => '2027-01-01',
            'effective_to' => '2027-12-31',
            'accrual' => ['mode' => 'ANNUAL_FIXED', 'minutes_per_year' => 4800],
        ], 10);
        app(LeavePolicyService::class)->append([
            'leave_type_id' => (int) $type->id,
            'effective_from' => '2028-01-01',
            'effective_to' => '2028-12-31',
            'accrual' => ['mode' => 'ANNUAL_FIXED', 'minutes_per_year' => 4800],
        ], 10);

        $this->assertSame(
            [1, 2],
            LeavePolicyVersion::query()->where('leave_type_id', $type->id)->orderBy('version')->pluck('version')->all(),
        );

        try {
            app(LeavePolicyService::class)->append([
                'leave_type_id' => (int) $type->id,
                'effective_from' => '2027-06-01',
                'effective_to' => '2027-07-01',
                'accrual' => ['mode' => 'ANNUAL_FIXED', 'minutes_per_year' => 2400],
            ], 10);
            $this->fail('Overlapping policy window must be rejected.');
        } catch (ConflictException $exception) {
            $this->assertSame('LEAVE_POLICY_RANGE_OVERLAP', $exception->details()['reason']);
        }

        $this->assertSame(2, LeavePolicyVersion::query()->where('leave_type_id', $type->id)->count());
    }

    public function test_retire_is_limited_to_not_yet_effective_versions(): void
    {
        $type = $this->leaveType();
        $future = $this->policy((int) $type->id, '2027-01-01', '2027-12-31');

        $retired = app(LeavePolicyService::class)->retire((int) $future->id, 'Superseded by a later policy window.', 10);
        $this->assertSame('RETIRED', $retired['status']);
        $this->assertNotNull(LeavePolicyVersion::query()->whereKey($future->id)->value('retired_at'));

        $effective = $this->policy((int) $type->id, '2026-01-01', '2026-12-31', 2);
        $this->expectException(ConflictException::class);
        app(LeavePolicyService::class)->retire((int) $effective->id, 'Try to retire an active version.', 10);
    }

    public function test_leave_type_code_and_unit_are_immutable_and_in_use_type_cannot_be_disabled(): void
    {
        $type = $this->leaveType();
        $this->leaveAccount($type);
        $this->actAsHttp(TimeTestFixtures::actor(
            capabilities: ['time.leave.read', 'time.leave.type.manage'],
        ));

        $this->patchJson('/v1/time/leave/types/'.$type->id, ['code' => 'CHANGED'], $this->headers('type-code'))
            ->assertStatus(422);

        $this->patchJson('/v1/time/leave/types/'.$type->id, ['unit' => 'HOUR'], $this->headers('type-unit'))
            ->assertStatus(422);

        $this->patchJson('/v1/time/leave/types/'.$type->id, ['status' => 'INACTIVE'], $this->headers('type-status'))
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'LEAVE_TYPE_IN_USE');
    }

    public function test_on_behalf_requires_explicit_subject_and_records_actor_separately(): void
    {
        $type = $this->leaveType();
        $this->policy((int) $type->id);
        $this->leaveAccount($type);
        $this->leaveAccount($type, 2);
        app(LeaveLedgerService::class)->grant(1, (int) $type->id, 4800, '2026-01-01', 'ACCRUAL', '2026', 'Opening');
        app(LeaveLedgerService::class)->grant(2, (int) $type->id, 4800, '2026-01-01', 'ACCRUAL', '2026', 'Opening');
        $this->people->add(TimeTestFixtures::employment(2));
        $this->people->bindUser(20, 2);

        $actor = TimeTestFixtures::actor(
            userId: 10,
            employmentId: 1,
            scope: 'COMPANY',
            capabilities: [
                'time.leave.read',
                'time.leave.request',
                'time.leave.request.cancel',
                'time.leave.admin',
                'time.leave.request.on-behalf',
                'time.leave.type.manage',
                'time.leave.policy.manage',
                'time.leave.policy.read',
                'time.leave.ledger.read',
            ],
        );
        $this->actAsHttp($actor);

        $this->postJson('/v1/time/leave/requests/on-behalf', [
            'employment_id' => 2,
            'leaveTypeCode' => 'ANNUAL',
            'startDate' => '2026-12-01',
            'endDate' => '2026-12-02',
            'halfDay' => false,
            'reason' => 'Synthetic on-behalf request.',
        ], $this->headers('on-behalf-1'))
            ->assertStatus(201)
            ->assertJsonPath('data.actor_user_id', 10)
            ->assertJsonPath('data.subject_employment_id', 2);

        $projection = LeaveRequestProjection::query()->where('employment_id', 2)->firstOrFail();
        $this->assertSame(10, (int) $projection->actor_user_id);
        $this->assertSame(2, (int) $projection->employment_id);
    }

    public function test_self_submit_ignores_supplied_employment_id_and_uses_session_employment(): void
    {
        $type = $this->leaveType();
        $this->policy((int) $type->id);
        $this->leaveAccount($type);
        app(LeaveLedgerService::class)->grant(1, (int) $type->id, 4800, '2026-01-01', 'ACCRUAL', '2026', 'Opening');
        $this->people->add(TimeTestFixtures::employment(2));
        $this->actAsHttp(TimeTestFixtures::actor(employmentId: 1, scope: 'SELF'));

        $this->postJson('/v1/time/leave/requests', [
            'employment_id' => 2,
            'leaveTypeCode' => 'ANNUAL',
            'startDate' => '2026-12-01',
            'endDate' => '2026-12-01',
            'halfDay' => false,
            'reason' => 'Synthetic self request.',
        ], $this->headers('self-1'))
            ->assertStatus(201);

        $this->assertSame(0, LeaveRequestProjection::query()->where('employment_id', 2)->count());
        $this->assertSame(1, LeaveRequestProjection::query()->where('employment_id', 1)->count());
    }

    public function test_calendar_working_days_and_overlap_are_validated_server_side(): void
    {
        $this->calendar();
        $type = $this->leaveType();
        $this->policy((int) $type->id);
        $this->leaveAccount($type);
        app(LeaveLedgerService::class)->grant(1, (int) $type->id, 4800, '2026-01-01', 'ACCRUAL', '2026', 'Opening');
        $this->actAsHttp(TimeTestFixtures::actor(employmentId: 1, scope: 'SELF'));

        // 2026-12-01 Tuesday, 2026-12-05 Saturday.
        $preview = $this->postJson('/v1/time/leave/preview', [
            'leaveTypeCode' => 'ANNUAL',
            'startDate' => '2026-12-01',
            'endDate' => '2026-12-05',
        ], $this->headers('preview-1'))
            ->assertOk()
            ->json('data');
        $this->assertSame(4, $preview['workingDays']);

        $this->postJson('/v1/time/leave/requests', [
            'leaveTypeCode' => 'ANNUAL',
            'startDate' => '2026-12-01',
            'endDate' => '2026-12-02',
            'reason' => 'Synthetic overlap first.',
        ], $this->headers('overlap-1'))->assertStatus(201);

        $this->postJson('/v1/time/leave/requests', [
            'leaveTypeCode' => 'ANNUAL',
            'startDate' => '2026-12-02',
            'endDate' => '2026-12-03',
            'reason' => 'Synthetic overlap second.',
        ], $this->headers('overlap-2'))
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'LEAVE_REQUEST_OVERLAP');
    }

    public function test_unpublished_policy_blocks_application_with_stable_reason(): void
    {
        $type = $this->leaveType();
        $this->leaveAccount($type);
        $this->actAsHttp(TimeTestFixtures::actor(employmentId: 1, scope: 'SELF'));

        $this->postJson('/v1/time/leave/requests', [
            'leaveTypeCode' => 'ANNUAL',
            'startDate' => '2026-12-01',
            'endDate' => '2026-12-01',
            'reason' => 'Synthetic no-policy request.',
        ], $this->headers('no-policy-1'))
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'LEAVE_POLICY_UNPUBLISHED');
    }

    /**
     * @return array<string, string>
     */
    private function headers(string $idempotencyKey): array
    {
        return [
            'Idempotency-Key' => $idempotencyKey,
            'Accept' => 'application/json',
        ];
    }
}
