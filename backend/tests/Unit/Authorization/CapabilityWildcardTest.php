<?php

declare(strict_types=1);

namespace Tests\Unit\Authorization;

use App\Shared\Authorization\CapabilityWildcard;
use PHPUnit\Framework\TestCase;

final class CapabilityWildcardTest extends TestCase
{
    public function test_the_wildcard_is_a_single_star(): void
    {
        $this->assertSame('*', CapabilityWildcard::WILDCARD);
    }

    public function test_is_matches_only_the_wildcard(): void
    {
        $this->assertTrue(CapabilityWildcard::is('*'));
        $this->assertFalse(CapabilityWildcard::is('iam.user.read'));
        $this->assertFalse(CapabilityWildcard::is(''));
        $this->assertFalse(CapabilityWildcard::is('**'));
        $this->assertFalse(CapabilityWildcard::is(' *'));
    }
}
