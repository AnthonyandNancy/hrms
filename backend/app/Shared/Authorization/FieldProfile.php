<?php

declare(strict_types=1);

namespace App\Shared\Authorization;

/**
 * Ordered field sensitivity. A row is visible only up to the profile carried
 * by a binding that also covers that row; profiles never merge across rows.
 */
enum FieldProfile: string
{
    case P0_PUBLIC = 'P0_PUBLIC';
    case P1_INTERNAL = 'P1_INTERNAL';
    case P2_SENSITIVE = 'P2_SENSITIVE';
    case P3_RESTRICTED = 'P3_RESTRICTED';

    public function rank(): int
    {
        return match ($this) {
            self::P0_PUBLIC => 0,
            self::P1_INTERNAL => 1,
            self::P2_SENSITIVE => 2,
            self::P3_RESTRICTED => 3,
        };
    }

    public function isAtMost(self $other): bool
    {
        return $this->rank() <= $other->rank();
    }

    public function isHidden(): bool
    {
        return $this === self::P3_RESTRICTED;
    }

    public function isFilterable(): bool
    {
        return $this->rank() <= self::P1_INTERNAL->rank();
    }

    public function requiresExportCapability(): bool
    {
        return $this === self::P2_SENSITIVE;
    }
}
