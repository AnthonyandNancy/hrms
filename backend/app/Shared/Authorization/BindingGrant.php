<?php

declare(strict_types=1);

namespace App\Shared\Authorization;

/**
 * Immutable snapshot of one authority binding. Date strings are ISO-8601 UTC
 * so the value object can safely live in the cache.
 */
final class BindingGrant
{
    public function __construct(
        public readonly int $id,
        public readonly int $tenantId,
        public readonly int $principalId,
        public readonly string $capabilityCode,
        public readonly AnchorType $anchorType,
        public readonly ?int $anchorId,
        public readonly FieldProfile $fieldProfile,
        public readonly string $validFrom,
        public readonly string $validTo,
        public readonly int $authVersion,
        public readonly string $purposeCode,
    ) {}

    public function isEffectiveOn(string $businessDate): bool
    {
        $on = $businessDate.' 00:00:00';

        return $this->validFrom <= $on && $on < $this->validTo;
    }
}
