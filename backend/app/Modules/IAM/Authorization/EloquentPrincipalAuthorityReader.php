<?php

declare(strict_types=1);

namespace App\Modules\IAM\Authorization;

use App\Modules\IAM\Contracts\Principal;
use App\Modules\IAM\Contracts\PrincipalAuthorityReader;
use App\Modules\IAM\Models\AuthorityBinding;

final class EloquentPrincipalAuthorityReader implements PrincipalAuthorityReader
{
    public function grants(Principal $principal, string $businessDate): array
    {
        return AuthorityBinding::query()
            ->where('tenant_id', $principal->tenantId())
            ->where('principal_id', $principal->userId())
            ->where('auth_version', $principal->authVersion())
            ->where('valid_from', '<=', $businessDate.' 00:00:00')
            ->where('valid_to', '>', $businessDate.' 00:00:00')
            ->orderBy('capability_code')
            ->orderBy('anchor_type')
            ->orderBy('anchor_id')
            ->get()
            ->map(static fn (AuthorityBinding $binding): array => [
                'capability' => (string) $binding->capability_code,
                'anchor_type' => (string) $binding->anchor_type,
                'anchor_id' => $binding->anchor_id === null ? null : (int) $binding->anchor_id,
                'field_profile' => (string) $binding->field_profile,
            ])
            ->all();
    }
}
