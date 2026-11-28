<?php

declare(strict_types=1);

namespace Tests\Feature\Engagement;

use App\Modules\Payroll\Contracts\PayslipPublicationNotifier;
use App\Modules\Payroll\Services\ApprovalService;
use Tests\Support\PayrollTestCase;

/**
 * Publication is committed before Engagement is told about it. The outbound
 * seam must carry only the run id and must not roll back payroll.
 */
final class PayslipPublicationNotificationTest extends PayrollTestCase
{
    public function test_publish_calls_the_engagement_notifier_after_commit_with_only_the_run_id(): void
    {
        $ids = $this->seedPayrollBase();
        $run = $this->reviewedRun('2026-11');

        $notifier = new RecordingPayslipPublicationNotifier;
        $this->app->instance(PayslipPublicationNotifier::class, $notifier);
        $this->app->forgetInstance(ApprovalService::class);

        $approval = $this->app->make(ApprovalService::class);
        $run = $approval->approve((int) $run->id, 12);
        $published = $approval->publish((int) $run->id, 13);

        $this->assertSame([(int) $run->id], $notifier->runIds);
        $this->assertSame('PUBLISHED', (string) $published->status->value);
    }

    public function test_a_direct_run_model_update_to_published_also_uses_the_commit_seam(): void
    {
        $this->seedPayrollBase();
        $run = $this->reviewedRun('2026-11');

        $notifier = new RecordingPayslipPublicationNotifier;
        $this->app->instance(PayslipPublicationNotifier::class, $notifier);

        \Illuminate\Support\Facades\DB::transaction(function () use ($run): void {
            $run->forceFill([
                'status' => \App\Modules\Payroll\Enums\PayrollRunStatus::PUBLISHED,
                'published_at' => $this->clock->now(),
                'published_by' => 13,
            ])->save();
        });

        $this->assertSame([(int) $run->id], $notifier->runIds);
    }

    public function test_a_failing_notifier_never_rolls_back_the_publication(): void
    {
        $this->seedPayrollBase();
        $run = $this->reviewedRun('2026-11');

        $this->app->instance(PayslipPublicationNotifier::class, new FailingPayslipPublicationNotifier);
        $this->app->forgetInstance(ApprovalService::class);
        $approval = $this->app->make(ApprovalService::class);
        $approval->approve((int) $run->id, 12);

        $published = $approval->publish((int) $run->id, 13);

        $this->assertSame('PUBLISHED', (string) $published->status->value);
        $this->assertDatabaseHas('payslips', ['run_id' => (int) $run->id, 'published_at' => $this->clock->now()]);
    }

    private function reviewedRun(string $period, int $preparedBy = 10, int $reviewerId = 11): \App\Modules\Payroll\Models\PayrollRun
    {
        $base = $this->seedPayrollBase();
        $runs = $this->app->make(\App\Modules\Payroll\Services\PayrollRunService::class);
        $run = $runs->createRun($preparedBy, [
            'scheme_id' => (int) $base['scheme']->id,
            'period' => $period,
        ]);
        $run = $runs->lockInputs((int) $run->id, $preparedBy);
        $this->app->make(\App\Modules\Payroll\Services\CalculationService::class)->calculate($run, $preparedBy);

        return $this->app->make(ApprovalService::class)->review((int) $run->id, $reviewerId);
    }
}

final class RecordingPayslipPublicationNotifier implements PayslipPublicationNotifier
{
    /** @var list<int> */
    public array $runIds = [];

    public function notifyRunPublished(int $runId): void
    {
        $this->runIds[] = $runId;
    }
}

final class FailingPayslipPublicationNotifier implements PayslipPublicationNotifier
{
    public function notifyRunPublished(int $runId): void
    {
        throw new \RuntimeException('SYNTHETIC_NOTIFICATION_FAILURE');
    }
}
