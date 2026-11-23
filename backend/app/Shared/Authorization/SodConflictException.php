<?php

declare(strict_types=1);

namespace App\Shared\Authorization;

use App\Shared\Exceptions\ConflictException;

final class SodConflictException extends ConflictException
{
    /**
     * @param  list<array{0: string, 1: string}>  $pairs
     */
    public function __construct(array $pairs)
    {
        parent::__construct(
            'The requested grants violate separation of duties.',
            ['reason' => 'SOD_CONFLICT', 'conflicts' => $pairs],
        );
    }
}
