<?php

declare(strict_types=1);

namespace Tests\Feature\People;

use App\Modules\People\Models\Person;
use Tests\Support\PeopleTestCase;

final class IdempotencyHttpTest extends PeopleTestCase
{
    public function test_idempotency_key_replays_the_original_command_response_without_duplicate_write(): void
    {
        $payload = ['legal_name' => 'Idempotent Person'];
        $headers = ['Idempotency-Key' => 'people-idem-0001'];

        $first = $this->postJson('/v1/persons', $payload, $headers)->assertCreated();
        $second = $this->postJson('/v1/persons', $payload, $headers)->assertCreated();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame('true', $second->headers->get('Idempotent-Replayed'));
        $this->assertSame(1, Person::query()->count());
    }

    public function test_same_key_with_different_payload_is_409(): void
    {
        $headers = ['Idempotency-Key' => 'people-idem-0002'];
        $this->postJson('/v1/persons', ['legal_name' => 'One'], $headers)->assertCreated();

        $this->postJson('/v1/persons', ['legal_name' => 'Two'], $headers)
            ->assertConflict()
            ->assertJsonPath('data.reason', 'IDEMPOTENCY_PAYLOAD_MISMATCH');
    }
}
