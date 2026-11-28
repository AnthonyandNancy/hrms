<?php

declare(strict_types=1);

namespace Tests\Feature\Time;

use App\Modules\Time\Contracts\PayrollInputDirectory;
use App\Modules\Time\Contracts\PayrollPeriodLockReader;
use App\Modules\Time\Models\AttendancePeriodClosure;
use App\Modules\Time\Models\LeaveLedgerEntry;
use App\Modules\Time\Models\LeavePolicyVersion;
use App\Modules\Time\Models\LeaveRequestProjection;
use App\Modules\Time\Models\LeaveType;
use App\Modules\Time\Models\TimePayrollFactRow;
use App\Modules\Time\Models\TimePayrollFactVersion;
use App\Modules\Time\Services\BusinessCalendar;
use App\Modules\Time\Services\LeaveAccountService;
use App\Modules\Time\Services\LeaveLedgerService;
use App\Modules\Time\Services\LeavePolicyService;
use App\Modules\Time\Services\LeaveRequestService;
use App\Modules\Time\Services\TimeFactVersionService;
use App\Modules\Workflow\Contracts\AssigneeDirectory;
use App\Modules\Workflow\Enums\EffectStatus;
use App\Modules\Workflow\Models\Effect;
use App\Modules\Workflow\Services\EffectExecutor;
use App\Shared\Exceptions\ConflictException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeAssigneeDirectory;
use Tests\Support\WorkflowTestHelpers;
use Tests\TestCase;

/**
 * T07: leave approval and reversal are real Workflow domain effects.
 *
 * The cases below are opened and approved through the production Time ->
 * Workflow adapter and the real DecisionService. No test calls
 * LeaveApprovalService directly; its only entry point here is time.leave.apply.
 */
final class LeaveWorkflowEffectTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('hrms.default_timezone', 'Asia/Shanghai');
        $this->bootWorkflow();
        $this->clock->setNow(\Carbon\CarbonImmutable::parse('2026-12-10T09:00:00Z'));
        $this->artisan('workflow:seed-templates')->assertSuccessful();

        /** @var FakeAssigneeDirectory $assignees */
        $assignees = $this->app->make(AssigneeDirectory::class);
        $assignees->managers['employment:1'] = 20;

        app(BusinessCalendar::class)->configure('Asia/Shanghai', [1, 2, 3, 4, 5], 480);
        $this->bindOpenPayrollPeriods();
    }

    public function test_real_workflow_approval_posts_exactly_one_consume_through_the_domain_effect(): void
    {
        $type = $this->leaveType('ANNUAL');
        $this->policy((int) $type->id, 'PAID');
        $this->fundAccount($type);

        $caseId = $this->requestLeave((int) $type->id, 'ANNUAL', '2026-12-08', '2026-12-08');

        $effect = Effect::query()->where('case_id', $caseId)->firstOrFail();
        $this->assertSame('time.leave.apply', $effect->effect_type);
        $this->assertSame(EffectStatus::APPLIED, $effect->status);

        $this->assertSame(1, LeaveLedgerEntry::query()->where('case_id', $caseId)->where('account', 'CONSUME')->count());
        $this->assertSame(1, LeaveLedgerEntry::query()->where('case_id', $caseId)->where('account', 'RELEASE')->count());
        $this->assertDatabaseHas('leave_ledger_entries', [
            'case_id' => $caseId,
            'account' => 'CONSUME',
            'quantity_minutes' => -480,
            'source_type' => 'LEAVE_REQUEST',
        ]);
        $this->assertSame('APPROVED', (string) LeaveRequestProjection::query()->where('case_id', $caseId)->value('state'));
    }

    public function test_replaying_the_decision_and_effect_does_not_duplicate_consumption(): void
    {
        $type = $this->leaveType('ANNUAL');
        $this->policy((int) $type->id, 'PAID');
        $this->fundAccount($type);

        $caseId = $this->requestLeave((int) $type->id, 'ANNUAL', '2026-12-09', '2026-12-09');
        $effect = Effect::query()->where('case_id', $caseId)->firstOrFail();

        $this->actAs(20);
        $this->decideThroughApi($caseId, 'leave-approve-'.$caseId)->assertOk();
        $this->decideThroughApi($caseId, 'leave-approve-'.$caseId)
            ->assertOk()
            ->assertHeader('Idempotency-Replayed', 'true');
        app(EffectExecutor::class)->apply((int) $effect->id);
        app(EffectExecutor::class)->apply((int) $effect->id);

        $this->assertSame(1, Effect::query()->where('case_id', $caseId)->count());
        $this->assertSame(1, LeaveLedgerEntry::query()->where('case_id', $caseId)->where('account', 'CONSUME')->count());
        $this->assertSame(-480, (int) LeaveLedgerEntry::query()
            ->where('case_id', $caseId)
            ->where('account', 'CONSUME')
            ->value('quantity_minutes'));
    }

    public function test_revoke_writes_one_reverse_and_payroll_net_drops_to_zero_on_replay(): void
    {
        $type = $this->leaveType('UNPAID');
        $this->policy((int) $type->id, 'UNPAID');
        $this->fundAccount($type);

        $caseId = $this->requestLeave((int) $type->id, 'UNPAID', '2026-12-08', '2026-12-08');

        $directory = $this->app->make(PayrollInputDirectory::class);
        $before = $directory->attendanceForPeriod(1, '2026-12');
        $this->assertNotNull($before);
        $this->assertSame(480, $before->unpaidLeaveMinutes);
        $this->assertSame(0, $before->paidLeaveMinutes);

        $this->actAs(20);
        $first = app(LeaveRequestService::class)->cancel(
            caseId: $caseId,
            actorUserId: 20,
            reason: 'Synthetic management revocation.',
            revoke: true,
            managerOverride: true,
        );
        $this->assertSame('REVOKED', $first['state']);
        $this->assertSame(480, $first['reversed_minutes']);

        $replay = app(LeaveRequestService::class)->cancel(
            caseId: $caseId,
            actorUserId: 20,
            reason: 'Synthetic management revocation replay.',
            revoke: true,
            managerOverride: true,
        );
        $this->assertTrue($replay['replayed']);
        $this->assertSame(1, LeaveLedgerEntry::query()
            ->where('case_id', $caseId)
            ->where('account', 'REVERSE')
            ->where('source_type', 'LEAVE_REVOKE')
            ->count());
        $this->assertSame(480, (int) LeaveLedgerEntry::query()
            ->where('case_id', $caseId)
            ->where('account', 'REVERSE')
            ->value('quantity_minutes'));

        $after = $directory->attendanceForPeriod(1, '2026-12');
        $this->assertNotNull($after);
        $this->assertSame(0, $after->unpaidLeaveMinutes);
        $this->assertSame(0, $after->paidLeaveMinutes);
    }

    public function test_partial_and_unpaid_policies_enter_payroll_from_first_class_treatment_columns(): void
    {
        $partial = $this->leaveType('PARTIAL_CARE');
        $partialPolicyId = $this->policy((int) $partial->id, 'PARTIAL', 500_000);
        $this->fundAccount($partial);
        $this->requestLeave((int) $partial->id, 'PARTIAL_CARE', '2026-12-07', '2026-12-07');

        $unpaid = $this->leaveType('UNPAID_FAMILY');
        $this->policy((int) $unpaid->id, 'UNPAID');
        $this->fundAccount($unpaid);
        $this->requestLeave((int) $unpaid->id, 'UNPAID_FAMILY', '2026-12-08', '2026-12-08');

        $stored = LeavePolicyVersion::query()->whereKey($partialPolicyId)->firstOrFail();
        $this->assertSame('PARTIAL', strtoupper((string) $stored->getAttribute('payroll_treatment')));
        $this->assertSame(500_000, (int) $stored->getAttribute('paid_ratio_scaled'));

        $input = $this->app->make(PayrollInputDirectory::class)->attendanceForPeriod(1, '2026-12');
        $this->assertNotNull($input);
        $this->assertSame(240, $input->paidLeaveMinutes);
        $this->assertSame(720, $input->unpaidLeaveMinutes);
    }

    public function test_unresolved_policy_fails_closed_in_payroll_input(): void
    {
        $type = $this->leaveType('UNRESOLVED_CARE');
        $this->policy((int) $type->id, null);
        $this->fundAccount($type);

        $this->requestLeave((int) $type->id, 'UNRESOLVED_CARE', '2026-12-08', '2026-12-08');

        try {
            $this->app->make(PayrollInputDirectory::class)->attendanceForPeriod(1, '2026-12');
            $this->fail('Unresolved payroll treatment must not be guessed from a leave code.');
        } catch (ConflictException $exception) {
            $this->assertSame('LEAVE_PAYROLL_TREATMENT_UNRESOLVED', $exception->details()['reason'] ?? null);
        }
    }

    public function test_revoke_of_an_approved_leave_in_a_closed_month_returns_period_closed(): void
    {
        $type = $this->leaveType('ANNUAL');
        $this->policy((int) $type->id, 'PAID');
        $this->fundAccount($type);
        $caseId = $this->requestLeave((int) $type->id, 'ANNUAL', '2026-12-08', '2026-12-08');

        AttendancePeriodClosure::query()->create([
            'month' => '2026-12',
            'status' => AttendancePeriodClosure::STATUS_CLOSED,
            'closed_by' => 10,
            'closed_at' => $this->clock->now(),
            'created_at' => $this->clock->now(),
            'updated_at' => $this->clock->now(),
        ]);

        $this->actAs(20);

        try {
            app(LeaveRequestService::class)->cancel(
                caseId: $caseId,
                actorUserId: 20,
                reason: 'Synthetic closed-month revocation.',
                revoke: true,
                managerOverride: true,
            );
            $this->fail('A closed Time month must reject leave revocation.');
        } catch (ConflictException $exception) {
            $this->assertSame('PERIOD_CLOSED', $exception->details()['reason'] ?? null);
            $this->assertSame('2026-12', $exception->details()['period'] ?? null);
        }

        $this->assertSame(0, LeaveLedgerEntry::query()
            ->where('case_id', $caseId)
            ->where('account', 'REVERSE')
            ->count());
        $this->assertSame('APPROVED', (string) LeaveRequestProjection::query()->where('case_id', $caseId)->value('state'));
    }

    public function test_cross_month_projection_and_closed_fact_rows_do_not_double_count_net_minutes(): void
    {
        $type = $this->leaveType('ANNUAL');
        $this->policy((int) $type->id, 'PAID');
        $this->fundAccount($type);

        $caseId = $this->requestLeave((int) $type->id, 'ANNUAL', '2026-12-30', '2027-01-04');

        $this->assertSame(1, LeaveLedgerEntry::query()->where('case_id', $caseId)->where('account', 'CONSUME')->count());

        app(TimeFactVersionService::class)->close('2026-12', 10);
        app(TimeFactVersionService::class)->close('2027-01', 10);

        $december = $this->leaveFactMinutes('2026-12');
        $january = $this->leaveFactMinutes('2027-01');

        $this->assertSame([480, 480], $december);
        $this->assertSame([480, 480], $january);
        $this->assertSame(1920, array_sum($december) + array_sum($january));
        $this->assertSame(1, LeaveLedgerEntry::query()->where('case_id', $caseId)->where('account', 'CONSUME')->count());
    }

    private function leaveType(string $code): LeaveType
    {
        return LeaveType::query()->create([
            'code' => $code,
            'name' => 'Synthetic '.$code,
            'is_statutory' => false,
            'is_annual' => $code === 'ANNUAL',
            'unit' => 'DAY',
            'annual_entitlement_days' => '10.00',
            'requires_attachment' => false,
            'allow_negative' => false,
            'expiry_rule_json' => null,
            'legal_basis' => 'SYNTHETIC-TEST',
            'status' => 'ACTIVE',
            'version' => 1,
            'created_at' => $this->clock->now(),
            'updated_at' => $this->clock->now(),
        ]);
    }

    private function policy(int $leaveTypeId, ?string $treatment, ?int $ratio = null): int
    {
        $payload = [
            'leave_type_id' => $leaveTypeId,
            'effective_from' => '2026-01-01',
            'effective_to' => '9999-12-31',
            'accrual' => ['mode' => 'ANNUAL_FIXED', 'minutes_per_year' => 4800],
            'min_notice_days' => 0,
            'allow_half_day' => true,
        ];

        if ($treatment !== null) {
            $payload['payroll_treatment'] = $treatment;
        }

        if ($ratio !== null) {
            $payload['paid_ratio_scaled'] = $ratio;
        }

        return (int) app(LeavePolicyService::class)->append($payload, 10)['id'];
    }

    private function fundAccount(LeaveType $type): void
    {
        app(LeaveAccountService::class)->ensureAccount(1, (int) $type->id, '2026-01-01');
        app(LeaveLedgerService::class)->grant(
            employmentId: 1,
            leaveTypeId: (int) $type->id,
            minutes: 4800,
            businessDate: '2026-01-01',
            sourceType: 'ACCRUAL',
            sourceId: '2026',
            reason: 'Synthetic opening grant.',
        );
    }

    private function requestLeave(int $typeId, string $code, string $from, string $to): int
    {
        $this->actAs(10);
        $result = app(LeaveRequestService::class)->request(
            employmentId: 1,
            userId: 10,
            leaveTypeCode: $code,
            fromDate: $from,
            toDate: $to,
            halfDay: false,
            reason: 'Synthetic T07 workflow leave.',
        );

        $caseId = (int) $result['case_id'];
        $this->actAs(20);
        $this->decideThroughApi($caseId, 'leave-approve-'.$caseId)->assertOk();
        $this->assertSame(EffectStatus::APPLIED, Effect::query()->where('case_id', $caseId)->firstOrFail()->status);

        return $caseId;
    }

    private function bindOpenPayrollPeriods(): void
    {
        $this->app->instance(PayrollPeriodLockReader::class, new class implements PayrollPeriodLockReader
        {
            public function isFrozen(int $employmentId, string $period): bool
            {
                return false;
            }
        });
    }

    /**
     * @return list<int>
     */
    private function leaveFactMinutes(string $period): array
    {
        $versionId = (int) TimePayrollFactVersion::query()
            ->where('period', $period)
            ->where('status', TimePayrollFactVersion::STATUS_CLOSED)
            ->value('id');

        return TimePayrollFactRow::query()
            ->where('fact_version_id', $versionId)
            ->where('source_type', 'LEAVE_REQUEST')
            ->orderBy('business_date')
            ->get()
            ->map(static fn (TimePayrollFactRow $row): int => (int) $row->paid_leave_minutes)
            ->all();
    }
}
