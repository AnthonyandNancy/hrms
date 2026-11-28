<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Payroll\Domain;

use App\Modules\Payroll\Domain\PayrollRuleCompileException;
use App\Modules\Payroll\Domain\PayrollRuleCompiler;
use App\Modules\Payroll\Domain\PayrollRuleEngine;
use App\Shared\Money\Decimal;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PayrollRuleEngineTest extends TestCase
{
    public function test_if_arithmetic_and_trace_are_deterministic(): void
    {
        $rule = (new PayrollRuleCompiler)->compile([
            'type' => 'if',
            'condition' => [
                'type' => 'compare',
                'op' => 'GT',
                'left' => ['type' => 'var', 'name' => 'base_salary'],
                'right' => ['type' => 'const', 'value' => '8000'],
            ],
            'then' => [
                'type' => 'mul',
                'left' => ['type' => 'var', 'name' => 'base_salary'],
                'right' => ['type' => 'const', 'value' => '0.10'],
            ],
            'else' => [
                'type' => 'mul',
                'left' => ['type' => 'var', 'name' => 'base_salary'],
                'right' => ['type' => 'const', 'value' => '0.05'],
            ],
        ], ['base_salary']);

        $evaluation = (new PayrollRuleEngine)->evaluate($rule, ['base_salary' => '9000']);
        $this->assertSame(0, Decimal::fromString($evaluation->value)->compare(Decimal::fromString('900')));
        $this->assertNotEmpty($evaluation->trace);
        $this->assertSame('if', $evaluation->trace[array_key_last($evaluation->trace)]['type']);
    }

    public function test_progressive_tier_uses_exact_decimal_slices(): void
    {
        $rule = (new PayrollRuleCompiler)->compile([
            'type' => 'tier',
            'value' => ['type' => 'var', 'name' => 'taxable'],
            'tiers' => [
                ['up_to' => '1000', 'rate' => '0.05'],
                ['up_to' => null, 'rate' => '0.10'],
            ],
        ], ['taxable']);

        $evaluation = (new PayrollRuleEngine)->evaluate($rule, ['taxable' => '1200']);
        $this->assertSame(0, Decimal::fromString($evaluation->value)->compare(Decimal::fromString('70')));
    }

    public function test_sum_aggregates_explicit_children(): void
    {
        $rule = (new PayrollRuleCompiler)->compile([
            'type' => 'sum',
            'items' => [
                ['type' => 'const', 'value' => '1.25'],
                ['type' => 'const', 'value' => '2.75'],
            ],
        ]);

        $evaluation = (new PayrollRuleEngine)->evaluate($rule, []);
        $this->assertSame(0, Decimal::fromString($evaluation->value)->compare(Decimal::fromString('4')));
    }

    public function test_compiler_rejects_unknown_variable_and_unknown_node_property(): void
    {
        try {
            (new PayrollRuleCompiler)->compile(['type' => 'var', 'name' => 'secret'], ['base_salary']);
            $this->fail('Unknown variable must be rejected.');
        } catch (PayrollRuleCompileException $exception) {
            $this->assertSame('RULE_VAR_UNKNOWN', $exception->errors[0]['code']);
        }

        try {
            (new PayrollRuleCompiler)->compile([
                'type' => 'const',
                'value' => '1',
                'shell' => 'rm -rf /',
            ], []);
            $this->fail('Unknown node property must be rejected.');
        } catch (PayrollRuleCompileException $exception) {
            $this->assertSame('RULE_PROPERTY_UNKNOWN', $exception->errors[0]['code']);
        }
    }

    public function test_engine_rejects_division_by_zero(): void
    {
        $rule = (new PayrollRuleCompiler)->compile([
            'type' => 'div',
            'left' => ['type' => 'const', 'value' => '1'],
            'right' => ['type' => 'const', 'value' => '0'],
        ]);

        $this->expectException(InvalidArgumentException::class);
        (new PayrollRuleEngine)->evaluate($rule, []);
    }

    public function test_tier_bounds_are_strictly_increasing(): void
    {
        $this->expectException(PayrollRuleCompileException::class);
        (new PayrollRuleCompiler)->compile([
            'type' => 'tier',
            'value' => ['type' => 'const', 'value' => '100'],
            'tiers' => [
                ['up_to' => '100', 'rate' => '0.05'],
                ['up_to' => '100', 'rate' => '0.10'],
            ],
        ]);
    }
}
