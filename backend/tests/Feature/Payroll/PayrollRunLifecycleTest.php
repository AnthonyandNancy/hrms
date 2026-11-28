<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Modules\IAM\Auth\CapabilityAuthorizer;
use App\Modules\IAM\Models\AuthorityBinding;
use App\Modules\IAM\Models\User;
use App\Modules\Payroll\Authorization\EloquentPayrollRunMembershipDirectory;
use App\Modules\Payroll\Enums\PayrollRunStatus;
use App\Modules\Payroll\Models\PayrollInputSnapshot;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\PayrollRunMember;
use App\Modules\Payroll\Models\PayrollYtd;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Models\PayslipLine;
use App\Modules\Payroll\Services\ApprovalService;
use App\Modules\Payroll\Services\CalculationService;
use App\Modules\Payroll\Services\PayrollRunService;
use App\Shared\Authorization\AnchorRef;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\FieldProfile;
use App\Shared\Exceptions\ConflictException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\PayrollTestCase;

final class PayrollRunLifecycleTest extends PayrollTestCase
{
    public function test_run_members_are_persisted_and_preassigned_reviewer_can_discover_the_run(): void
    {
        $base = $this->seedPayrollBase();
        $runs = app(PayrollRunService::class);
        $run = $runs->createRun(10, [
            'scheme_id' => (int) $base['scheme']->id,
            'period' => '2026-06',
        ]);

        $this->assertDatabaseHas('payroll_run_members', [
            'run_id' => $run->id,
            'user_id' => 10,
            'member_role' => PayrollRunMember::ROLE_PREPARER,
        ]);

        $member = $runs->assignMember(
            runId: (int) $run->id,
            actorUserId: 10,
            targetUserId: 11,
            memberRole: PayrollRunMember::ROLE_REVIEWER,
            reason: 'Pre-assigned four-eyes reviewer.',
        );
        $this->assertSame(PayrollRunMember::ROLE_REVIEWER, $member['member_role']);

        $directory = new EloquentPayrollRunMembershipDirectory;
        $this->assertTrue($directory->isMember(11, (int) $run->id, 'payroll.run.read'));
        $this->assertContains((int) $run->id, $directory->runIdsForUser(11, '2026-11-23'));
        $this->assertContains((int) $run->id, $directory->runIdsForUser(10, '2026-11-23'));

        $detail = $runs->runDetail($run->refresh());
        $this->assertSame(2, count($detail['members']));
        $this->assertSame(1, PayrollRunMember::query()
            ->where('run_id', $run->id)
            ->where('user_id', 11)
            ->where('member_role', PayrollRunMember::ROLE_REVIEWER)
            ->count());
    }

    public function test_full_lifecycle_is_legal_and_seals_the_run(): void
    {
        $base = $this->seedPayrollBase();
        $runs = app(PayrollRunService::class);

        $run = $runs->createRun(10, [
            'scheme_id' => (int) $base['scheme']->id,
            'period' => '2026-01',
        ]);

        $this->assertSame(PayrollRunStatus::DRAFT, $run->status);

        $run = $runs->lockInputs((int) $run->id, 10);
        $this->assertSame(PayrollRunStatus::INPUT_LOCKED, $run->status);
        $hash = (string) $run->input_hash;
        $this->assertSame(64, strlen($hash));

        app(CalculationService::class)->calculate($run, 10);
        $run = $run->refresh();
        $this->assertSame(PayrollRunStatus::CALCULATED, $run->status);
        $this->assertGreaterThan(0, $run->gross_cents);
        $this->assertSame($run->gross_cents - $run->deductions_cents, $run->net_cents);

        $approval = app(ApprovalService::class);
        $run = $approval->review((int) $run->id, 11);
        $this->assertSame(PayrollRunStatus::REVIEWED, $run->status);

        $run = $approval->approve((int) $run->id, 12);
        $this->assertSame(PayrollRunStatus::APPROVED, $run->status);

        $run = $approval->publish((int) $run->id, 13);
        $this->assertSame(PayrollRunStatus::PUBLISHED, $run->status);

        $run = $approval->markPaid((int) $run->id, 13, 'DEMO-BANK-REF');
        $this->assertSame(PayrollRunStatus::PAID, $run->status);

        $run = $approval->close((int) $run->id, 13);
        $this->assertSame(PayrollRunStatus::CLOSED, $run->status);

        $this->assertDatabaseCount('payroll_ytd', 1);
        $ytd = PayrollYtd::query()->firstOrFail();
        $this->assertSame($run->gross_cents, $ytd->gross_cents);
    }

