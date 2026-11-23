<?php

declare(strict_types=1);

namespace App\Shared\Authorization\Contracts;

/**
 * Optional contract for domain models that can name the column used for each
 * anchor. Models that do not implement it fall back to conventional columns.
 */
interface ScopeableResource
{
    public static function authorizationScopeColumn(\App\Shared\Authorization\AnchorType $anchorType): ?string;
}
