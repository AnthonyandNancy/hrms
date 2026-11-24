<?php

declare(strict_types=1);

namespace App\Shared\Authorization;

/**
 * The only wildcard capability. `*` is not a catalog entry: it never appears in
 * the `capabilities` table and can only be produced by a code-owned role
 * template. Every check for "is this the wildcard" must route through here so
 * the literal has exactly one definition.
 */
final class CapabilityWildcard
{
    public const WILDCARD = '*';

    public static function is(string $code): bool
    {
        return $code === self::WILDCARD;
    }
}
