<?php

declare(strict_types=1);

namespace Tests\Unit\Authorization;

use App\Shared\Authorization\SodConflictException;
use App\Shared\Authorization\SodRules;
use PHPUnit\Framework\TestCase;

class SodConflictTest extends TestCase
{
    public function test_payroll_maker_and_approver_are_a_conflict(): void
    {
        $pairs = SodRules::conflicts([
            'payroll.run.prepare',
            'payroll.run.approve',
            'people.employee.read',
        ]);

        $this->assertSame([['payroll.run.approve', 'payroll.run.prepare']], $pairs);
    }

    public function test_governance_and_audit_are_a_conflict(): void
    {
        $pairs = SodRules::conflicts(['iam.binding.manage', 'iam.audit.read']);

        $this->assertCount(1, $pairs);
        $this->assertSame(['iam.audit.read', 'iam.binding.manage'], $pairs[0]);
    }

    public function test_assert_allowed_throws_a_typed_conflict(): void
    {
        $this->expectException(SodConflictException::class);

        SodRules::assertAllowed(['payroll.run.prepare', 'payroll.run.publish']);
    }

    public function test_unrelated_capabilities_are_allowed(): void
    {
        SodRules::assertAllowed(['people.employee.read', 'time.leave.request']);

        $this->assertSame([], SodRules::conflicts(['people.employee.read', 'time.leave.request']));
    }
}
