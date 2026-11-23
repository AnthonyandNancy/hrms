<?php

declare(strict_types=1);

namespace Tests\Unit\Workflow;

use App\Modules\Workflow\Domain\ConditionEvaluator;
use App\Shared\Exceptions\ValidationException;
use PHPUnit\Framework\TestCase;

class ConditionEvaluatorTest extends TestCase
{
    private ConditionEvaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new ConditionEvaluator;
    }

    public function test_supported_leaf_operators_compare_scalar_payload_fields(): void
    {
        $payload = ['amount_minor' => 250000, 'department' => 'ENG', 'urgent' => true];

        $this->assertTrue($this->evaluator->evaluate(['field' => 'amount_minor', 'operator' => 'eq', 'value' => 250000], $payload));
        $this->assertTrue($this->evaluator->evaluate(['field' => 'amount_minor', 'operator' => 'ne', 'value' => 1], $payload));
        $this->assertTrue($this->evaluator->evaluate(['field' => 'amount_minor', 'operator' => 'gte', 'value' => 250000], $payload));
        $this->assertTrue($this->evaluator->evaluate(['field' => 'amount_minor', 'operator' => 'lte', 'value' => 250000], $payload));
        $this->assertTrue($this->evaluator->evaluate(['field' => 'department', 'operator' => 'in', 'value' => ['ENG', 'OPS']], $payload));
    }

    public function test_all_and_any_compose_only_declarative_conditions(): void
    {
        $payload = ['amount_minor' => 500000, 'country' => 'CN'];

        $this->assertTrue($this->evaluator->evaluate([
            'all' => [
                ['field' => 'amount_minor', 'operator' => 'gte', 'value' => 100000],
                ['field' => 'country', 'operator' => 'in', 'value' => ['CN', 'SG']],
            ],
        ], $payload));

        $this->assertTrue($this->evaluator->evaluate([
            'any' => [
                ['field' => 'country', 'operator' => 'eq', 'value' => 'US'],
                ['field' => 'amount_minor', 'operator' => 'gte', 'value' => 500000],
            ],
        ], $payload));

        $this->assertFalse($this->evaluator->evaluate([
            'all' => [
                ['field' => 'amount_minor', 'operator' => 'gte', 'value' => 100000],
                ['field' => 'country', 'operator' => 'eq', 'value' => 'US'],
            ],
        ], $payload));
    }

    public function test_nested_dotted_payload_fields_are_supported(): void
    {
        $payload = ['leave' => ['days' => 3]];

        $this->assertTrue($this->evaluator->evaluate(
            ['field' => 'leave.days', 'operator' => 'gte', 'value' => 3],
            $payload,
        ));
    }

    public function test_missing_or_non_scalar_fields_do_not_match(): void
    {
        $payload = ['details' => ['nested' => true]];

        $this->assertFalse($this->evaluator->evaluate(['field' => 'missing', 'operator' => 'eq', 'value' => null], $payload));
        $this->assertFalse($this->evaluator->evaluate(['field' => 'details', 'operator' => 'eq', 'value' => true], $payload));
    }

    public function test_unknown_or_expression_operators_are_rejected(): void
    {
        foreach (['LIKE', 'contains', '>'] as $operator) {
            try {
                $this->evaluator->evaluate(['field' => 'name', 'operator' => $operator, 'value' => 'x'], ['name' => 'x']);
                $this->fail("Operator {$operator} must be rejected.");
            } catch (ValidationException $exception) {
                $this->assertSame('INVALID_CONDITION_OPERATOR', $exception->details()['reason'] ?? null);
            }
        }
    }

    public function test_arbitrary_expression_strings_are_rejected(): void
    {
        $invalid = [
            ['expression' => '$amount > 100'],
            ['field' => 'amount; DROP TABLE cases', 'operator' => 'eq', 'value' => 1],
            ['field' => 'payload.amount', 'operator' => 'eq', 'value' => ['nested' => 'object']],
            ['all' => 'not-an-array'],
            ['field' => 'amount', 'operator' => 'eq', 'value' => 1, 'sql' => '1=1'],
        ];

        foreach ($invalid as $condition) {
            try {
                $this->evaluator->evaluate($condition, ['amount' => 1]);
                $this->fail('Invalid condition shape must be rejected.');
            } catch (ValidationException $exception) {
                $this->assertSame('INVALID_CONDITION', $exception->details()['reason'] ?? null);
            }
        }
    }

    public function test_in_operator_requires_a_non_empty_list_of_scalars(): void
    {
        foreach ([[], 'CN', [['nested']]] as $value) {
            try {
                $this->evaluator->evaluate(['field' => 'country', 'operator' => 'in', 'value' => $value], ['country' => 'CN']);
                $this->fail('Invalid IN value must be rejected.');
            } catch (ValidationException $exception) {
                $this->assertSame('INVALID_CONDITION', $exception->details()['reason'] ?? null);
            }
        }
    }

    public function test_depth_and_node_count_are_bounded(): void
    {
        $condition = ['field' => 'x', 'operator' => 'eq', 'value' => 1];

        for ($i = 0; $i < 8; $i++) {
            $condition = ['all' => [$condition]];
        }

        $this->expectException(ValidationException::class);
        $this->evaluator->evaluate($condition, ['x' => 1]);
    }
}
