<?php

declare(strict_types=1);

namespace App\Modules\Platform\Services;

use App\Modules\Platform\Contracts\IdempotentResult;
use App\Modules\Platform\Models\IdempotencyKey;
use App\Shared\Exceptions\ConflictException;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Throwable;

/**
 * Reserves an idempotency key before executing an operation, replays the
 * stored HTTP result for the same key+payload, and frees failed reservations.
 * Module services must call this within the same transaction as their write.
 */
final class IdempotencyService
{
    private const TTL_SECONDS = 86400;

    /**
     * @param  array<string, mixed>  $payload
     * @param  Closure(): array{http_status: int, body: array<string, mixed>, resource_type?: string, resource_id?: string}  $operation
     */
    public function run(
        int $tenantId,
        int $principalId,
        string $scope,
        string $key,
        array $payload,
        Closure $operation,
    ): IdempotentResult {
        $hash = $this->hash($payload);
        $existing = $this->find($tenantId, $principalId, $scope, $key);

        if ($existing !== null) {
            $replay = $this->resume($existing, $hash);

            if ($replay !== null) {
                return $replay;
            }
        }

        try {
            $record = IdempotencyKey::query()->create([
                'tenant_id' => $tenantId,
                'principal_id' => $principalId,
                'scope' => $scope,
                'key' => $key,
                'request_hash' => $hash,
                'status' => IdempotencyKey::STATUS_IN_PROGRESS,
                'expires_at' => CarbonImmutable::now('UTC')->addSeconds(self::TTL_SECONDS),
                'created_at' => CarbonImmutable::now('UTC'),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Another process raced us. Its reservation is authoritative.
            $record = $this->find($tenantId, $principalId, $scope, $key);

            if ($record === null) {
                throw new ConflictException(
                    'The request is already being processed.',
                    ['reason' => 'IDEMPOTENCY_IN_PROGRESS'],
                );
            }

            $replay = $this->resume($record, $hash);

            if ($replay !== null) {
                return $replay;
            }

            throw new ConflictException(
                'The request is already being processed.',
                ['reason' => 'IDEMPOTENCY_IN_PROGRESS'],
            );
        }

        return $this->execute($record, $operation);
    }

    /**
     * Returns a completed replay, or null when a stale FAILED reservation was
     * removed and the caller may reserve the key again.
     */
    private function resume(IdempotencyKey $record, string $hash): ?IdempotentResult
    {
        if (! hash_equals($record->request_hash, $hash)) {
            throw new ConflictException(
                'The idempotency key was reused with a different request payload.',
                ['reason' => 'IDEMPOTENCY_PAYLOAD_MISMATCH'],
            );
        }

        if ($record->status === IdempotencyKey::STATUS_COMPLETED) {
            return new IdempotentResult(
                (int) $record->response_status,
                (array) ($record->response_body_json ?? []),
                true,
            );
        }

        if ($record->status === IdempotencyKey::STATUS_FAILED) {
            $record->delete();

            return null;
        }

        throw new ConflictException(
            'The request is already being processed.',
            ['reason' => 'IDEMPOTENCY_IN_PROGRESS'],
        );
    }

    /**
     * @param  Closure(): array{http_status: int, body: array<string, mixed>, resource_type?: string, resource_id?: string}  $operation
     */
    private function execute(IdempotencyKey $record, Closure $operation): IdempotentResult
    {
        try {
            $result = $operation();

            $record->forceFill([
                'status' => IdempotencyKey::STATUS_COMPLETED,
                'response_status' => (int) $result['http_status'],
                'response_body_json' => $result['body'],
                'resource_type' => $result['resource_type'] ?? null,
                'resource_id' => $result['resource_id'] ?? null,
            ])->save();

            return new IdempotentResult((int) $result['http_status'], (array) $result['body'], false);
        } catch (Throwable $exception) {
            // Release the reservation so a retry can execute cleanly.
            $record->delete();

            throw $exception;
        }
    }

    private function find(int $tenantId, int $principalId, string $scope, string $key): ?IdempotencyKey
    {
        return IdempotencyKey::query()
            ->where('tenant_id', $tenantId)
            ->where('principal_id', $principalId)
            ->where('scope', $scope)
            ->where('key', $key)
            ->first();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function hash(array $payload): string
    {
        return hash('sha256', json_encode($this->normalize($payload), JSON_THROW_ON_ERROR));
    }

    /**
     * Sort keys recursively so semantically identical JSON objects compare equal.
     *
     * @param  array<mixed>  $payload
     * @return array<mixed>
     */
    private function normalize(array $payload): array
    {
        if (! array_is_list($payload)) {
            ksort($payload);
        }

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->normalize($value);
            }
        }

        return $payload;
    }
}
