<?php

declare(strict_types=1);

namespace Tests\Unit\Workflow;

use App\Modules\Workflow\Domain\IdempotencyHash;
use PHPUnit\Framework\TestCase;

class IdempotencyHashTest extends TestCase
{
    private IdempotencyHash $hash;

    protected function setUp(): void
    {
        $this->hash = new IdempotencyHash;
    }

    public function test_key_order_does_not_change_the_hash(): void
    {
        $a = ['action' => 'APPROVE', 'comment' => 'ok', 'meta' => ['y' => 2, 'x' => 1]];
        $b = ['meta' => ['x' => 1, 'y' => 2], 'comment' => 'ok', 'action' => 'APPROVE'];

        $this->assertSame($this->hash->forPayload($a), $this->hash->forPayload($b));
    }

    public function test_list_order_and_scalar_types_are_part_of_the_hash(): void
    {
        $this->assertNotSame(
            $this->hash->forPayload(['approvers' => [10, 11]]),
            $this->hash->forPayload(['approvers' => [11, 10]]),
        );

        $this->assertNotSame(
            $this->hash->forPayload(['days' => 2]),
            $this->hash->forPayload(['days' => '2']),
        );
    }

    public function test_hash_is_stable_sha256_hex(): void
    {
        $hash = $this->hash->forPayload(['days' => 2]);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);
        $this->assertSame($hash, $this->hash->forPayload(['days' => 2]));
    }
}
