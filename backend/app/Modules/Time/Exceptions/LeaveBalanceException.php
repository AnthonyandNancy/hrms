<?php

declare(strict_types=1);

namespace App\Modules\Time\Exceptions;

use App\Shared\Exceptions\ConflictException;

class LeaveBalanceException extends ConflictException
{
    public static function insufficient(int $requested, int $available): self
    {
        return new self(
            'The leave request exceeds the available balance.',
            [
                'reason' => 'INSUFFICIENT_LEAVE_BALANCE',
                'requested_minutes' => $requested,
                'available_minutes' => $available,
            ],
        );
    }

    public static function negativeNotAllowed(int $balance): self
    {
        return new self(
            'The operation would make the leave balance negative.',
            ['reason' => 'NEGATIVE_LEAVE_BALANCE', 'available_minutes' => $balance],
        );
    }

    public static function invalidQuantity(string $account, int $quantity): self
    {
        return new self(
            'The ledger quantity does not follow the account direction.',
            ['reason' => 'INVALID_LEDGER_QUANTITY', 'account' => $account, 'quantity_minutes' => $quantity],
        );
    }

    public static function invalidState(string $message, array $details = []): self
    {
        return new self($message, ['reason' => 'INVALID_LEAVE_STATE', ...$details]);
    }
}
