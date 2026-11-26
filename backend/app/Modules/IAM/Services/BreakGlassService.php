<?php

declare(strict_types=1);

namespace App\Modules\IAM\Services;

use App\Modules\IAM\Models\BreakGlassGrant;
use App\Modules\IAM\Models\User;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Observability\AuditRecorder;
use App\Shared\Observability\OutboxRecorder;
use App\Shared\Time\Clock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Time-boxed, two-person emergency elevation. There is deliberately no
 * permanent super-admin switch: every grant has an explicit 30-minute expiry,
 * a reason, a distinct approver, audit evidence and an outbox alert.
 */
final class BreakGlassService
{
    public function __construct(
        private readonly Clock $clock,
        private readonly OutboxRecorder $outbox,
        private readonly AuditRecorder $audit,
        private readonly SecurityEventService $events,
    ) {}

    /**
     * @param  list<string>|null  $scope
     * @return array{grant: BreakGlassGrant, capabilities: list<string>}
     */
    public function activate(Request $request, User $requester, int $approverUserId, string $reason, ?array $scope = null): array
    {
        if ($requester->id === $approverUserId) {
            throw new ValidationException(['approver_user_id' => ['The approver must be a different person.']], 'The given data was invalid.', ['reason' => 'BREAK_GLASS_SEPARATION_REQUIRED']);
        }

        if (mb_strlen(trim($reason)) < (int) config('iam.break_glass.reason_min_length', 10)) {
            throw new ValidationException(['reason' => ['A reason of at least 10 characters is required.']], 'The given data was invalid.', ['reason' => 'BREAK_GLASS_REASON_REQUIRED']);
        }

        $approver = User::query()
            ->whereKey($approverUserId)
            ->where('status', User::STATUS_ACTIVE)
            ->first();

        if ($approver === null) {
            throw new NotFoundOrForbiddenException;
        }

        // MFA enrollment is mandatory for anyone who can trigger break-glass.
        if (! $requester->hasConfirmedMfa()) {
            throw new ConflictException(
                'MFA enrollment is required before activating break-glass.',
                ['reason' => 'MFA_REQUIRED'],
            );
        }

        $scope = $scope === null || $scope === [] ? ['*'] : $this->normalizeScope($scope);

        $now = $this->clock->now();
        $expiresAt = $now->addMinutes((int) config('iam.break_glass.duration_minutes', 30));

        $grant = DB::transaction(function () use ($request, $requester, $approver, $reason, $scope, $now, $expiresAt): BreakGlassGrant {
            /** @var BreakGlassGrant $grant */
            $grant = BreakGlassGrant::query()->create([
                'requester_user_id' => (int) $requester->id,
                'approver_user_id' => (int) $approver->id,
                'reason' => trim($reason),
                'granted_at' => $now,
                'expires_at' => $expiresAt,
                'revoked_at' => null,
                'scope_json' => $scope,
                'alert_sent_at' => null,
            ]);

            $this->audit($grant, 'security.break_glass.activated', (int) $requester->id, $request);

            $this->outbox->record(
                eventType: 'security.break_glass.activated',
                aggregateType: 'break_glass_grant',
                aggregateId: (string) $grant->id,
                aggregateVersion: 1,
                payload: [
                    'grant_id' => (int) $grant->id,
                    'requester_user_id' => (int) $grant->requester_user_id,
                    'approver_user_id' => (int) $grant->approver_user_id,
                    'expires_at' => $expiresAt->toIso8601ZuluString(),
                    'scope' => $scope,
                ],
            );

            $this->events->record(
                (int) $requester->id,
                'break_glass.activated',
                'SUCCESS',
                ['grant_id' => (int) $grant->id],
                $request,
            );

            return $grant;
        });

        return ['grant' => $grant, 'capabilities' => $scope];
    }

    public function revoke(Request $request, User $actor, int $grantId): BreakGlassGrant
    {
        /** @var BreakGlassGrant|null $grant */
        $grant = BreakGlassGrant::query()
            ->whereKey($grantId)
            ->where(function ($query) use ($actor): void {
                $query->where('requester_user_id', $actor->id)->orWhere('approver_user_id', $actor->id);
            })
            ->first();

        if ($grant === null) {
            throw new NotFoundOrForbiddenException;
        }

        if ($grant->revoked_at !== null) {
            return $grant;
        }

        DB::transaction(function () use ($grant, $actor, $request): void {
            $grant->forceFill(['revoked_at' => $this->clock->now()])->save();

            $this->audit($grant, 'security.break_glass.revoked', (int) $actor->id, $request);
            $this->outbox->record(
                eventType: 'security.break_glass.revoked',
                aggregateType: 'break_glass_grant',
                aggregateId: (string) $grant->id,
                aggregateVersion: 2,
                payload: [
                    'grant_id' => (int) $grant->id,
                    'revoked_by_user_id' => (int) $actor->id,
                ],
            );

            $this->events->record(
                (int) $actor->id,
                'break_glass.revoked',
                'SUCCESS',
                ['grant_id' => (int) $grant->id],
                $request,
            );
        });

        return $grant;
    }

    /**
     * @param  list<string>  $scope
     * @return list<string>
     */
    private function normalizeScope(array $scope): array
    {
        $catalog = app(\App\Modules\IAM\Authorization\CapabilityCatalog::class);
        $normalized = [];

        foreach ($scope as $code) {
            if (! is_string($code) || $code === '' || ($code !== '*' && ! $catalog->has($code))) {
                throw new ValidationException(['scope' => ['The break-glass scope contains an unknown capability.']], 'The given data was invalid.', ['reason' => 'BREAK_GLASS_SCOPE_INVALID']);
            }

            $normalized[] = $code;
        }

        $normalized = array_values(array_unique($normalized));

        if (count($normalized) > 10) {
            throw new ValidationException(['scope' => ['Break-glass scope is limited to 10 capabilities.']], 'The given data was invalid.', ['reason' => 'BREAK_GLASS_SCOPE_TOO_WIDE']);
        }

        return $normalized;
    }

    private function audit(BreakGlassGrant $grant, string $action, int $actorId, Request $request): void
    {
        $this->audit->record(
            actorUserId: $actorId,
            action: $action,
            aggregateType: 'break_glass_grant',
            aggregateId: (string) $grant->id,
            metadata: [
                'requester_user_id' => (int) $grant->requester_user_id,
                'approver_user_id' => (int) $grant->approver_user_id,
                'expires_at' => $grant->expires_at->toIso8601ZuluString(),
                'scope' => $grant->scope_json,
            ],
        );
    }
}
