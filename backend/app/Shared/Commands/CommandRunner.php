<?php

declare(strict_types=1);

namespace App\Shared\Commands;

use App\Modules\Platform\Contracts\IdempotencyRunner;
use App\Shared\Authorization\PrincipalResolver;
use App\Shared\Exceptions\ValidationException;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Single entry point for write commands.
 *
 * Responsibilities, in order:
 *  1. Resolve the caller principal (never trusts a client-supplied tenant).
 *  2. Require a bounded Idempotency-Key for idempotent commands.
 *  3. Evaluate invariants inside the transaction, before any write.
 *  4. Delegate the write to the operation closure through the idempotency
 *     ledger so retries replay the original body instead of re-executing.
 *
 * Capability and anchor enforcement stay in route middleware: the runner is a
 * correctness seam, not a second authorization system.
 */
final class CommandRunner
{
    public function __construct(
        private readonly PrincipalResolver $principals,
        private readonly IdempotencyRunner $idempotency,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  Closure(): array{http_status: int, body: array<string, mixed>, resource_type?: string, resource_id?: string}  $operation
     */
    public function run(
        CommandDefinition $definition,
        array $payload,
        Closure $operation,
        string $idempotencyKey,
    ): CommandResult {
        $key = trim($idempotencyKey);

        if (! $definition->idempotent) {
            throw new ValidationException(
                ['idempotency_key' => ['This command does not accept an Idempotency-Key.']],
                'This command does not accept an Idempotency-Key.',
                ['reason' => 'IDEMPOTENCY_KEY_NOT_SUPPORTED'],
            );
        }

        if ($key === '' || mb_strlen($key) > 128) {
            throw new ValidationException(
                ['idempotency_key' => ['A valid Idempotency-Key header is required (1-128 characters).']],
                'A valid Idempotency-Key header is required.',
                ['reason' => 'IDEMPOTENCY_KEY_REQUIRED'],
            );
        }

        $principal = $this->principals->resolve();

        return DB::transaction(function () use ($definition, $payload, $operation, $key, $principal): CommandResult {
            foreach ($definition->invariants as $invariantClass) {
                $invariant = app($invariantClass);

                if (! $invariant instanceof Invariant) {
                    throw new \LogicException("{$invariantClass} must implement ".Invariant::class.'.');
                }

                $invariant->assert($principal->tenantId(), $payload);
            }

            $result = $this->idempotency->run(
                tenantId: $principal->tenantId(),
                principalId: $principal->userId(),
                scope: 'CMD:'.$definition->name,
                key: $key,
                payload: $payload,
                operation: $operation,
            );

            return new CommandResult(
                status: $result->replayed ? 200 : $result->status,
                body: $result->body,
                replayed: $result->replayed,
            );
        });
    }
}
