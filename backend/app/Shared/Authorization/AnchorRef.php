<?php

declare(strict_types=1);

namespace App\Shared\Authorization;

/**
 * One of the seven code-owned authorization anchors. The anchor is the row
 * scope decision input; it is never parsed from client condition JSON.
 */
final readonly class AnchorRef
{
    public function __construct(
        public string $type,
        public int|string|null $id = null,
    ) {
        if (! in_array($type, ['GLOBAL', 'SELF', 'MANAGED', 'ORG_UNIT', 'ORG_SUBTREE', 'CASE', 'PAYROLL_RUN', 'LEGAL_ENTITY'], true)) {
            throw new \InvalidArgumentException("Unsupported anchor type [{$type}].");
        }
    }

    public static function global(): self
    {
        return new self('GLOBAL');
    }

    public static function self(): self
    {
        return new self('SELF');
    }

    public static function managed(): self
    {
        return new self('MANAGED');
    }

    public static function orgUnit(int $id): self
    {
        return new self('ORG_UNIT', $id);
    }

    public static function orgSubtree(int $id): self
    {
        return new self('ORG_SUBTREE', $id);
    }

    public static function legalEntity(int $id): self
    {
        return new self('LEGAL_ENTITY', $id);
    }
}
