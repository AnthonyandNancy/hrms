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
 * Ordering is deliberate:
 *  1. Resolve the caller principal (never trusts client-supplied scope).
 *  2. Require a bounded Idempotency-Key.
 *  3. Reserve the key, then evaluate invariants and run the write inside the
 *     ledger's operation closure.
 *
 * Invariants MUST run inside the operation closure, not before it: a retry of
 * a completed command has to replay the stored response, and a state-sensitive
 * invariant (for example "contract is ACTIVE") would otherwise reject the
 * replay with 409 after the first call already changed that state.
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

        if ($key === '' || mb_strlen($key) > 128) {
            throw new ValidationException(
                ['idempotency_key' => ['A valid Idempotency-Key header is required (1-128 characters).']],
                'A valid Idempotency-Key header is required.',
                ['reason' => 'IDEMPOTENCY_KEY_REQUIRED'],
            );
        }

        $principal = $this->principals->resolve();

        return DB::transaction(function () use ($definition, $payload, $operation, $key, $principal): CommandResult {
            $result = $this->idempotency->run(
                principalId: $principal->userId(),
                scope: 'CMD:'.$definition->name,
                key: $key,
                payload: $payload,
                operation: function () use ($definition, $payload, $operation, $principal): array {
                    foreach ($definition->invariants as $invariantClass) {
                        $invariant = app($invariantClass);

                        if (! $invariant instanceof Invariant) {
                            throw new \LogicException("{$invariantClass} must implement ".Invariant::class.'.');
                        }

                        $invariant->assert($payload);
                    }

                    return $operation();
                },
            );

            return new CommandResult(
                status: $result->replayed ? 200 : $result->status,
                body: $result->body,
                replayed: $result->replayed,
            );
        });
    }
}
