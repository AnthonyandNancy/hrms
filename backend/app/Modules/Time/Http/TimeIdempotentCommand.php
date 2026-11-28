<?php

declare(strict_types=1);

namespace App\Modules\Time\Http;

use App\Modules\Platform\Contracts\IdempotencyRunner;
use App\Shared\Authorization\PrincipalResolver;
use App\Shared\Exceptions\ValidationException;
use Illuminate\Support\Facades\DB;

/**
 * Time-local closure command runner.
 *
 * The closure endpoints are not CommandRunner routes today; they still need
 * the same mandatory key, replay header and payload-mismatch semantics.
 */
final class TimeIdempotentCommand
{
    public function __construct(
        private readonly PrincipalResolver $principals,
        private readonly IdempotencyRunner $idempotency,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  \Closure(): array{status: int, body: array<string, mixed>}  $operation
     * @return array{status: int, body: array<string, mixed>, replayed: bool}
     */
    public function run(string $scope, array $payload, string $idempotencyKey, \Closure $operation): array
    {
        $key = trim($idempotencyKey);

        if ($key === '' || mb_strlen($key) > 128) {
            throw new ValidationException(
                ['idempotency_key' => ['A valid Idempotency-Key header is required (1-128 characters).']],
                'A valid Idempotency-Key header is required.',
                ['reason' => 'IDEMPOTENCY_KEY_REQUIRED'],
            );
        }

        $principal = $this->principals->resolve();

        return DB::transaction(function () use ($principal, $scope, $key, $payload, $operation): array {
            $result = $this->idempotency->run(
                principalId: $principal->userId(),
                scope: $scope,
                key: $key,
                payload: $payload,
                operation: function () use ($operation): array {
                    $outcome = $operation();
                    $result = [
                        'http_status' => (int) $outcome['status'],
                        'body' => (array) $outcome['body'],
                    ];

                    if (isset($outcome['resource_type'])) {
                        $result['resource_type'] = (string) $outcome['resource_type'];
                    }

                    if (isset($outcome['resource_id'])) {
                        $result['resource_id'] = (string) $outcome['resource_id'];
                    }

                    return $result;
                },
            );

            return [
                'status' => $result->replayed ? 200 : $result->status,
                'body' => $result->body,
                'replayed' => $result->replayed,
            ];
        });
    }
}
