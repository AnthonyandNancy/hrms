<?php

declare(strict_types=1);

namespace App\Modules\Time\Services;

use App\Modules\Time\Exceptions\LeaveBalanceException;
use App\Modules\Time\Models\LeaveAccount;
use App\Modules\Time\Models\LeaveLedgerEntry;
use App\Modules\Time\Models\LeaveType;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Time\BusinessDate;
use App\Shared\Time\Clock;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Append-only leave ledger. Every operation writes a signed integer-minute
 * entry. RESERVE/RELEASE are a hold pair; approval posts RELEASE for the hold
 * plus CONSUME so reserved time is not counted twice. All balance checks run
 * under a row lock on the leave account inside one transaction.
 */
final class LeaveLedgerService
{
    public function __construct(
        private readonly Clock $clock,
        private readonly LeaveBalanceService $balances,
    ) {}

    public function grant(
        int $employmentId,
        int $leaveTypeId,
        int $minutes,
        string $businessDate,
        string $sourceType,
        string $sourceId,
        string $reason,
        ?int $caseId = null,
        ?string $effectiveOn = null,
    ): LeaveLedgerEntry {
        return $this->append(
            $employmentId,
            $leaveTypeId,
            'GRANT',
            abs($minutes),
            $businessDate,
            $sourceType,
            $sourceId,
            $reason,
            $caseId,
            $effectiveOn,
            allowNegative: true,
        );
    }

    public function reserve(
        int $employmentId,
        int $leaveTypeId,
        int $minutes,
        string $businessDate,
        string $sourceType,
        string $sourceId,
        string $reason,
        ?int $caseId = null,
        ?string $effectiveOn = null,
    ): LeaveLedgerEntry {
        return $this->append(
            $employmentId,
            $leaveTypeId,
            'RESERVE',
            -abs($minutes),
            $businessDate,
            $sourceType,
            $sourceId,
            $reason,
            $caseId,
            $effectiveOn,
        );
    }

    public function consume(
        int $employmentId,
        int $leaveTypeId,
        int $minutes,
        string $businessDate,
        string $sourceType,
        string $sourceId,
        string $reason,
        ?int $caseId = null,
        ?string $effectiveOn = null,
    ): LeaveLedgerEntry {
        return DB::transaction(function () use ($employmentId, $leaveTypeId, $minutes, $businessDate, $sourceType, $sourceId, $reason, $caseId, $effectiveOn): LeaveLedgerEntry {
            $this->lockAccount($employmentId, $leaveTypeId);
            $this->releaseReservationIfOutstanding(
                $employmentId,
                $leaveTypeId,
                $businessDate,
                $sourceType,
                $sourceId,
                $reason,
                $caseId,
                $effectiveOn,
            );

            return $this->append(
                $employmentId,
                $leaveTypeId,
                'CONSUME',
                -abs($minutes),
                $businessDate,
                $sourceType,
                $sourceId,
                $reason,
                $caseId,
                $effectiveOn,
            );
        });
    }

    public function release(
        int $employmentId,
        int $leaveTypeId,
        int $minutes,
        string $businessDate,
        string $sourceType,
        string $sourceId,
        string $reason,
        ?int $caseId = null,
        ?string $effectiveOn = null,
    ): LeaveLedgerEntry {
        return $this->append(
            $employmentId,
            $leaveTypeId,
            'RELEASE',
            abs($minutes),
            $businessDate,
            $sourceType,
            $sourceId,
            $reason,
            $caseId,
            $effectiveOn,
            allowNegative: true,
        );
    }

    public function expire(
        int $employmentId,
        int $leaveTypeId,
        int $minutes,
        string $businessDate,
        string $sourceType,
        string $sourceId,
        string $reason,
        ?int $caseId = null,
        ?string $effectiveOn = null,
    ): LeaveLedgerEntry {
        return $this->append(
            $employmentId,
            $leaveTypeId,
            'EXPIRE',
            -abs($minutes),
            $businessDate,
            $sourceType,
            $sourceId,
            $reason,
            $caseId,
            $effectiveOn,
        );
    }

    public function adjust(
        int $employmentId,
        int $leaveTypeId,
        int $minutes,
        string $businessDate,
        string $sourceType,
        string $sourceId,
        string $reason,
        ?int $caseId = null,
        ?string $effectiveOn = null,
    ): LeaveLedgerEntry {
        if ($minutes === 0) {
            throw LeaveBalanceException::invalidQuantity('ADJUST', $minutes);
        }

        return $this->append(
            $employmentId,
            $leaveTypeId,
            'ADJUST',
            $minutes,
            $businessDate,
            $sourceType,
            $sourceId,
            $reason,
            $caseId,
            $effectiveOn,
        );
    }

