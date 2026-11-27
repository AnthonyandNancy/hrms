<?php

declare(strict_types=1);

namespace App\Modules\Time\Services;

use App\Modules\Time\Exceptions\LeaveBalanceException;
use App\Modules\Time\Models\LeaveLedgerEntry;

/**
 * Account direction:
 * GRANT +, RESERVE -, CONSUME -, RELEASE +, EXPIRE -, ADJUST +/-, REVERSE +/-.
 *
 * The reported balance is the sum of all signed ledger quantities. Available
 * is the amount that may still be reserved; it equals balance because RESERVE
 * is itself already a negative ledger quantity, and RELEASE/REVERSE add back.
 */
final class LeaveEquation
{
    /** @var list<string> */
    public const POSITIVE = ['GRANT', 'RELEASE'];

    /** @var list<string> */
    public const NEGATIVE = ['RESERVE', 'CONSUME', 'EXPIRE'];

    /** @var list<string> */
    public const CONTROLLED_SIGN = ['ADJUST', 'REVERSE'];

    /** @var list<string> */
    public const ALL = ['GRANT', 'RESERVE', 'CONSUME', 'RELEASE', 'EXPIRE', 'ADJUST', 'REVERSE'];

    /**
     * @param  iterable<LeaveLedgerEntry>  $entries
     * @return array{
     *   granted: int,
     *   reserved: int,
     *   pending: int,
     *   consumed: int,
     *   released: int,
     *   expired: int,
     *   adjusted: int,
     *   reversed: int,
     *   balance: int,
     *   available: int,
     *   unexplained_difference: int
     * }
     */
    public static function summarize(iterable $entries): array
    {
        $summary = [
            'granted' => 0,
            'reserved' => 0,
            'pending' => 0,
            'consumed' => 0,
            'released' => 0,
            'expired' => 0,
            'adjusted' => 0,
            'reversed' => 0,
            'balance' => 0,
            'available' => 0,
            'unexplained_difference' => 0,
        ];

        foreach ($entries as $entry) {
            $quantity = (int) $entry->quantity_minutes;

            match ($entry->account) {
                'GRANT' => $summary['granted'] += abs($quantity),
                'RESERVE' => $summary['reserved'] += abs($quantity),
                'CONSUME' => $summary['consumed'] += abs($quantity),
                'RELEASE' => $summary['released'] += abs($quantity),
                'EXPIRE' => $summary['expired'] += abs($quantity),
                'ADJUST' => $summary['adjusted'] += $quantity,
                'REVERSE' => $summary['reversed'] += $quantity,
                default => throw LeaveBalanceException::invalidState(
                    'Unknown leave ledger account.',
                    ['account' => (string) $entry->account],
                ),
            };

            $summary['balance'] += $quantity;
        }

        // Opening is any pre-period GRANT and is included in granted; the
        // equation is checked by independently reconstructing closing from
        // each account's signed contribution.
        $reconstructed = $summary['granted']
            - $summary['reserved']
            - $summary['consumed']
            + $summary['released']
            - $summary['expired']
            + $summary['adjusted']
            + $summary['reversed'];

        $summary['pending'] = max(0, $summary['reserved'] - $summary['released']);
        $summary['available'] = $summary['balance'];
        $summary['unexplained_difference'] = $summary['balance'] - $reconstructed;

        return $summary;
    }

    public static function assertDirection(string $account, int $quantity): void
    {
        if (! in_array($account, self::ALL, true)) {
            throw LeaveBalanceException::invalidState('Unknown leave ledger account.', ['account' => $account]);
        }

        if (in_array($account, self::POSITIVE, true) && $quantity <= 0) {
            throw LeaveBalanceException::invalidQuantity($account, $quantity);
        }

        if (in_array($account, self::NEGATIVE, true) && $quantity >= 0) {
            throw LeaveBalanceException::invalidQuantity($account, $quantity);
        }

        if ($quantity === 0) {
            throw LeaveBalanceException::invalidQuantity($account, $quantity);
        }
    }

    /**
     * @param  array{balance: int, available: int}  $summary
     */
    public static function assertNonNegative(array $summary, bool $allowNegative): void
    {
        if (! $allowNegative && $summary['available'] < 0) {
            throw LeaveBalanceException::negativeNotAllowed($summary['available']);
        }
    }
}
