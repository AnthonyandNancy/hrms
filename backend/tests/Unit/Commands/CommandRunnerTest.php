<?php

declare(strict_types=1);

namespace Tests\Unit\Commands;

use App\Modules\IAM\Auth\AuthenticationContext;
use App\Modules\IAM\Contracts\Principal;
use App\Shared\Authorization\AnchorType;
use App\Shared\Commands\CommandDefinition;
use App\Shared\Commands\CommandRunner;
use App\Shared\Commands\Invariant;
use App\Shared\ErrorCode;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\ValidationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestPrincipal;
use Tests\TestCase;

final class CommandRunnerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        MutableStateInvariant::$active = true;
        MutableStateInvariant::$calls = 0;
    }

    /**
     * @param  list<class-string<Invariant>>  $invariants
     */
    private function definition(array $invariants = []): CommandDefinition
    {
        return new CommandDefinition(
            name: 'test.command',
            capability: 'people.employee.manage',
            anchor: AnchorType::COMPANY,
            idempotent: true,
            invariants: $invariants,
            effect: null,
            auditAction: 'test.command.executed',
        );
    }

    private function principal(): void
    {
        $principal = new TestPrincipal(userId: 10);
        $this->app->instance(Principal::class, $principal);
        $this->app->make(AuthenticationContext::class)->set($principal);
    }

    public function test_replaying_the_same_idempotency_key_returns_the_original_body(): void
    {
        $this->principal();
        $runner = $this->app->make(CommandRunner::class);
        $calls = 0;
        $operation = function () use (&$calls): array {
            $calls++;

            return [
                'http_status' => 201,
                'body' => ['ok' => true],
                'resource_type' => 'test',
                'resource_id' => '1',
            ];
        };

        $first = $runner->run($this->definition(), ['x' => 1], $operation, 'same-key');
        $second = $runner->run($this->definition(), ['x' => 1], $operation, 'same-key');

        $this->assertSame(201, $first->status);
        $this->assertFalse($first->replayed);
        $this->assertSame(200, $second->status);
        $this->assertTrue($second->replayed);
        $this->assertSame(['ok' => true], $second->body);
        $this->assertSame(1, $calls);
    }

    public function test_a_missing_idempotency_key_is_rejected(): void
    {
        $this->principal();

        $this->expectException(ValidationException::class);

        $this->app->make(CommandRunner::class)->run(
            $this->definition(),
            ['x' => 1],
            static fn (): array => ['http_status' => 201, 'body' => []],
            '',
        );
    }

    public function test_a_whitespace_only_key_is_rejected(): void
    {
        $this->principal();

        try {
            $this->app->make(CommandRunner::class)->run(
                $this->definition(),
                ['x' => 1],
                static fn (): array => ['http_status' => 201, 'body' => []],
                '   ',
            );
            $this->fail('Whitespace-only key was accepted.');
        } catch (ValidationException $exception) {
            $this->assertSame(ErrorCode::VALIDATION_FAILED, $exception->errorCode());
            $this->assertSame(422, $exception->errorCode()->status());
            $this->assertSame('IDEMPOTENCY_KEY_REQUIRED', $exception->details()['reason'] ?? null);
            $this->assertDatabaseCount('idempotency_keys', 0);
        }
    }

    public function test_a_key_longer_than_128_characters_is_rejected(): void
    {
        $this->principal();

        $this->expectException(ValidationException::class);

        $this->app->make(CommandRunner::class)->run(
            $this->definition(),
            ['x' => 1],
            static fn (): array => ['http_status' => 201, 'body' => []],
            str_repeat('k', 129),
        );
    }

    public function test_the_same_key_with_a_different_payload_conflicts(): void
    {
        $this->principal();
        $runner = $this->app->make(CommandRunner::class);
        $calls = 0;
        $operation = function () use (&$calls): array {
            $calls++;

            return ['http_status' => 201, 'body' => ['ok' => true]];
        };

        $runner->run($this->definition(), ['x' => 1], $operation, 'mismatch-key');

        try {
            $runner->run($this->definition(), ['x' => 2], $operation, 'mismatch-key');
            $this->fail('Payload mismatch was accepted.');
        } catch (ConflictException $exception) {
            $this->assertSame(ErrorCode::CONFLICT, $exception->errorCode());
            $this->assertSame(409, $exception->errorCode()->status());
            $this->assertSame('IDEMPOTENCY_PAYLOAD_MISMATCH', $exception->details()['reason'] ?? null);
            $this->assertSame(1, $calls, 'The mismatched retry must not execute the operation.');
        }
    }

    public function test_invariants_run_before_the_operation_and_block_it(): void
    {
        $this->principal();
        $calls = 0;

        try {
            $this->app->make(CommandRunner::class)->run(
                $this->definition([RejectingInvariant::class]),
                ['x' => 1],
                function () use (&$calls): array {
                    $calls++;

                    return ['http_status' => 201, 'body' => []];
                },
                'invariant-key',
            );
            $this->fail('Invariant violation was not raised.');
        } catch (ConflictException $exception) {
            $this->assertSame('TEST_INVARIANT', $exception->details()['reason'] ?? null);
            $this->assertSame(0, $calls);
            $this->assertDatabaseCount('idempotency_keys', 0);
        }
    }

    public function test_a_replay_does_not_re_evaluate_a_state_sensitive_invariant(): void
    {
        $this->principal();
        $runner = $this->app->make(CommandRunner::class);
        $definition = $this->definition([MutableStateInvariant::class]);
        $calls = 0;
        $operation = function () use (&$calls): array {
            $calls++;
            // First execution flips the state the invariant guards.
            MutableStateInvariant::$active = false;

            return ['http_status' => 201, 'body' => ['ok' => true]];
        };

        $first = $runner->run($definition, ['x' => 1], $operation, 'stateful-key');
        $replay = $runner->run($definition, ['x' => 1], $operation, 'stateful-key');

        $this->assertSame(201, $first->status);
        $this->assertFalse($first->replayed);
        $this->assertSame(200, $replay->status);
        $this->assertTrue($replay->replayed);
        $this->assertSame(['ok' => true], $replay->body);
        $this->assertSame(1, $calls, 'The replay must not re-run the operation.');
        $this->assertSame(1, MutableStateInvariant::$calls, 'The replay must not re-run the invariant.');
    }
}

final class MutableStateInvariant implements Invariant
{
    public static bool $active = true;

    public static int $calls = 0;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function assert(array $payload): void
    {
        self::$calls++;

        if (! self::$active) {
            throw new ConflictException('State already changed.', ['reason' => 'STATE_CHANGED']);
        }
    }
}

final class RejectingInvariant implements Invariant
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function assert(array $payload): void
    {
        throw new ConflictException('Invariant rejected.', ['reason' => 'TEST_INVARIANT']);
    }
}
