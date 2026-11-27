<?php

declare(strict_types=1);

namespace App\Modules\IAM\Services;

use App\Modules\IAM\Models\AuthEvent;
use App\Shared\RequestContext;
use App\Shared\Time\Clock;
use Illuminate\Http\Request;

/**
 * Writes append-only authentication/authorization evidence. Metadata is
 * explicitly allow-listed; tokens, passwords and MFA secrets never reach it.
 */
final class SecurityEventService
{
    public function __construct(private readonly Clock $clock) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        ?int $userId,
        string $eventType,
        string $result,
        array $metadata = [],
        ?Request $request = null,
    ): AuthEvent {
        $request ??= request();

        $safe = array_intersect_key($metadata, array_flip([
            'reason',
            'session_id',
            'factor_type',
            'grant_id',
            'capability',
            'anchor_type',
            'anchor_id',
            'role_code',
            'binding_id',
            'target_user_id',
            'method',
            'outcome',
            'risks',
        ]));

        return AuthEvent::query()->create([
            'user_id' => $userId,
            'event_type' => $eventType,
            'result' => $result,
            'ip' => $request instanceof Request ? $request->ip() : null,
            'user_agent' => $request instanceof Request ? mb_substr((string) $request->userAgent(), 0, 1000) : null,
            'request_id' => RequestContext::requestId(),
            'metadata_json' => $safe === [] ? null : $safe,
            'occurred_at' => $this->clock->now(),
        ]);
    }
}
