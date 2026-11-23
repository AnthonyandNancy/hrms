<?php

declare(strict_types=1);

namespace Tests\Unit\Authorization;

use App\Shared\Authorization\AnchorType;
use PHPUnit\Framework\TestCase;
use ValueError;

class AnchorTypeTest extends TestCase
{
    public function test_catalog_exposes_exactly_the_seven_frozen_anchors(): void
    {
        $this->assertSame([
            'SELF',
            'MANAGED',
            'ORG_UNIT',
            'ORG_SUBTREE',
            'CASE',
            'PAYROLL_RUN',
            'LEGAL_ENTITY',
        ], array_map(static fn (AnchorType $type): string => $type->value, AnchorType::cases()));
    }

    public function test_custom_anchor_is_rejected(): void
    {
        $this->assertNull(AnchorType::tryFrom('CUSTOM'));

        $this->expectException(ValueError::class);
        AnchorType::from('CUSTOM');
    }
}
