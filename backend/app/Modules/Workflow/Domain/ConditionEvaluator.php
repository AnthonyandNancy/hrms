<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Domain;

use App\Shared\Exceptions\ValidationException;

/**
 * Evaluates the only condition language a workflow template may contain:
 * {field, operator, value} leaves composed by all/any. There is deliberately
 * no parser, eval(), SQL, template string or arbitrary expression support.
 */
final class ConditionEvaluator
{
    public const OPERATORS = ['eq', 'ne', 'gte', 'lte', 'in'];

    private const MAX_DEPTH = 5;

    private const MAX_NODES = 100;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function evaluate(mixed $condition, array $payload): bool
    {
        $count = 0;

        return $this->evaluateNode($condition, $payload, 1, $count);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function evaluateNode(mixed $condition, array $payload, int $depth, int &$count): bool
    {
        if ($depth > self::MAX_DEPTH) {
            throw $this->invalidCondition('Condition nesting is too deep.');
        }

        $count++;

        if ($count > self::MAX_NODES) {
            throw $this->invalidCondition('Condition has too many nodes.');
        }

        if (! is_array($condition) || $condition === []) {
            throw $this->invalidCondition('Condition must be a non-empty object.');
        }

        $keys = array_keys($condition);

        if (in_array('all', $keys, true) || in_array('any', $keys, true)) {
            if (count($condition) !== 1) {
                throw $this->invalidCondition('A composite condition has exactly one key.');
            }

            $operator = array_key_first($condition);
            $children = $condition[$operator];

            if (! is_array($children) || $children === [] || ! array_is_list($children)) {
                throw $this->invalidCondition('all/any require a non-empty list of conditions.');
            }

            foreach ($children as $child) {
                $matched = $this->evaluateNode($child, $payload, $depth + 1, $count);

                if ($operator === 'any' && $matched) {
                    return true;
                }

                if ($operator === 'all' && ! $matched) {
                    return false;
                }
            }

            return $operator === 'all';
        }

        $expected = ['field', 'operator', 'value'];
        if (count($condition) !== 3 || array_diff($keys, $expected) !== []) {
            throw $this->invalidCondition('A condition leaf must contain only field, operator and value.');
        }

        $field = $condition['field'];
        $operator = $condition['operator'];
        $value = $condition['value'];

        if (! is_string($field) || preg_match('/^[A-Za-z][A-Za-z0-9_]*(?:\.[A-Za-z][A-Za-z0-9_]*)*$/', $field) !== 1) {
            throw $this->invalidCondition('Condition fields must be plain payload identifiers.');
        }

        if (! is_string($operator) || ! in_array($operator, self::OPERATORS, true)) {
            throw new ValidationException(
                ['condition' => ['Unsupported condition operator.']],
                'Unsupported condition operator.',
            )->withReason('INVALID_CONDITION_OPERATOR');
        }

        if ($operator === 'in') {
            if (! is_array($value) || $value === [] || ! array_is_list($value)) {
                throw $this->invalidCondition('in requires a non-empty list.');
            }

            foreach ($value as $candidate) {
                if (! is_scalar($candidate) && $candidate !== null) {
                    throw $this->invalidCondition('in accepts scalar values only.');
                }
            }
        } elseif (is_array($value) || is_object($value)) {
            throw $this->invalidCondition('Comparison values must be scalar.');
        }

        $actual = $this->resolve($payload, $field);

        if (! $actual->found || (! is_scalar($actual->value) && $actual->value !== null)) {
            return false;
        }

        $comparison = $this->compare($actual->value, $value);

        return match ($operator) {
            'eq' => $actual->value === $value,
            'ne' => $actual->value !== $value,
            'gte' => $comparison !== null && $comparison >= 0,
            'lte' => $comparison !== null && $comparison <= 0,
            'in' => in_array($actual->value, $value, true),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolve(array $payload, string $field): \stdClass
    {
        $segments = explode('.', $field);
        $value = $payload;

        foreach ($segments as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return (object) ['found' => false, 'value' => null];
            }

            $value = $value[$segment];
        }

        return (object) ['found' => true, 'value' => $value];
    }

    private function compare(mixed $left, mixed $right): ?int
    {
        if (is_numeric($left) && is_numeric($right)) {
            return (float) $left <=> (float) $right;
        }

        if (is_string($left) && is_string($right)) {
            return $left <=> $right;
        }

        // Mixed types never satisfy an ordering predicate.
        return null;
    }

    private function invalidCondition(string $message): ValidationException
    {
        return (new ValidationException(['condition' => [$message]], $message))
            ->withReason('INVALID_CONDITION');
    }
}
