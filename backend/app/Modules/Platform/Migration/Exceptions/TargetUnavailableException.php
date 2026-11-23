<?php

declare(strict_types=1);

namespace App\Modules\Platform\Migration\Exceptions;

use RuntimeException;

final class TargetUnavailableException extends RuntimeException
{
    public static function forDomain(string $domain, string $reason): self
    {
        return new self("Migration target for '{$domain}' is unavailable: {$reason}");
    }
}
