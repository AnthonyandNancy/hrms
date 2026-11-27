<?php

declare(strict_types=1);

namespace Tests\Feature\Time;

use App\Modules\Time\Contracts\PayrollPeriodLockReader;
use App\Modules\Time\Models\LeaveLedgerEntry;
use App\Modules\Time\Models\LeavePolicyVersion;
use App\Modules\Time\Models\LeaveRequestProjection;
use App\Modules\Time\Services\LeaveApprovalService;
use App\Modules\Time\Services\LeaveLedgerService;
use Illuminate\Support\Facades\Route;
use Tests\Support\Time\TimeHttpTestCase;
use Tests\Support\Time\TimeTestFixtures;

/**
 * M10 revoke state machine and payroll freeze protection.
 *
 * Approvals are applied through the Time-side LeaveApprovalService; the real
 * Workflow effect binding is tracked as an integration item rather than being
 * hidden inside this module.
 */
final class LeaveRevokeFreezeTest extends TimeHttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::prefix('v1')->middleware(['api', 'auth.session'])->group(function (): void {
            Route::post('/time/leave/requests', [\App\Modules\Time\Http\Controllers\LeaveController::class, 'request'])
                ->middleware('capability:time.leave.request');
            Route::post('/time/leave/requests/{caseId}/cancel', [\App\Modules\Time\Http\Controllers\LeaveController::class, 'cancel'])
                ->whereNumber('caseId')
                ->middleware('capability:time.leave.request.cancel');
        });
    }

    private function policy(int $typeId): LeavePolicyVersion
    {
        return LeavePolicyVersion::query()->create([
            'leave_type_id' => $typeId,
            'version' => 1,
            'status' => 'PUBLISHED',
            'effective_from' => '2026-01-01',
            'effective_to' => '9999-12-31',
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

    private function approve(int $caseId): void
    {
        $this->workflow->approve($caseId);
        app(LeaveApprovalService::class)->applyApprovedCase($caseId, 10);
    }

    public function test_pending_request_cancel_releases_once_and_replays_without_ledger_change(): void
    {
        $type = $this->leaveType();
        $this->policy((int) $type->id);
        $this->leaveAccount($type);
        app(LeaveLedgerService::class)->grant(1, $type->id, 4800, '2026-01-01', 'ACCRUAL', '2026', 'Opening');
        $this->actAsHttp(TimeTestFixtures::actor(employmentId: 1, scope: 'SELF'));

        $created = $this->postJson('/v1/time/leave/requests', [
            'leaveTypeCode' => 'ANNUAL',
            'startDate' => '2026-12-01',
            'endDate' => '2026-12-01',
            'reason' => 'Synthetic pending cancellation.',
        ], $this->headers('cancel-create'))->assertStatus(201)->json('data');
        $caseId = (int) $created['caseId'];

        $this->postJson("/v1/time/leave/requests/{$caseId}/cancel", [
            'reason' => 'Synthetic requester cancellation.',
            'revoke' => false,
        ], $this->headers('cancel-1'))
            ->assertOk()
            ->assertJsonPath('data.state', 'CANCELLED')
            ->assertJsonPath('data.releasedMinutes', 480);

        $this->postJson("/v1/time/leave/requests/{$caseId}/cancel", [
            'reason' => 'Synthetic requester cancellation.',
            'revoke' => false,
        ], $this->headers('cancel-2'))
            ->assertOk()
            ->assertJsonPath('data.state', 'CANCELLED')
            ->assertJsonPath('data.releasedMinutes', 0);

        $this->assertSame(1, LeaveLedgerEntry::query()
            ->where('case_id', $caseId)
            ->where('account', 'RELEASE')
            ->count());
        $this->assertSame('CANCELLED', (string) LeaveRequestProjection::query()->where('case_id', $caseId)->value('state'));
    }

    public function test_revoke_approved_leave_reverses_consumption_and_documents_actor_subject(): void
    {
        $this->app->instance(PayrollPeriodLockReader::class, new class implements PayrollPeriodLockReader
        {
            public function isFrozen(int $employmentId, string $period): bool
            {
                return false;
            }
        });

        $type = $this->leaveType();
        $this->policy((int) $type->id);
        $this->leaveAccount($type);
        app(LeaveLedgerService::class)->grant(1, $type->id, 4800, '2026-01-01', 'ACCRUAL', '2026', 'Opening');
        $this->actAsHttp(TimeTestFixtures::actor(employmentId: 1, scope: 'SELF'));

        $created = $this->postJson('/v1/time/leave/requests', [
            'leaveTypeCode' => 'ANNUAL',
            'startDate' => '2026-12-01',
            'endDate' => '2026-12-02',
            'reason' => 'Synthetic approved revoke.',
        ], $this->headers('revoke-create'))->assertStatus(201)->json('data');
        $caseId = (int) $created['caseId'];
        $this->approve($caseId);

        $before = LeaveLedgerEntry::query()->where('case_id', $caseId)->orderBy('id')->get()
            ->map(static fn (LeaveLedgerEntry $entry): array => [$entry->account, (int) $entry->quantity_minutes])
            ->all();
        $this->assertContains(['RESERVE', -960], $before);
        $this->assertContains(['RELEASE', 960], $before);
        $this->assertContains(['CONSUME', -960], $before);

        $this->postJson("/v1/time/leave/requests/{$caseId}/cancel", [
            'reason' => 'Synthetic approved revoke.',
            'revoke' => true,
        ], $this->headers('revoke-1'))
            ->assertOk()
            ->assertJsonPath('data.state', 'REVOKED')
            ->assertJsonPath('data.reversedMinutes', 960);

        $this->assertSame(1, LeaveLedgerEntry::query()
            ->where('case_id', $caseId)
            ->where('account', 'REVERSE')
            ->where('source_type', 'LEAVE_REVOKE')
            ->count());
        $this->assertSame('REVOKED', (string) LeaveRequestProjection::query()->where('case_id', $caseId)->value('state'));
    }

    public function test_revoke_fails_closed_when_payroll_lock_reader_is_unbound(): void
    {
        $type = $this->leaveType();
        $this->policy((int) $type->id);
        $this->leaveAccount($type);
        app(LeaveLedgerService::class)->grant(1, $type->id, 4800, '2026-01-01', 'ACCRUAL', '2026', 'Opening');
        $this->actAsHttp(TimeTestFixtures::actor(employmentId: 1, scope: 'SELF'));

        $created = $this->postJson('/v1/time/leave/requests', [
            'leaveTypeCode' => 'ANNUAL',
            'startDate' => '2026-12-01',
            'endDate' => '2026-12-01',
            'reason' => 'Synthetic frozen revoke.',
        ], $this->headers('freeze-create'))->assertStatus(201)->json('data');
        $caseId = (int) $created['caseId'];
        $this->approve($caseId);

        $this->postJson("/v1/time/leave/requests/{$caseId}/cancel", [
            'reason' => 'Synthetic frozen revoke.',
            'revoke' => true,
        ], $this->headers('freeze-revoke'))
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'LEAVE_PERIOD_FROZEN')
            ->assertJsonPath('data.failure', 'PAYROLL_LOCK_READER_UNAVAILABLE');

        $this->assertSame('APPROVED', (string) LeaveRequestProjection::query()->where('case_id', $caseId)->value('state'));
        $this->assertSame(0, LeaveLedgerEntry::query()->where('case_id', $caseId)->where('account', 'REVERSE')->count());
    }

    public function test_revoke_fails_closed_when_payroll_lock_reader_throws(): void
    {
        $this->app->instance(PayrollPeriodLockReader::class, new class implements PayrollPeriodLockReader
        {
            public function isFrozen(int $employmentId, string $period): bool
            {
                throw new \RuntimeException('Synthetic payroll outage.');
            }
        });

        $type = $this->leaveType();
        $this->policy((int) $type->id);
        $this->leaveAccount($type);
        app(LeaveLedgerService::class)->grant(1, $type->id, 4800, '2026-01-01', 'ACCRUAL', '2026', 'Opening');
        $this->actAsHttp(TimeTestFixtures::actor(employmentId: 1, scope: 'SELF'));

        $created = $this->postJson('/v1/time/leave/requests', [
            'leaveTypeCode' => 'ANNUAL',
            'startDate' => '2026-12-01',
            'endDate' => '2026-12-01',
            'reason' => 'Synthetic payroll outage revoke.',
        ], $this->headers('outage-create'))->assertStatus(201)->json('data');
        $caseId = (int) $created['caseId'];
        $this->approve($caseId);

        $this->postJson("/v1/time/leave/requests/{$caseId}/cancel", [
            'reason' => 'Synthetic payroll outage revoke.',
            'revoke' => true,
        ], $this->headers('outage-revoke'))
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'LEAVE_PERIOD_FROZEN')
            ->assertJsonPath('data.failure', 'PAYROLL_LOCK_READ_FAILED');
    }

    public function test_revoke_is_rejected_when_payroll_period_is_frozen(): void
    {
        $this->app->instance(PayrollPeriodLockReader::class, new class implements PayrollPeriodLockReader
        {
            public function isFrozen(int $employmentId, string $period): bool
            {
                return $period === '2026-12';
            }
        });

        $type = $this->leaveType();
        $this->policy((int) $type->id);
        $this->leaveAccount($type);
        app(LeaveLedgerService::class)->grant(1, $type->id, 4800, '2026-01-01', 'ACCRUAL', '2026', 'Opening');
        $this->actAsHttp(TimeTestFixtures::actor(employmentId: 1, scope: 'SELF'));

        $created = $this->postJson('/v1/time/leave/requests', [
            'leaveTypeCode' => 'ANNUAL',
            'startDate' => '2026-12-01',
            'endDate' => '2026-12-01',
            'reason' => 'Synthetic frozen period.',
        ], $this->headers('frozen-create'))->assertStatus(201)->json('data');
        $caseId = (int) $created['caseId'];
        $this->approve($caseId);

        $this->postJson("/v1/time/leave/requests/{$caseId}/cancel", [
            'reason' => 'Synthetic frozen period revoke.',
            'revoke' => true,
        ], $this->headers('frozen-revoke'))
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'LEAVE_PERIOD_FROZEN')
            ->assertJsonPath('data.period', '2026-12');
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
