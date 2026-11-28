<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Modules\Payroll\Enums\PayrollRunStatus;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\PayrollRunEmployee;
use App\Modules\Payroll\Models\PayrollScheme;
use App\Modules\Payroll\Models\PayrollYtd;
use App\Modules\Payroll\Models\PayrollYtdLedgerEntry;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Services\ApprovalService;
use App\Modules\Payroll\Services\CalculationService;
use App\Modules\Payroll\Services\CorrectionService;
use App\Modules\Payroll\Services\PayrollRunService;
use App\Modules\Payroll\Services\ReconciliationService;
use App\Shared\Exceptions\ConflictException;
use Tests\Support\PayrollTestCase;

final class CorrectionRunTest extends PayrollTestCase
{
    public function test_correction_is_delta_only_and_original_plus_correction_reconciles(): void
    {
        $base = $this->seedPayrollBase(1_000_000);
        $original = $this->closedJanuaryRun($base);
        $originalPayslip = Payslip::query()->where('run_id', $original->id)->firstOrFail();
        $originalEmployee = PayrollRunEmployee::query()->where('run_id', $original->id)->firstOrFail();
        $originalSnapshot = $originalPayslip->toArray();

        // A previously missed deduction entered after close.
        \App\Modules\Payroll\Models\PayrollAdjustment::query()->create([
            'run_id' => null,
            'employment_id' => $base['employment_id'],
            'item_code' => 'RETRO_DEDUCTION',
            'amount_cents' => -100_000,
            'reason' => 'Synthetic retroactive pharmacy deduction',
            'case_id' => null,
            'approved_by' => 12,
            'effective_period' => '2026-02',
            'created_at' => $this->clock->now(),
        ]);

        $correction = app(CorrectionService::class)->create(
            (int) $original->id,
            11,
            '2026-02',
            'Synthetic correction for a missed deduction.',
        );

        $this->assertSame(PayrollRunStatus::DRAFT, $correction->status);
        $this->assertSame($original->id, $correction->correction_of_run_id);

        $runs = app(PayrollRunService::class);
        $correction = $runs->lockInputs((int) $correction->id, 11);
        app(CalculationService::class)->calculate($correction, 11);
        $correction = $correction->refresh();

        $this->assertSame(PayrollRunStatus::CALCULATED, $correction->status);
        $this->assertSame(0, (int) $correction->gross_cents);
        $this->assertLessThan(0, (int) $correction->net_cents);

        $correctionEmployee = PayrollRunEmployee::query()->where('run_id', $correction->id)->firstOrFail();
        $this->assertSame(0, (int) $correctionEmployee->gross_cents);
        $this->assertSame(100_000, (int) $correctionEmployee->deductions_cents);

        $this->publishCorrection($correction);
        $correction = $correction->refresh();
        $this->assertSame(PayrollRunStatus::PUBLISHED, $correction->status);

        // Original immutable.
        $this->assertSame($originalSnapshot['net_cents'], (int) $original->refresh()->net_cents);
        $this->assertSame($originalSnapshot['gross_cents'], (int) $original->refresh()->gross_cents);

        $report = app(ReconciliationService::class)->reconcile((int) $correction->id);
        $this->assertTrue($report['passed'], json_encode($report['differences']));
        $this->assertSame(
            (int) $original->refresh()->net_cents + (int) $correction->net_cents,
            $report['chain']['payable_net_cents'],
        );
        $this->assertSame($report['chain']['payable_net_cents'], $report['chain']['payslip_net_cents']);
    }

    public function test_multiple_corrections_chain_correctly_and_ytd_is_adjusted(): void
    {
        $base = $this->seedPayrollBase(1_000_000);
        $original = $this->closedJanuaryRun($base);
        $ytdAfterOriginal = PayrollYtd::query()->firstOrFail();

        $first = $this->postAdjustmentAndPublish($original, -50_000, '2026-02', 11);
        $second = $this->postAdjustmentAndPublish($original, 20_000, '2026-03', 12);
        $first = $first->refresh();
        $second = $second->refresh();
        $this->assertSame(1, (int) $first->correction_sequence);
        $this->assertSame((int) $original->id, (int) $first->correction_baseline_run_id);
        $this->assertSame([], (array) ($first->prior_correction_ids_json ?? []));
        $this->assertSame(2, (int) $second->correction_sequence);
        $this->assertSame((int) $first->id, (int) $second->correction_baseline_run_id);
        $this->assertSame([(int) $first->id], (array) ($second->prior_correction_ids_json ?? []));

        $firstEmployee = PayrollRunEmployee::query()->where('run_id', $first->id)->firstOrFail();
        $secondEmployee = PayrollRunEmployee::query()->where('run_id', $second->id)->firstOrFail();
        // A retro deduction is net-only; a retro earning is gross and still
        // attracts employee SI, housing fund and cumulative tax.
        $this->assertSame(-50_000, (int) $firstEmployee->net_cents);
        $this->assertSame(20_000, (int) $secondEmployee->gross_cents);
        $this->assertSame(16_490, (int) $secondEmployee->net_cents);
        $this->assertSame(3_510, (int) $secondEmployee->deductions_cents);

        $report = app(ReconciliationService::class)->reconcile((int) $second->id);
        $this->assertTrue($report['passed'], json_encode($report['differences']));
        $this->assertSame((int) $original->net_cents, $report['chain']['original_net_cents']);
        $this->assertSame((int) $first->net_cents + (int) $second->net_cents, $report['chain']['correction_net_cents']);
        $this->assertSame(
            (int) $original->net_cents + (int) $first->net_cents + (int) $second->net_cents,
            $report['chain']['payslip_net_cents'],
        );

        // Two published adjustments move the cumulative ledger.
        $ytd = PayrollYtd::query()->firstOrFail();
        // A net-only retro deduction does not reduce gross; the retro
        // earning adds its full gross.
        $this->assertSame(1_000_000 + 20_000, (int) $ytd->gross_cents);
        $this->assertSame((int) $ytdAfterOriginal->net_cents - 50_000 + 16_490, (int) $ytd->net_cents);
    }

