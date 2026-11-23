<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Platform\Models\IdempotencyKey;
use App\Modules\Platform\Services\IdempotencyService;
use App\Shared\Exceptions\ConflictException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IdempotencyServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_call_executes_and_replay_returns_stored_response(): void
    {
        $service = app(IdempotencyService::class);
        $executions = 0;
        $operation = function () use (&$executions): array {
            $executions++;

            return ['http_status' => 201, 'body' => ['resource_id' => 99]];
        };

        $first = $service->run(
            tenantId: 1,
            principalId: 7,
            scope: 'POST:/v1/leave-cases',
            key: 'idem-key-0001',
            payload: ['type' => 'ANNUAL', 'days' => 2],
            operation: $operation,
        );
        $replay = $service->run(
            tenantId: 1,
            principalId: 7,
            scope: 'POST:/v1/leave-cases',
            key: 'idem-key-0001',
            payload: ['days' => 2, 'type' => 'ANNUAL'],
            operation: $operation,
        );

        $this->assertFalse($first->replayed);
        $this->assertTrue($replay->replayed);
        $this->assertSame(1, $executions);
        $this->assertSame(201, $replay->status);
        $this->assertSame(['resource_id' => 99], $replay->body);
        $this->assertSame(IdempotencyKey::STATUS_COMPLETED, IdempotencyKey::query()->firstOrFail()->status);
    }

    public function test_same_key_with_different_payload_is_a_conflict(): void
    {
        $service = app(IdempotencyService::class);
        $service->run(1, 7, 'POST:/v1/leave-cases', 'idem-key-0002', ['days' => 1], fn (): array => [
            'http_status' => 201,
            'body' => [],
        ]);

        $this->expectException(ConflictException::class);

        $service->run(1, 7, 'POST:/v1/leave-cases', 'idem-key-0002', ['days' => 2], fn (): array => [
            'http_status' => 201,
            'body' => [],
        ]);
    }

    public function test_failed_key_is_released_and_can_be_reused(): void
    {
        $service = app(IdempotencyService::class);
        $executions = 0;

        try {
            $service->run(1, 7, 'POST:/v1/leave-cases', 'idem-key-0003', ['days' => 1], function () use (&$executions): array {
                $executions++;
                throw new \RuntimeException('operation failed');
            });
            $this->fail('Expected runtime exception.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('operation failed', $exception->getMessage());
        }

        $this->assertSame(0, IdempotencyKey::query()->count());

        $result = $service->run(1, 7, 'POST:/v1/leave-cases', 'idem-key-0003', ['days' => 1], function () use (&$executions): array {
            $executions++;

            return ['http_status' => 201, 'body' => ['ok' => true]];
        });

        $this->assertFalse($result->replayed);
        $this->assertSame(2, $executions);
        $this->assertSame(IdempotencyKey::STATUS_COMPLETED, IdempotencyKey::query()->firstOrFail()->status);
    }

    public function test_in_progress_key_returns_conflict_instead_of_running_twice(): void
    {
        IdempotencyKey::query()->create([
            'tenant_id' => 1,
            'principal_id' => 7,
            'scope' => 'POST:/v1/leave-cases',
            'key' => 'idem-key-0004',
            'request_hash' => hash('sha256', json_encode(['days' => 1]) ?: ''),
            'status' => IdempotencyKey::STATUS_IN_PROGRESS,
            'expires_at' => now()->addDay(),
            'created_at' => now(),
        ]);

        $executions = 0;

        try {
            app(IdempotencyService::class)->run(1, 7, 'POST:/v1/leave-cases', 'idem-key-0004', ['days' => 1], function () use (&$executions): array {
                $executions++;

                return ['http_status' => 201, 'body' => []];
            });
            $this->fail('Expected conflict for in-progress key.');
        } catch (ConflictException) {
            $this->assertSame(0, $executions);
        }
    }

    public function test_unique_index_prevents_duplicate_reservations(): void
    {
        $attributes = [
            'tenant_id' => 1,
            'principal_id' => 7,
            'scope' => 'POST:/v1/x',
            'key' => 'idem-key-0005',
            'request_hash' => 'hash',
            'status' => IdempotencyKey::STATUS_IN_PROGRESS,
            'expires_at' => now()->addDay(),
            'created_at' => now(),
        ];
        IdempotencyKey::query()->create($attributes);

        $this->expectException(\Illuminate\Database\QueryException::class);
        IdempotencyKey::query()->create($attributes);
    }
}
