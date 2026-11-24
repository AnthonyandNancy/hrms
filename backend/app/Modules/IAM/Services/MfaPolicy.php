<?php

declare(strict_types=1);

namespace App\Modules\IAM\Services;

use App\Modules\IAM\Models\AuthorityBinding;
use App\Modules\IAM\Models\User;
use App\Shared\Authorization\CapabilityWildcard;
use App\Shared\Authorization\FieldProfile;
use App\Shared\Time\Clock;

/**
 * Decides whether a session must pass a second factor.
 *
 * The earlier implementation hard-coded four payroll capability codes, so a
 * newly added sensitive capability silently escaped MFA. The rule is derived
 * from the binding's field profile instead: privileged accounts and any holder
 * of a currently effective P2/P3 (or wildcard) binding must present a second
 * factor.
 */
final class MfaPolicy
{
    public const REASON_PRIVILEGED = 'PRIVILEGED';

    public const REASON_RESTRICTED_FIELDS = 'RESTRICTED_FIELDS';

    public function __construct(private readonly Clock $clock) {}

    public function challengeRequired(User $user): bool
    {
        return $this->reason($user) !== null;
    }

    public function reason(User $user): ?string
    {
        if ((bool) $user->is_privileged) {
            return self::REASON_PRIVILEGED;
        }

        $now = $this->clock->now();
        $profiles = [
            FieldProfile::P2_SENSITIVE->value,
            FieldProfile::P3_RESTRICTED->value,
        ];

        $holdsSensitive = AuthorityBinding::query()
            ->where('tenant_id', (int) $user->tenant_id)
            ->where('principal_id', (int) $user->id)
            ->where('auth_version', (int) $user->auth_version)
            ->where('valid_from', '<=', $now)
            ->where('valid_to', '>', $now)
            ->where(function ($query) use ($profiles): void {
                $query->whereIn('field_profile', $profiles)
                    ->orWhere('capability_code', CapabilityWildcard::WILDCARD);
            })
            ->exists();

        return $holdsSensitive ? self::REASON_RESTRICTED_FIELDS : null;
    }
}
