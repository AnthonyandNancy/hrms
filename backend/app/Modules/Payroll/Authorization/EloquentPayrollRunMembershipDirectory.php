<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Authorization;

use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Models\PayrollRunMember;
use App\Shared\Authorization\Contracts\PayrollRunMembershipDirectory;

/**
 * Production PAYROLL_RUN anchor adapter. Run membership is the set of users
 * recorded on the run as preparer, reviewer, approver or publisher, which keeps
 * run-scoped bindings narrow and auditable.
 */
final class EloquentPayrollRunMembershipDirectory implements PayrollRunMembershipDirectory
{
    public function isMember(int $userId, int $payrollRunId, string $capabilityCode): bool
    {
        $run = PayrollRun::query()
            ->whereKey($payrollRunId)
            ->first();

        if ($run === null) {
            return false;
        }

        $memberIds = PayrollRunMember::query()
            ->where('run_id', $payrollRunId)
            ->where('user_id', $userId)
            ->exists();

        if ($memberIds) {
            return true;
        }

        // Compatibility for runs created before the membership table existed.
        $members = array_filter([
            (int) $run->prepared_by,
            $run->reviewed_by !== null ? (int) $run->reviewed_by : null,
            $run->approved_by !== null ? (int) $run->approved_by : null,
            $run->published_by !== null ? (int) $run->published_by : null,
        ], static fn (?int $id): bool => $id !== null);

        return in_array($userId, $members, true);
    }

    /** @return list<int> */
    public function runIdsForUser(int $userId, string $businessDate): array
    {
        $memberRunIds = PayrollRunMember::query()
            ->where('user_id', $userId)
            ->pluck('run_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        return PayrollRun::query()
            ->where(function ($query) use ($userId, $memberRunIds): void {
                $query->whereIn('id', $memberRunIds)
                    ->orWhere('prepared_by', $userId)
                    ->orWhere('reviewed_by', $userId)
                    ->orWhere('approved_by', $userId)
                    ->orWhere('published_by', $userId);
            })
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }
}
