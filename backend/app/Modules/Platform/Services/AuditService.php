<?php

declare(strict_types=1);

namespace App\Modules\Platform\Services;

use App\Modules\Platform\Exceptions\SensitiveAuditPayloadException;
use App\Modules\Platform\Models\AuditEvent;
use App\Shared\RequestContext;
use App\Shared\Time\Clock;

/**
 * Append-only audit recorder. Services call this inside the same transaction as
 * the business write; payloads carry IDs/codes only, never PII or secrets.
 */
final class AuditService
{
    private const PROHIBITED_KEYS = [
        'id_number',
        'id_number_ciphertext',
        'id_number_hash',
        'mobile',
        'phone',
        'email',
        'password',
        'password_hash',
        'token',
        'session_token',
        'secret',
        'bank_account',
        'bank_account_number',
        'address',
        'health',
        'biometric',
    ];

    public function __construct(private readonly Clock $clock) {}

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        ?int $actorUserId,
        string $action,
        string $aggregateType,
        int|string $aggregateId,
        array $before = [],
        array $after = [],
        array $metadata = [],
    ): AuditEvent {
        $this->assertSafe([$before, $after, $metadata]);

        return AuditEvent::query()->create([
            'actor_user_id' => $actorUserId,
            'actor_type' => $actorUserId === null ? 'SYSTEM' : 'USER',
            'action' => $action,
            'aggregate_type' => $aggregateType,
            'aggregate_id' => (string) $aggregateId,
            'before_json' => $before === [] ? null : $before,
            'after_json' => $after === [] ? null : $after,
            'metadata_json' => $metadata === [] ? null : $metadata,
            'request_id' => RequestContext::requestId() !== '' ? RequestContext::requestId() : null,
            'occurred_at' => $this->clock->now(),
        ]);
    }

    /**
     * @param  list<array<mixed>>  $payloads
     */
    private function assertSafe(array $payloads): void
    {
        $found = [];

        foreach ($payloads as $payload) {
            $this->collectProhibited($payload, $found);
        }

        if ($found !== []) {
            $found = array_values(array_unique($found));
            sort($found);

            throw new SensitiveAuditPayloadException($found);
        }
    }

    /**
     * @param  array<mixed>  $payload
     * @param  list<string>  $found
     */
    private function collectProhibited(array $payload, array &$found): void
    {
        foreach ($payload as $key => $value) {
            if (is_string($key) && in_array(mb_strtolower($key), self::PROHIBITED_KEYS, true)) {
                $found[] = $key;
            }

            if (is_array($value)) {
                $this->collectProhibited($value, $found);
            }
        }
    }
}