    public function test_correction_target_baseline_and_delta_are_cumulative_for_ac12_pattern(): void
    {
        $base = $this->seedPayrollBase(961_796);
        $original = $this->closedJanuaryRun($base);
        $originalNet = (int) PayrollRunEmployee::query()->where('run_id', $original->id)->firstOrFail()->net_cents;
        $this->assertSame(808_000, $originalNet);

        // Synthetic gross adjustments chosen so 8% SI/7% HF/3% tax rounding
        // produces AC-12's exact 8300.00 and 8350.00 target net.
        $first = $this->postAdjustmentAndPublish($original, 26_681, '2026-02', 11)->refresh();
        $this->assertSame($originalNet, (int) $first->correction_baseline_net_cents);
        $this->assertSame(830_000, (int) $first->correction_target_net_cents);
        $this->assertSame(22_000, (int) $first->correction_delta_net_cents);
        $this->assertSame(830_000, (int) $first->correction_cumulative_net_cents);

        $second = $this->postAdjustmentAndPublish($original, 6_065, '2026-03', 12)->refresh();
        $this->assertSame(830_000, (int) $second->correction_baseline_net_cents);
        $this->assertSame(835_000, (int) $second->correction_target_net_cents);
        $this->assertSame(5_000, (int) $second->correction_delta_net_cents);
        $this->assertSame(835_000, (int) $second->correction_cumulative_net_cents);
        $this->assertSame(5_000, (int) PayrollRunEmployee::query()->where('run_id', $second->id)->firstOrFail()->net_cents);

        $chain = app(ReconciliationService::class)->chain($second);
        $this->assertTrue($chain['passed'], json_encode($chain));
        $this->assertSame(835_000, (int) $chain['payable_net_cents']);
    }

    public function test_ytd_ledger_is_signed_idempotent_and_carries_negative_balance(): void
    {
        $base = $this->seedPayrollBase(1_000_000);
        $original = $this->closedJanuaryRun($base);
        $originalNet = (int) PayrollRunEmployee::query()->where('run_id', $original->id)->firstOrFail()->net_cents;
        $originalYtd = PayrollYtd::query()->firstOrFail();
        PayrollScheme::query()->whereKey((int) $base['scheme']->id)->firstOrFail()
            ->forceFill(['allow_negative_net' => true])->save();

        $correction = $this->postAdjustmentAndPublish($original, -2_000_000, '2026-02', 11);
        $ytd = PayrollYtd::query()->firstOrFail();
        $this->assertSame($originalNet - 2_000_000, (int) $ytd->net_cents);
        $this->assertSame((int) $ytd->net_cents, (int) $ytd->carry_forward_cents);
        $this->assertLessThan(0, (int) $ytd->carry_forward_cents);

        $this->assertDatabaseCount('payroll_ytd_ledger_entries', 2);
        $entry = PayrollYtdLedgerEntry::query()->where('source_run_id', $correction->id)->firstOrFail();
        $this->assertSame('CORRECTION', (string) $entry->source_type);
        $this->assertSame(-2_000_000, (int) $entry->net_cents);
        $this->assertSame((int) $ytd->net_cents, (int) $entry->carry_forward_cents);
        $this->assertSame(64, strlen((string) $entry->source_hash));

        // Re-publishing the same correction is idempotent and must not fold
        // the signed ledger twice.
        app(ApprovalService::class)->publish((int) $correction->id, 23);
        $this->assertDatabaseCount('payroll_ytd_ledger_entries', 2);
        $this->assertSame((int) $ytd->net_cents, (int) PayrollYtd::query()->firstOrFail()->net_cents);
        $this->assertSame((int) $originalYtd->gross_cents, (int) PayrollYtd::query()->firstOrFail()->gross_cents);
    }

