<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Services;

use App\Modules\Payroll\Enums\PayrollReceiptType;
use App\Modules\Payroll\Enums\PayrollRunStatus;
use App\Modules\Payroll\Models\PayrollReceipt;
use App\Modules\Payroll\Models\PayrollRun;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Observability\AuditRecorder;
use App\Shared\Time\Clock;
use Illuminate\Support\Facades\DB;

/**
 * Reconciles an external bank/tax acknowledgement file against the only
 * exported bytes for that run. The adapter never clears funds or files tax.
 */
final class ReceiptReconciliationService
{
    public function __construct(
        private readonly Clock $clock,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @return array{reconciled: bool, reason: string|null, receipt_id: int|null}
     */
    public function record(int $runId, string $type, int $actorUserId, string $fileHash, ?string $externalReference = null): array
    {
        $run = PayrollRun::query()->whereKey($runId)->first();

        if ($run === null) {
            throw new \App\Shared\Exceptions\NotFoundOrForbiddenException;
        }

        $status = $run->status instanceof PayrollRunStatus
            ? $run->status
            : PayrollRunStatus::from((string) $run->status);

        if (! in_array($status, [PayrollRunStatus::PAID, PayrollRunStatus::CLOSED], true)) {
            throw new ConflictException('Receipts can only be attached to a paid or closed run.', [
                'reason' => 'RUN_NOT_RECEIPTABLE',
                'status' => $status->value,
            ]);
        }

        return DB::transaction(function () use ($run, $runId, $type, $actorUserId, $fileHash, $externalReference): array {
            $expected = $type === PayrollReceiptType::BANK->value ? $run->bank_export_hash : $run->tax_export_hash;

            if ($expected === null) {
                throw new ConflictException('No matching export hash exists for this receipt.', [
                    'reason' => 'EXPORT_HASH_MISSING',
                    'type' => $type,
                ]);
            }

            $matches = hash_equals((string) $expected, $fileHash);
            $receipt = PayrollReceipt::query()->create([
                'run_id' => $runId,
                'type' => $type,
                'file_hash' => $fileHash,
                'external_reference' => $externalReference,
                'received_at' => $this->clock->now(),
                'reconciled_at' => $matches ? $this->clock->now() : null,
                'metadata_json' => ['expected_hash' => $expected, 'matched' => $matches],
                'created_at' => $this->clock->now(),
            ]);

            $this->audit->record(
                actorUserId: $actorUserId,
                action: 'payroll.receipt.recorded',
                aggregateType: 'payroll_run',
                aggregateId: $runId,
                after: [
                    'receipt_id' => (int) $receipt->id,
                    'type' => $type,
                    'matched' => $matches,
                ],
            );

            return [
                'reconciled' => $matches,
                'reason' => $matches ? null : 'EXPORT_HASH_MISMATCH',
                'receipt_id' => (int) $receipt->id,
            ];
        });
    }
}
