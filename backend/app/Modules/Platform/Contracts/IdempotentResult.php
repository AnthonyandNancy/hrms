<?php

declare(strict_types=1);

namespace App\Modules\Platform\Contracts;

/**
 * Result returned by IdempotencyService::run(). The HTTP layer serializes
 * status/body exactly as stored so a replay is byte-stable.
 */
final readonly class IdempotentResult
{
    /**
     * @param  array<string, mixed>  $body
     */
    public function __construct(
        public int $status,
        public array $body,
        public bool $replayed,
    ) {}
}
