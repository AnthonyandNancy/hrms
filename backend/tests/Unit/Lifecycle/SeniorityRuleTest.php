<?php

declare(strict_types=1);

namespace Tests\Unit\Lifecycle;

use App\Modules\Lifecycle\Domain\SeniorityRule;
use PHPUnit\Framework\TestCase;

final class SeniorityRuleTest extends TestCase
{
    public function test_preserve_and_rehire_rules_are_explicit(): void
    {
        $rule = new SeniorityRule;

        $this->assertSame('2020-01-01', $rule->resolve(SeniorityRule::PRESERVE_ORIGINAL, '2020-01-01', '2026-12-01'));
        $this->assertSame('2026-12-01', $rule->resolve(SeniorityRule::REHIRE_DATE, '2020-01-01', '2026-12-01'));
        $this->expectException(\InvalidArgumentException::class);
        $rule->resolve('GUESS_FROM_CALENDAR', '2020-01-01', '2026-12-01');
    }
}