    public function test_cumulative_negative_and_positive_corrections_keep_one_signed_projection(): void
    {
        $base = $this->seedPayrollBase(1_000_000);
        $original = $this->closedJanuaryRun($base);
        $originalNet = (int) PayrollRunEmployee::query()->where('run_id', $original->id)->firstOrFail()->net_cents;

        $first = $this->postAdjustmentAndPublish($original, -50_000, '2026-02', 11);
        $second = $this->postAdjustmentAndPublish($original, 20_000, '2026-03', 12);
        $first = $first->refresh();
        $second = $second->refresh();
        $this->assertSame(1, (int) $first->correction_sequence);
        $this->assertSame((int) $original->id, (int) $first->correction_baseline_run_id);
        $this->assertSame([], (array) ($first->prior_correction_ids_json ?? []));
        $this->assertSame(2, (int) $second->correction_sequence);
        $this->assertSame((int) $first->id, (int) $second->correction_baseline_run_id);
        $this->assertSame([(int) $first->id], (array) ($second->prior_correction_ids_json ?? []));
        $entries = PayrollYtdLedgerEntry::query()->orderBy('id')->get();
        $this->assertCount(3, $entries);
        $this->assertSame(['PAYROLL', 'CORRECTION', 'CORRECTION'], $entries->pluck('source_type')->all());

        $ytd = PayrollYtd::query()->firstOrFail();
        $expected = $originalNet + (int) $first->net_cents + (int) $second->net_cents;
        $this->assertSame($expected, (int) $ytd->net_cents);
        $this->assertSame($expected, (int) $entries->sum('net_cents'));
        $this->assertSame(min(0, $expected), (int) $ytd->carry_forward_cents);

        $chain = app(ReconciliationService::class)->chain($second->refresh());
        $this->assertSame($expected, (int) $chain['payable_net_cents']);
        $this->assertCount(3, (array) $chain['ytd_ledger']);
        $this->assertSame($expected, (int) array_sum(array_column((array) $chain['ytd_ledger'], 'net_cents')));
    }

    public function test_correction_requires_a_published_original(): void
    {
        $base = $this->seedPayrollBase();
        $runs = app(PayrollRunService::class);
        $draft = $runs->createRun(10, ['scheme_id' => (int) $base['scheme']->id, 'period' => '2026-01']);

        $this->expectException(ConflictException::class);
        app(CorrectionService::class)->create((int) $draft->id, 11, '2026-02', 'Draft is not a valid correction base.');
    }

    /**
     * @param  array{scheme: PayrollScheme, employment_id: int}  $base
     */
    private function closedJanuaryRun(array $base): PayrollRun
    {
        $runs = app(PayrollRunService::class);
        $run = $runs->createRun(10, ['scheme_id' => (int) $base['scheme']->id, 'period' => '2026-01']);
        $run = $runs->lockInputs((int) $run->id, 10);
        app(CalculationService::class)->calculate($run, 10);

        $approval = app(ApprovalService::class);
        $run = $approval->review((int) $run->id, 11);
        $run = $approval->approve((int) $run->id, 12);
        $run = $approval->publish((int) $run->id, 13);
        $run = $approval->markPaid((int) $run->id, 13, 'DEMO-BANK');

        return $approval->close((int) $run->id, 13);
    }

    private function postAdjustmentAndPublish(PayrollRun $original, int $amountCents, string $period, int $actor): PayrollRun
    {
        $employmentId = (int) PayrollRunEmployee::query()
            ->where('run_id', $original->id)
            ->firstOrFail()
            ->employment_id;

        \App\Modules\Payroll\Models\PayrollAdjustment::query()->create([
            'run_id' => null,
            'employment_id' => $employmentId,
            'item_code' => $amountCents < 0 ? 'RETRO_DEDUCTION' : 'RETRO_EARNING',
            'amount_cents' => $amountCents,
            'reason' => 'Synthetic correction adjustment',
            'case_id' => null,
            'approved_by' => $actor,
            'effective_period' => $period,
            'created_at' => $this->clock->now(),
        ]);

        $correction = app(CorrectionService::class)->create(
            (int) $original->id,
            $actor,
            $period,
            'Synthetic correction reason for chain test.',
        );

        $runs = app(PayrollRunService::class);
        $correction = $runs->lockInputs((int) $correction->id, $actor);
        app(CalculationService::class)->calculate($correction, $actor);
        $this->publishCorrection($correction->refresh());

        return $correction->refresh();
    }

    private function publishCorrection(PayrollRun $correction): void
    {
        $approval = app(ApprovalService::class);
        $approval->review((int) $correction->id, 21);
        $approval->approve((int) $correction->id, 22);
        $approval->publish((int) $correction->id, 23);
    }
}
