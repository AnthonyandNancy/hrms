<?php

declare(strict_types=1);

namespace App\Shared\Authorization;

/**
 * A concrete scope target for an anchor. AnchorRef never accepts SQL or a
 * custom predicate; resolution always goes through the AnchorResolver.
 */
final class AnchorRef
{
    public function __construct(
        public readonly AnchorType $type,
        public readonly ?int $id = null,
        public readonly ?string $businessDate = null,
    ) {}

    public static function self(): self
    {
        return new self(AnchorType::SELF);
    }

    public static function managed(): self
    {
        return new self(AnchorType::MANAGED);
    }

    public static function orgUnit(int $id): self
    {
        return new self(AnchorType::ORG_UNIT, $id);
    }

    public static function orgSubtree(int $id): self
    {
        return new self(AnchorType::ORG_SUBTREE, $id);
    }

    public static function case(int $id): self
    {
        return new self(AnchorType::CASE, $id);
    }

    public static function payrollRun(int $id): self
    {
        return new self(AnchorType::PAYROLL_RUN, $id);
    }

    public static function legalEntity(int $id): self
    {
        return new self(AnchorType::LEGAL_ENTITY, $id);
    }
}
