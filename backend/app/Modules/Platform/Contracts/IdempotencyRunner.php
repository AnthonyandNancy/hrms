<?php

declare(strict_types=1);

namespace App\Modules\Platform\Contracts;

use Closure;

/**
 * Command idempotency port. Business modules depend on this contract instead
 * of Platform's concrete service so the ledger stays a Platform concern.
 */
interface IdempotencyRunner
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  Closure(): array{http_status: int, body: array<string, mixed>, resource_type?: string, resource_id?: string}  $operation
     */
    public function run(
        int $principalId,
        string $scope,
        string $key,
        array $payload,
        Closure $operation,
    ): IdempotentResult;
}