    public function test_illegal_transitions_are_409_conflicts(): void
    {
        $base = $this->seedPayrollBase();
        $run = app(PayrollRunService::class)->createRun(10, [
            'scheme_id' => (int) $base['scheme']->id,
            'period' => '2026-02',
        ]);

        $this->expectException(ConflictException::class);
        app(ApprovalService::class)->approve((int) $run->id, 12);
    }

    public function test_published_payslip_and_lines_are_immutable(): void
    {
        $base = $this->seedPayrollBase();
        $run = $this->publishedRun('2026-03');

        $payslip = Payslip::query()->where('run_id', $run->id)->firstOrFail();
        $this->assertNotNull($payslip->published_at);

        $this->expectException(\DomainException::class);
        $line = PayslipLine::query()->where('payslip_id', $payslip->id)->firstOrFail();
        $line->forceFill(['amount_cents' => (int) $line->amount_cents + 1])->save();
    }

    public function test_sealed_run_rejects_financial_updates_at_database_level(): void
    {
        $run = $this->publishedRun('2026-04');

        try {
            DB::table('payroll_runs')
                ->where('id', $run->id)
                ->update(['net_cents' => (int) $run->net_cents + 1]);
            $this->fail('Sealed payroll run was mutated.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('immutable', $exception->getMessage());
        }

        $this->assertSame((int) $run->net_cents, (int) PayrollRun::query()->whereKey($run->id)->firstOrFail()->net_cents);
    }

    public function test_snapshots_are_append_only_at_database_level(): void
    {
        $base = $this->seedPayrollBase();
        $run = app(PayrollRunService::class)->createRun(10, [
            'scheme_id' => (int) $base['scheme']->id,
            'period' => '2026-05',
        ]);
        $run = app(PayrollRunService::class)->lockInputs((int) $run->id, 10);
        $snapshot = PayrollInputSnapshot::query()->where('run_id', $run->id)->firstOrFail();

        try {
            DB::table('payroll_input_snapshots')
                ->where('id', $snapshot->id)
                ->update(['source_hash' => str_repeat('b', 64)]);
            $this->fail('Snapshot update was not blocked.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('append-only', $exception->getMessage());
        }

        $this->assertSame($snapshot->source_hash, PayrollInputSnapshot::query()->whereKey($snapshot->id)->firstOrFail()->source_hash);
    }

    public function test_preparer_cannot_approve_own_run(): void
    {
        $run = $this->reviewedRun('2026-06');

        $this->expectException(ConflictException::class);
        app(ApprovalService::class)->approve((int) $run->id, 10);
    }

    public function test_a_holder_of_prepare_and_approve_cannot_approve_their_own_run(): void
    {
        $fixture = $this->reviewedRunPreparedByDualCapabilityHolder('2026-08');
        $actorId = $fixture['actor_id'];
        $run = $fixture['run'];

        try {
            app(ApprovalService::class)->approve((int) $run->id, $actorId);
            $this->fail('A preparer holding both prepare and approve must not approve their own run.');
        } catch (ConflictException $exception) {
            $this->assertSame('SOD_CONFLICT', $exception->details()['reason']);
            $this->assertSame('approve', $exception->details()['step']);
            $this->assertSame(['preparer'], $exception->details()['conflicts_with']);
        }

        $this->assertSame(PayrollRunStatus::REVIEWED, $run->refresh()->status);
    }

    public function test_self_approval_by_a_dual_capability_holder_is_409_over_http(): void
    {
        $fixture = $this->reviewedRunPreparedByDualCapabilityHolder('2026-09');
        $actorId = $fixture['actor_id'];
        $run = $fixture['run'];

        // This suite's route gate is AllowAll; the capability premise is
        // proven above with the production authorizer, so the 409 below comes
        // from ApprovalService::assertSod, not from a middleware denial.
        $this->actAs($actorId);
        $this->postJson('/v1/payroll/runs/'.(int) $run->id.'/approve', [], [])
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'SOD_CONFLICT')
            ->assertJsonPath('data.step', 'approve')
            ->assertJsonPath('data.conflicts_with', ['preparer']);

        $this->assertSame(PayrollRunStatus::REVIEWED, $run->refresh()->status);
    }

    public function test_reviewer_cannot_approve_same_run(): void
    {
        $run = $this->reviewedRun('2026-07', reviewerId: 11);

        $this->expectException(ConflictException::class);
        app(ApprovalService::class)->approve((int) $run->id, 11);
    }

    /**
     * @return array{actor_id: int, run: PayrollRun}
     */
    private function reviewedRunPreparedByDualCapabilityHolder(string $period): array
    {
        $base = $this->seedPayrollBase();
        $actor = User::factory()->create(['auth_version' => 1]);
        $actorId = (int) $actor->id;
        $this->actAs($actorId);
        $this->grant($actorId, 'payroll.run.prepare');
        $this->grant($actorId, 'payroll.run.approve');

        // Prove the actor really holds both capabilities under the production
        // authorizer. This premise used to be rejected at the capability layer;
        // only the object-level control may now reject the approval.
        $principal = $this->actAs($actorId);
        $anchor = AnchorRef::company();
        $authorizer = app(CapabilityAuthorizer::class);
        $this->assertTrue($authorizer->allows($principal, 'payroll.run.prepare', $anchor, '2026-11-23'));
        $this->assertTrue($authorizer->allows($principal, 'payroll.run.approve', $anchor, '2026-11-23'));

        $run = $this->payrollRun((int) $base['scheme']->id, $period);
        $run->forceFill([
            'status' => PayrollRunStatus::REVIEWED,
            'prepared_by' => $actorId,
            'reviewed_by' => 11,
        ])->save();

        return ['actor_id' => $actorId, 'run' => $run];
    }

    private function grant(int $userId, string $capability): void
    {
        AuthorityBinding::factory()
            ->forPrincipal($userId, 1)
            ->anchor(AnchorType::COMPANY, null, FieldProfile::P2_SENSITIVE)
            ->create([
                'capability_code' => $capability,
                'valid_from' => '2025-01-01 00:00:00',
                'valid_to' => '2030-01-01 00:00:00',
            ]);
    }

    private function publishedRun(string $period, int $preparedBy = 10): PayrollRun
    {
        $run = $this->reviewedRun($period, preparedBy: $preparedBy);
        $approval = app(ApprovalService::class);
        $run = $approval->approve((int) $run->id, 12);

        return $approval->publish((int) $run->id, 13);
    }

    private function reviewedRun(string $period, int $preparedBy = 10, int $reviewerId = 11): PayrollRun
    {
        $base = $this->seedPayrollBase();
        $runs = app(PayrollRunService::class);
        $run = $runs->createRun($preparedBy, [
            'scheme_id' => (int) $base['scheme']->id,
            'period' => $period,
        ]);
        $run = $runs->lockInputs((int) $run->id, $preparedBy);
        app(CalculationService::class)->calculate($run, $preparedBy);

        return app(ApprovalService::class)->review((int) $run->id, $reviewerId);
    }
}
