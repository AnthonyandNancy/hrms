<?php

declare(strict_types=1);

namespace Tests\Unit\Authorization;

use App\Shared\Authorization\SodRules;
use PHPUnit\Framework\TestCase;

final class SodConflictTest extends TestCase
{
    public function test_payroll_prepare_and_approve_is_an_object_level_risk(): void
    {
        $risks = SodRules::risks(['payroll.run.prepare', 'payroll.run.approve', 'people.employee.read']);

        $this->assertCount(1, $risks);
        $this->assertSame('OBJECT_LEVEL', $risks[0]['kind']);
        $this->assertSame(['payroll.run.approve', 'payroll.run.prepare'], $risks[0]['pair']);
    }

    public function test_governance_and_audit_is_a_cumulative_risk(): void
    {
        $risks = SodRules::risks(['iam.binding.manage', 'iam.audit.read']);

        $this->assertCount(1, $risks);
        $this->assertSame('CUMULATIVE', $risks[0]['kind']);
    }

    public function test_unrelated_capabilities_carry_no_risk(): void
    {
        $this->assertSame([], SodRules::risks(['people.employee.read', 'time.leave.request']));
        $this->assertFalse(SodRules::hasRisk(['people.employee.read', 'time.leave.request']));
    }

    public function test_a_wildcard_holder_carries_every_risk(): void
    {
        $risks = SodRules::risks(['*']);

        $this->assertCount(6, $risks);
        $this->assertTrue(SodRules::hasRisk(['*']));
    }

    public function test_the_obsolete_hard_block_exception_file_no_longer_exists(): void
    {
        $this->assertFalse(
            file_exists(__DIR__.'/../../../app/Shared/Authorization/SodConflictException.php'),
            'Cumulative SoD is a reported risk, so the capability-layer conflict exception must be gone.',
        );
    }

    public function test_the_hard_blocking_api_no_longer_exists(): void
    {
        $this->assertFalse(
            // @phpstan-ignore-next-line The deleted method is exactly what this contract test checks.
            method_exists(SodRules::class, 'assertAllowed'),
            'SoD must be a risk report, not a hard block: the object-level checks live in the services.',
        );
    }
}
