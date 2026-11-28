<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services;

use App\Modules\Payroll\Contracts\PayslipPublicationNotifier;
use App\Modules\Payroll\Enums\PayrollRunStatus;
use App\Modules\Payroll\Models\PayrollRun;
use Illuminate\Support\Facades\DB;

/**
 * Outbound publication event bridge.
 *
 * Payroll remains the writer of its run aggregate. This observer watches the
 * committed status transition to PUBLISHED and asks the notifier after commit,
 * so a transport failure can never roll back an irreversible publication.
 */
final class PayrollPublicationEventBridge
{
    public function __construct(private readonly PayslipPublicationNotifier $notifier) {}

    public function handle(PayrollRun $run): void
    {
        $status = $run->status instanceof PayrollRunStatus
            ? $run->status
            : PayrollRunStatus::from((string) $run->status);

        if ($status !== PayrollRunStatus::PUBLISHED || ! $run->wasChanged('status')) {
            return;
        }

        $runId = (int) $run->id;

        DB::afterCommit(function () use ($runId): void {
            try {
                $this->notifier->notifyRunPublished($runId);
            } catch (\Throwable $exception) {
                report($exception);
            }
        });
    }
}
