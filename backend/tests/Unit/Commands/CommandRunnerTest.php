<?php

declare(strict_types=1);

namespace Tests\Unit\Commands;

use App\Modules\IAM\Auth\AuthenticationContext;
use App\Modules\IAM\Contracts\Principal;
use App\Shared\Authorization\AnchorType;
use App\Shared\Commands\CommandDefinition;
use App\Shared\Commands\CommandRunner;
use App\Shared\Commands\Invariant;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\ValidationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestPrincipal;
use Tests\TestCase;

final class CommandRunnerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  list<class-string<Invariant>>  $invariants
     */
    private function definition(array $invariants = []): CommandDefinition
    {
        return new CommandDefinition(
            name: 'test.command',
            capability: 'people.employee.manage',
            anchor: AnchorType::LEGAL_ENTITY,
            idempotent: true,
            invariants: $invariants,
            effect: null,
            auditAction: 'test.command.executed',
        );
    }

    private function principal(): void
    {
        $principal = new TestPrincipal(tenantId: 1, userId: 10);
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

    public function test_a_non_idempotent_command_rejects_an_idempotency_key(): void
    {
        $this->principal();
        $definition = new CommandDefinition(
            name: 'test.query',
            capability: 'people.employee.read',
            anchor: AnchorType::LEGAL_ENTITY,
            idempotent: false,
            invariants: [],
            effect: null,
            auditAction: 'test.query.executed',
        );

        $this->expectException(ValidationException::class);

        $this->app->make(CommandRunner::class)->run(
            $definition,
            [],
            static fn (): array => ['http_status' => 200, 'body' => []],
            'unexpected-key',
        );
    }

    public function test_the_same_key_with_a_different_payload_conflicts(): void
    {
        $this->principal();
        $runner = $this->app->make(CommandRunner::class);
        $operation = static fn (): array => ['http_status' => 201, 'body' => ['ok' => true]];

        $runner->run($this->definition(), ['x' => 1], $operation, 'mismatch-key');

        $this->expectException(ConflictException::class);

        $runner->run($this->definition(), ['x' => 2], $operation, 'mismatch-key');
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
        }
    }
}

final class RejectingInvariant implements Invariant
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function assert(int $tenantId, array $payload): void
    {
        throw new ConflictException('Invariant rejected.', ['reason' => 'TEST_INVARIANT']);
    }
}
