<?php

declare(strict_types=1);

namespace App\Modules\Time\Services;

use App\Modules\Time\Models\LeaveLedgerEntry;
use App\Modules\Time\Models\LeavePolicyVersion;
use App\Modules\Time\Models\LeaveRequestProjection;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Observability\AuditRecorder;
use App\Shared\Time\Clock;
use Illuminate\Support\Facades\DB;

/**
 * Applies Workflow decisions to Time-owned leave facts.
 *
 * Workflow remains the owner of the Decision and Effect. This service is the
 * only Time-side writer that turns a recorded leave approval or reversal into
 * append-only ledger rows. Its ledger source identity is the case id plus the
 * account, so concurrent replays collapse onto one CONSUME/REVERSE entry.
 */
final class LeaveApprovalService
{
    public function __construct(
        private readonly LeaveLedgerService $ledger,
        private readonly LeavePolicyService $policies,
        private readonly PayrollFreezeGuard $freezeGuard,
        private readonly AuditRecorder $audit,
        private readonly Clock $clock,
        private readonly PayrollFreezeGuard $freezeGuard,
    ) {}

    /**
     * @param  array<string, mixed>|null  $approvedPayload  Frozen Workflow case payload.
     * @return array{case_id: int, state: string, replayed: bool}
     */
    public function applyApprovedCase(
        int $caseId,
        ?int $actorUserId = null,
        ?array $approvedPayload = null,
    ): array {
        return DB::transaction(function () use ($caseId, $actorUserId, $approvedPayload): array {
            /** @var LeaveRequestProjection|null $projection */
            $projection = LeaveRequestProjection::query()
                ->where('case_id', $caseId)
                ->lockForUpdate()
                ->first();

            if ($projection === null) {
                throw new ConflictException('The leave request was not found.', ['reason' => 'LEAVE_REQUEST_NOT_FOUND']);
            }

            $state = strtoupper((string) $projection->state);

            if (in_array($state, ['APPROVED', 'CONSUMED', 'REVOKED'], true)) {
                return ['case_id' => $caseId, 'state' => $state, 'replayed' => true];
            }

            if ($state !== 'SUBMITTED') {
                throw new ConflictException('The leave request is not awaiting approval.', [
                    'reason' => 'LEAVE_REQUEST_NOT_APPROVABLE',
                    'state' => $state,
                ]);
            }

            $payload = $approvedPayload ?? [];
            $minutes = (int) ($payload['minutes'] ?? $projection->minutes);
            $employmentId = (int) ($payload['employment_id'] ?? $projection->employment_id);
            $leaveTypeId = (int) ($payload['leave_type_id'] ?? $projection->leave_type_id);
            $fromDate = is_string($payload['from_date'] ?? null)
                ? (string) $payload['from_date']
                : $projection->from_date->format('Y-m-d');

            if ($minutes <= 0
                || $employmentId !== (int) $projection->employment_id
                || $leaveTypeId !== (int) $projection->leave_type_id
                || $fromDate !== $projection->from_date->format('Y-m-d')) {
                throw new ConflictException('The approved leave payload no longer matches the application.', [
                    'reason' => 'LEAVE_APPROVED_PAYLOAD_MISMATCH',
                    'case_id' => $caseId,
                ]);
            }

            $this->policyForProjection($projection, $fromDate, $minutes);

            // Ledger money/facts belong to the leave month. Lock the same
            // durable Time month mutex used by close and all other mutations.
            $this->freezeGuard->lockAndAssertOpen(substr($businessDate, 0, 7));

            $this->ledger->consume(
                employmentId: $employmentId,
                leaveTypeId: $leaveTypeId,
                minutes: $minutes,
                businessDate: $fromDate,
                sourceType: 'LEAVE_REQUEST',
                sourceId: (string) $caseId,
                reason: 'Leave approved',
                caseId: $caseId,
            );

            $projection->forceFill([
                'state' => 'APPROVED',
                'updated_at' => $this->clock->now(),
            ])->save();

            $this->audit->record(
                $actorUserId,
                'time.leave.request.approved',
                'leave_request',
                $caseId,
                after: [
                    'state' => 'APPROVED',
                    'subject_employment_id' => $employmentId,
                    'minutes' => $minutes,
                    'policy_version_id' => $projection->leave_policy_version_id !== null
                        ? (int) $projection->leave_policy_version_id
                        : null,
                ],
            );

            return ['case_id' => $caseId, 'state' => 'APPROVED', 'replayed' => false];
        });
    }

