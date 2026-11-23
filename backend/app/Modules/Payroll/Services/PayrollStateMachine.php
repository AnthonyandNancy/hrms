<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services;

use App\Modules\Payroll\Enums\PayrollRunStatus;
use App\Modules\Payroll\Models\PayrollRun;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundOrForbiddenException;

/**
 * The one writer of payroll_runs.status. Every lifecycle transition must go
 * through this service so actor columns and timestamps stay consistent.
 */
final class PayrollStateMachine
{
    /**
     * @var array<string, list<PayrollRunStatus>>
     */
    public const TRANSITIONS = [
        'DRAFT' => [PayrollRunStatus::INPUT_LOCKED, PayrollRunStatus::CANCELLED],
        'INPUT_LOCKED' => [PayrollRunStatus::CALCULATED, PayrollRunStatus::DRAFT, PayrollRunStatus::CANCELLED],
        'CALCULATED' => [PayrollRunStatus::REVIEWED, PayrollRunStatus::INPUT_LOCKED, PayrollRunStatus::CANCELLED],
        'REVIEWED' => [PayrollRunStatus::APPROVED, PayrollRunStatus::CALCULATED, PayrollRunStatus::CANCELLED],
        'APPROVED' => [PayrollRunStatus::PUBLISHED, PayrollRunStatus::REVIEWED, PayrollRunStatus::CANCELLED],
        'PUBLISHED' => [PayrollRunStatus::PAID],
        'PAID' => [PayrollRunStatus::CLOSED],
        'CLOSED' => [],
        'CANCELLED' => [],
    ];

    /**
     * @return list<PayrollRunStatus>
     */
    public function allowedFrom(PayrollRunStatus $from): array
    {
        return self::TRANSITIONS[$from->value] ?? [];
    }

    public function canTransition(PayrollRunStatus $from, PayrollRunStatus $to): bool
    {
        return in_array($to, $this->allowedFrom($from), true);
    }

    public function assertTransition(PayrollRunStatus $from, PayrollRunStatus $to, array $details = []): void
    {
        if ($this->canTransition($from, $to)) {
            return;
        }

        throw new ConflictException(
            "Payroll run cannot transition from {$from->value} to {$to->value}.",
            [
                'reason' => 'INVALID_RUN_TRANSITION',
                'from' => $from->value,
                'to' => $to->value,
                ...$details,
            ],
        );
    }

    /**
     * Apply one legal transition to a locked run. The caller owns the
     * surrounding DB transaction.
     *
     * @param  array<string, scalar|null>  $columns
     */
    public function transition(PayrollRun $run, PayrollRunStatus $to, array $columns = [], array $details = []): PayrollRun
    {
        $from = $run->status;

        if (! $from instanceof PayrollRunStatus) {
            $from = PayrollRunStatus::from((string) $from);
        }

        $this->assertTransition($from, $to, $details);

        $run->forceFill([
            'status' => $to,
            ...$columns,
        ])->save();

        return $run->refresh();
    }

    public static function lockRun(int $tenantId, int $runId): PayrollRun
    {
        $run = PayrollRun::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($runId)
            ->lockForUpdate()
            ->first();

        if ($run === null) {
            throw new NotFoundOrForbiddenException;
        }

        return $run;
    }
}
