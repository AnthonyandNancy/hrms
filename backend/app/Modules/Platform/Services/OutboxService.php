<?php

declare(strict_types=1);

namespace App\Modules\Platform\Services;

use App\Modules\Platform\Exceptions\SensitiveOutboxPayloadException;
use App\Modules\Platform\Models\OutboxEvent;
use Carbon\CarbonImmutable;

/**
 * Records integration events in the caller's active transaction. This service
 * never opens or commits a transaction itself; that is what makes local state
 * changes and their outbox effects atomic.
 */
final class OutboxService
{
    /** @var list<string> */
    public const PROHIBITED_PAYLOAD_KEYS = [
        'id_number',
        'id_number_ciphertext',
        'password',
        'password_hash',
        'token',
        'session_token',
        'secret',
        'bank_account',
        'bank_account_number',
        'mobile',
        'phone',
        'email',
        'address',
        'health',
        'biometric',
    ];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(
        string $eventType,
        string $aggregateType,
        string $aggregateId,
        int $aggregateVersion,
        array $payload,
        ?CarbonImmutable $availableAt = null,
    ): OutboxEvent {
        $prohibited = $this->findProhibitedKeys($payload);

        if ($prohibited !== []) {
            throw new SensitiveOutboxPayloadException($prohibited);
        }

        $now = CarbonImmutable::now('UTC');

        return OutboxEvent::query()->create([
            'event_type' => $eventType,
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'aggregate_version' => $aggregateVersion,
            'payload_json' => $payload,
            'occurred_at' => $now,
            'available_at' => $availableAt ?? $now,
            'attempts' => 0,
            'created_at' => $now,
        ]);
    }

    /**
     * Return every prohibited key found at any depth. The result is sorted so
     * errors are deterministic and do not reveal object traversal order.
     *
     * @param  array<mixed>  $payload
     * @return list<string>
     */
    private function findProhibitedKeys(array $payload): array
    {
        $found = [];

        foreach ($payload as $key => $value) {
            if (is_string($key) && in_array(mb_strtolower($key), self::PROHIBITED_PAYLOAD_KEYS, true)) {
                $found[] = $key;
            }

            if (is_array($value)) {
                $found = [...$found, ...$this->findProhibitedKeys($value)];
            }
        }

        $found = array_values(array_unique($found));
        sort($found);

        return $found;
    }
}
