<?php

declare(strict_types=1);

namespace App\Modules\Platform\Exceptions;

use App\Shared\Exceptions\ConflictException;

/**
 * Raised before an outbox row is written when a payload contains a key that
 * must never leave its owning module (PII, credentials, banking data).
 */
final class SensitiveOutboxPayloadException extends ConflictException
{
    /**
     * @param  list<string>  $keys
     */
    public function __construct(array $keys)
    {
        parent::__construct(
            'The outbox payload contains prohibited sensitive fields.',
            ['reason' => 'OUTBOX_PAYLOAD_SENSITIVE', 'keys' => $keys],
        );
    }
}