    /**
     * Reverse the effective consumption and release any still-outstanding hold.
     * The ledger's (case, account) idempotency key makes replay safe even if a
     * caller retries outside Workflow.
     *
     * @return array{case_id: int, state: string, reversed_minutes: int, replayed: bool}
     */
    public function reverseApprovedCase(
        int $caseId,
        ?int $actorUserId = null,
        string $reason = 'Leave approval reversed',
    ): array {
        return DB::transaction(function () use ($caseId, $actorUserId, $reason): array {
            /** @var LeaveRequestProjection|null $projection */
            $projection = LeaveRequestProjection::query()
                ->where('case_id', $caseId)
                ->lockForUpdate()
                ->first();

            if ($projection === null) {
                throw new ConflictException('The leave request was not found.', ['reason' => 'LEAVE_REQUEST_NOT_FOUND']);
            }

            $state = strtoupper((string) $projection->state);

            if ($state === 'REVOKED') {
                return ['case_id' => $caseId, 'state' => $state, 'reversed_minutes' => 0, 'replayed' => true];
            }

            if ($state === 'CANCELLED') {
                throw new ConflictException('A cancelled leave request has no effective consumption to reverse.', [
                    'reason' => 'LEAVE_REQUEST_NOT_EFFECTIVE',
                    'state' => $state,
                ]);
            }

            $this->freezeGuard->assertNotFrozen(
                (int) $projection->employment_id,
                PayrollFreezeGuard::periodsForRange(
                    $projection->from_date->format('Y-m-d'),
                    $projection->to_date->format('Y-m-d'),
                ),
            );

            $consume = LeaveLedgerEntry::query()
                ->where('case_id', $caseId)
                ->where('account', 'CONSUME')
                ->first();

            $minutes = $consume !== null ? abs((int) $consume->quantity_minutes) : 0;

            if ($minutes > 0) {
                $this->ledger->reverse(
                    employmentId: (int) $projection->employment_id,
                    leaveTypeId: (int) $projection->leave_type_id,
                    minutes: $minutes,
                    businessDate: $this->clock->now()->toDateString(),
                    sourceType: 'LEAVE_REVOKE',
                    sourceId: (string) $caseId,
                    reason: $reason,
                    targetAccount: 'CONSUME',
                    caseId: $caseId,
                );
            } else {
                $this->ledger->release(
                    employmentId: (int) $projection->employment_id,
                    leaveTypeId: (int) $projection->leave_type_id,
                    minutes: (int) $projection->minutes,
                    businessDate: $this->clock->now()->toDateString(),
                    sourceType: 'LEAVE_REQUEST',
                    sourceId: (string) $caseId,
                    reason: $reason,
                    caseId: $caseId,
                );
            }

            $projection->forceFill([
                'state' => 'REVOKED',
                'updated_at' => $this->clock->now(),
            ])->save();

            $this->audit->record(
                $actorUserId,
                'time.leave.request.revoked',
                'leave_request',
                $caseId,
                before: ['state' => $state],
                after: [
                    'state' => 'REVOKED',
                    'reversed_minutes' => $minutes,
                ],
            );

            return [
                'case_id' => $caseId,
                'state' => 'REVOKED',
                'reversed_minutes' => $minutes,
                'replayed' => false,
            ];
        });
    }

    private function policyForProjection(LeaveRequestProjection $projection, string $fromDate, int $minutes): LeavePolicyVersion
    {
        $policy = $projection->leave_policy_version_id !== null
            ? LeavePolicyVersion::query()->whereKey((int) $projection->leave_policy_version_id)->first()
            : $this->policies->effectiveFor((int) $projection->leave_type_id, $fromDate);

        if ($policy === null) {
            throw new ConflictException('The approved leave has no effective policy version.', [
                'reason' => 'LEAVE_POLICY_VERSION_MISSING',
                'case_id' => (int) $projection->case_id,
            ]);
        }

        if ((int) $policy->leave_type_id !== (int) $projection->leave_type_id) {
            throw new ConflictException('The leave policy does not belong to the requested leave type.', [
                'reason' => 'LEAVE_POLICY_TYPE_MISMATCH',
                'case_id' => (int) $projection->case_id,
            ]);
        }

        $treatment = is_string($policy->payroll_treatment) ? strtoupper(trim($policy->payroll_treatment)) : '';
        $legacy = (array) $policy->accrual_json;
        $treatment = $treatment !== '' ? $treatment : strtoupper((string) ($legacy['payroll_treatment'] ?? 'UNRESOLVED'));

        if (! in_array($treatment, ['PAID', 'PARTIAL', 'UNPAID', 'UNRESOLVED'], true)) {
            throw new ConflictException('The leave policy payroll treatment is invalid.', [
                'reason' => 'LEAVE_PAYROLL_TREATMENT_UNRESOLVED',
                'case_id' => (int) $projection->case_id,
                'policy_version_id' => (int) $policy->id,
            ]);
        }

        return $policy;
    }
}
