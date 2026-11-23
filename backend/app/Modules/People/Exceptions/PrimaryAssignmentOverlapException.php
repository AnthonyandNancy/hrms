<?php

declare(strict_types=1);

namespace App\Modules\People\Exceptions;

use App\Shared\Exceptions\ConflictException;

final class PrimaryAssignmentOverlapException extends ConflictException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(array $details = [])
    {
        parent::__construct(
            'An employment may only have one primary assignment covering a business date.',
            ['reason' => 'PRIMARY_ASSIGNMENT_OVERLAP'] + $details,
        );
    }
}
