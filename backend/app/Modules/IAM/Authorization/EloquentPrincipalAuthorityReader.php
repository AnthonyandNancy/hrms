<?php

declare(strict_types=1);

namespace App\Modules\IAM\Authorization;

use App\Modules\IAM\Contracts\Principal;
use App\Modules\IAM\Contracts\PrincipalAuthorityReader;
use App\Modules\IAM\Models\AuthorityBinding;
use App\Shared\Authorization\BindingValidity;
use App\Shared\Authorization\CapabilityWildcard;

/**
 * Reads the caller's currently effective bindings for cross-module principal
 * bridges. The wildcard is a coverage declaration, not a capability code, so
 * it is expanded here against the code-owned catalog. A concrete binding for
 * the same code always wins because it carries a narrower, auditable anchor.
 */
final class EloquentPrincipalAuthorityReader implements PrincipalAuthorityReader
{
    public function __construct(private readonly CapabilityCatalog $catalog) {}

    public function grants(Principal $principal, string $businessDate): array
    {
        $query = AuthorityBinding::query()
            ->where('principal_id', $principal->userId())
            ->where('auth_version', $principal->authVersion());

        $rows = BindingValidity::whereActiveOn($query, $businessDate)
            ->orderBy('capability_code')
            ->orderBy('anchor_type')
            ->orderBy('anchor_id')
            ->get();

        $wildcard = $rows->first(
            static fn (AuthorityBinding $binding): bool => CapabilityWildcard::is((string) $binding->capability_code),
        );

        $grants = [];

        foreach ($rows as $binding) {
            if (CapabilityWildcard::is((string) $binding->capability_code)) {
                continue;
            }

            $grants[(string) $binding->capability_code] = $this->row($binding);
        }

        if ($wildcard !== null) {
            foreach ($this->catalog->all() as $definition) {
                $grants[$definition->code] ??= [
                    'capability' => $definition->code,
                    'anchor_type' => (string) $wildcard->anchor_type,
                    'anchor_id' => $wildcard->anchor_id === null ? null : (int) $wildcard->anchor_id,
                    'field_profile' => (string) $wildcard->field_profile,
                ];
            }
        }

        return array_values($grants);
    }

    /**
     * @return array{capability: string, anchor_type: string, anchor_id: int|null, field_profile: string}
     */
    private function row(AuthorityBinding $binding): array
    {
        return [
            'capability' => (string) $binding->capability_code,
            'anchor_type' => (string) $binding->anchor_type,
            'anchor_id' => $binding->anchor_id === null ? null : (int) $binding->anchor_id,
            'field_profile' => (string) $binding->field_profile,
        ];
    }
}
