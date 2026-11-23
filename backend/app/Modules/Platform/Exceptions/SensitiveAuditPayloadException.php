<?php

declare(strict_types=1);

namespace App\Modules\Platform\Exceptions;

use App\Shared\Exceptions\ConflictException;

final class SensitiveAuditPayloadException extends ConflictException
{
    /**
     * @param  list<string>  $keys
     */
    public function __construct(array $keys)
    {
        parent::__construct(
            'The audit payload contains prohibited sensitive fields.',
            ['reason' => 'AUDIT_PAYLOAD_SENSITIVE', 'keys' => $keys],
        );
    }
}
