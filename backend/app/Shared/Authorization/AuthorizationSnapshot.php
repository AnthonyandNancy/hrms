<?php

declare(strict_types=1);

namespace App\Shared\Authorization;

use App\Modules\IAM\Models\AuthorityBinding;
use App\Modules\IAM\Models\User;
use App\Modules\IAM\Models\UserRole;
use App\Shared\Observability\AuditRecorder;
use App\Shared\Time\Clock;
use Illuminate\Support\Facades\DB;

/**
 * Replays the user's complete effective authorization snapshot into a new
 * auth_version.
 *
 * auth_version is a snapshot generation, not a session-revocation counter.
 * Copying only the row that triggered a change silently drops every other
 * grant: the historical "password change revokes all capabilities" defect.
 * This service locks the subject and copies all currently effective bindings
 * and roles forward in the same transaction, preserving history.
 */
final class AuthorizationSnapshot
{
    public function __construct(
        private readonly Clock $clock,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @return int the new authorization snapshot generation
     */
    public function replay(User $user, string $reason): int
    {
        return DB::transaction(function () use ($user, $reason): int {
            /** @var User $locked */
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $fromVersion = (int) $locked->auth_version;
            $toVersion = $fromVersion + 1;

            $this->copyEffectiveBindings($locked, $fromVersion, $toVersion);
            $this->copyEffectiveRoles($locked, $fromVersion, $toVersion);

            $locked->forceFill(['auth_version' => $toVersion])->save();

            $this->audit->record(
                actorUserId: (int) $locked->id,
                action: 'authorization.snapshot.replayed',
                aggregateType: 'user',
                aggregateId: (string) $locked->id,
                metadata: [
                    'from_version' => $fromVersion,
                    'to_version' => $toVersion,
                    'reason' => $reason,
                ],
            );

            return $toVersion;
        });
    }

    private function copyEffectiveBindings(User $user, int $fromVersion, int $toVersion): void
    {
        $now = $this->clock->now();

        AuthorityBinding::query()
            ->where('principal_id', (int) $user->id)
            ->where('auth_version', $fromVersion)
            ->where('valid_from', '<=', $now)
            ->where('valid_to', '>', $now)
            ->orderBy('id')
            ->each(function (AuthorityBinding $binding) use ($toVersion): void {
                $copy = $binding->replicate();
                $copy->auth_version = $toVersion;
                $copy->last_used_at = null;
                $copy->save();
            });
    }

    private function copyEffectiveRoles(User $user, int $fromVersion, int $toVersion): void
    {
        $now = $this->clock->now();

        UserRole::query()
            ->where('user_id', (int) $user->id)
            ->where('auth_version', $fromVersion)
            ->where('valid_from', '<=', $now)
            ->where('valid_to', '>', $now)
            ->orderBy('id')
            ->each(function (UserRole $role) use ($toVersion): void {
                $copy = $role->replicate();
                $copy->auth_version = $toVersion;
                $copy->save();
            });
    }
}
