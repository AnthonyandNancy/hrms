<?php

declare(strict_types=1);

namespace App\Shared\Authorization;

/**
 * The only data anchors the authorization engine understands. There is
 * deliberately no CUSTOM variant and no arbitrary predicate language.
 */
enum AnchorType: string
{
    case SELF = 'SELF';
    case MANAGED = 'MANAGED';
    case ORG_UNIT = 'ORG_UNIT';
    case ORG_SUBTREE = 'ORG_SUBTREE';
    case CASE = 'CASE';
    case PAYROLL_RUN = 'PAYROLL_RUN';
    case COMPANY = 'COMPANY';

    public function requiresAnchorId(): bool
    {
        return match ($this) {
            self::ORG_UNIT, self::ORG_SUBTREE, self::CASE, self::PAYROLL_RUN => true,
            self::SELF, self::MANAGED, self::COMPANY => false,
        };
    }
}