    public function reverse(
        int $employmentId,
        int $leaveTypeId,
        int $minutes,
        string $businessDate,
        string $sourceType,
        string $sourceId,
        string $reason,
        ?int $reversedEntryId = null,
        ?string $targetAccount = null,
        ?int $caseId = null,
        ?string $effectiveOn = null,
    ): LeaveLedgerEntry {
        if ($reversedEntryId !== null) {
            $target = LeaveLedgerEntry::query()
                ->whereKey($reversedEntryId)
                ->firstOrFail();

            $expected = -((int) $target->quantity_minutes);

            if ($minutes !== $expected) {
                throw LeaveBalanceException::invalidQuantity('REVERSE', $minutes);
            }
        } elseif ($targetAccount !== null) {
            $expectedSign = in_array($targetAccount, LeaveEquation::POSITIVE, true) ? -1 : 1;

            if (($minutes <=> 0) !== $expectedSign) {
                throw LeaveBalanceException::invalidQuantity('REVERSE', $minutes);
            }
        } elseif ($minutes === 0) {
            throw LeaveBalanceException::invalidQuantity('REVERSE', $minutes);
        }

        return $this->append(
            $employmentId,
            $leaveTypeId,
            'REVERSE',
            $minutes,
            $businessDate,
            $sourceType,
            $sourceId,
            $reason,
            $caseId,
            $effectiveOn,
        );
    }

    private function append(
        int $employmentId,
        int $leaveTypeId,
        string $account,
        int $minutes,
        string $businessDate,
        string $sourceType,
        string $sourceId,
        string $reason,
        ?int $caseId,
        ?string $effectiveOn,
        bool $allowNegative = false,
    ): LeaveLedgerEntry {
        BusinessDate::parse($businessDate);
        $effectiveOn ??= $businessDate;
        BusinessDate::parse($effectiveOn);

        if ($minutes === 0) {
            throw LeaveBalanceException::invalidQuantity($account, $minutes);
        }

        return DB::transaction(function () use ($employmentId, $leaveTypeId, $account, $minutes, $businessDate, $effectiveOn, $sourceType, $sourceId, $caseId, $reason, $allowNegative): LeaveLedgerEntry {
            $existing = LeaveLedgerEntry::query()
                ->where('employment_id', $employmentId)
                ->where('leave_type_id', $leaveTypeId)
                ->where('source_type', $sourceType)
                ->where('source_id', $sourceId)
                ->where('account', $account)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $this->lockAccount($employmentId, $leaveTypeId);
            $type = $this->type($leaveTypeId);
            $entry = new LeaveLedgerEntry([
                'employment_id' => $employmentId,
                'leave_type_id' => $leaveTypeId,
                'account' => $account,
                'quantity_minutes' => $minutes,
                'business_date' => $businessDate,
                'effective_on' => $effectiveOn,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'case_id' => $caseId,
                'reason' => $reason,
                'entry_hash' => hash('sha256', implode('|', [
                    $employmentId,
                    $leaveTypeId,
                    $account,
                    $minutes,
                    $businessDate,
                    $effectiveOn,
                    $sourceType,
                    $sourceId,
                ])),
                'recorded_at' => $this->clock->now(),
                'created_at' => $this->clock->now(),
            ]);

            try {
                $entry->save();
            } catch (UniqueConstraintViolationException) {
                return LeaveLedgerEntry::query()
                    ->where('employment_id', $employmentId)
                    ->where('leave_type_id', $leaveTypeId)
                    ->where('source_type', $sourceType)
                    ->where('source_id', $sourceId)
                    ->where('account', $account)
                    ->firstOrFail();
            }

            $summary = $this->balances->forType($employmentId, $leaveTypeId, $businessDate);
            $this->balances->project($employmentId, $leaveTypeId, $summary);

            if (! $allowNegative && ! $type->allow_negative && $summary['available'] < 0) {
                throw LeaveBalanceException::negativeNotAllowed($summary['available']);
            }

            return $entry;
        });
    }

    private function releaseReservationIfOutstanding(
        int $employmentId,
        int $leaveTypeId,
        string $businessDate,
        string $sourceType,
        string $sourceId,
        string $reason,
        ?int $caseId,
        ?string $effectiveOn,
    ): void {
        $reserve = LeaveLedgerEntry::query()
            ->where('employment_id', $employmentId)
            ->where('leave_type_id', $leaveTypeId)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('account', 'RESERVE')
            ->first();

        if ($reserve === null) {
            return;
        }

        $alreadyReleased = LeaveLedgerEntry::query()
            ->where('employment_id', $employmentId)
            ->where('leave_type_id', $leaveTypeId)
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('account', 'RELEASE')
            ->exists();

        if ($alreadyReleased) {
            return;
        }

        $this->append(
            $employmentId,
            $leaveTypeId,
            'RELEASE',
            abs((int) $reserve->quantity_minutes),
            $businessDate,
            $sourceType,
            $sourceId,
            $reason,
            $caseId,
            $effectiveOn,
            allowNegative: true,
        );
    }

    private function lockAccount(int $employmentId, int $leaveTypeId): LeaveAccount
    {
        $account = LeaveAccount::query()
            ->where('employment_id', $employmentId)
            ->where('leave_type_id', $leaveTypeId)
            ->whereNull('closed_on')
            ->lockForUpdate()
            ->first();

        if ($account === null) {
            throw new ConflictException('Open a leave account before posting to the ledger.');
        }

        return $account;
    }

    private function type(int $leaveTypeId): LeaveType
    {
        return LeaveType::query()
            ->whereKey($leaveTypeId)
            ->firstOrFail();
    }
}
