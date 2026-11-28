<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

use App\Shared\Money\Decimal;
use App\Shared\Money\RoundingMode;
use InvalidArgumentException;

final class PayrollRuleEngine
{
    /**
     * @param  array<string, string|int>  $variables
     */
    public function evaluate(CompiledPayrollRule $rule, array $variables): CompiledRuleEvaluation
    {
        $values = [];
        foreach ($rule->variables as $name) {
            if (! array_key_exists($name, $variables)) {
                throw new InvalidArgumentException("Rule variable {$name} is missing.");
            }
            $values[$name] = Decimal::fromString((string) $variables[$name]);
        }

        $trace = [];
        $value = $this->evaluateNode($rule->ast, '$', $values, $trace);

        return new CompiledRuleEvaluation($value->toDecimalString(), $trace);
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, Decimal>  $variables
     * @param  list<array{path:string,type:string,value:string}>  $trace
     */
    private function evaluateNode(array $node, string $path, array $variables, array &$trace): Decimal
    {
        $type = (string) ($node['type'] ?? '');
        $result = match ($type) {
            'const' => Decimal::fromString((string) $node['value']),
            'var' => $variables[(string) $node['name']] ?? throw new InvalidArgumentException("Rule variable {$node['name']} is missing."),
            'add' => $this->evaluateNode($node['left'], $path.'.left', $variables, $trace)
                ->add($this->evaluateNode($node['right'], $path.'.right', $variables, $trace)),
            'sub' => $this->evaluateNode($node['left'], $path.'.left', $variables, $trace)
                ->subtract($this->evaluateNode($node['right'], $path.'.right', $variables, $trace)),
            'mul' => $this->evaluateNode($node['left'], $path.'.left', $variables, $trace)
                ->multiply($this->evaluateNode($node['right'], $path.'.right', $variables, $trace)),
            'div' => $this->divide(
                $this->evaluateNode($node['left'], $path.'.left', $variables, $trace),
                $this->evaluateNode($node['right'], $path.'.right', $variables, $trace),
            ),
            'neg' => $this->evaluateNode($node['value'], $path.'.value', $variables, $trace)->negate(),
            'abs' => $this->evaluateNode($node['value'], $path.'.value', $variables, $trace)->absolute(),
            'round' => $this->evaluateNode($node['value'], $path.'.value', $variables, $trace)
                ->roundTo((int) $node['scale'], RoundingMode::from((string) $node['mode'])),
            'min' => $this->minMax($node['items'], $path, $variables, $trace, false),
            'max' => $this->minMax($node['items'], $path, $variables, $trace, true),
            'clamp' => $this->clamp(
                $this->evaluateNode($node['value'], $path.'.value', $variables, $trace),
                $this->evaluateNode($node['min'], $path.'.min', $variables, $trace),
                $this->evaluateNode($node['max'], $path.'.max', $variables, $trace),
            ),
            'if' => $this->evaluateNode($node['condition'], $path.'.condition', $variables, $trace)->isZero()
                ? $this->evaluateNode($node['else'], $path.'.else', $variables, $trace)
                : $this->evaluateNode($node['then'], $path.'.then', $variables, $trace),
            'compare' => $this->compare(
                (string) $node['op'],
                $this->evaluateNode($node['left'], $path.'.left', $variables, $trace),
                $this->evaluateNode($node['right'], $path.'.right', $variables, $trace),
            ),
            'and' => $this->booleanCollection($node['items'], $path, $variables, $trace, true),
            'or' => $this->booleanCollection($node['items'], $path, $variables, $trace, false),
            'not' => $this->evaluateNode($node['value'], $path.'.value', $variables, $trace)->isZero()
                ? Decimal::fromString('1')
                : Decimal::fromString('0'),
            'tier' => $this->tier($node, $path, $variables, $trace),
            default => throw new InvalidArgumentException("Unknown rule node type {$type}."),
        };

        $trace[] = ['path' => $path, 'type' => $type, 'value' => $result->toDecimalString()];

        return $result;
    }

    private function divide(Decimal $left, Decimal $right): Decimal
    {
        if ($right->isZero()) {
            throw new InvalidArgumentException('Payroll rule division by zero.');
        }

        return $left->divide($right, 6);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, Decimal>  $variables
     * @param  list<array{path:string,type:string,value:string}>  $trace
     */
    private function minMax(array $items, string $path, array $variables, array &$trace, bool $max): Decimal
    {
        $value = $this->evaluateNode($items[0], $path.'.items.0', $variables, $trace);
        foreach (array_slice($items, 1) as $index => $item) {
            $candidate = $this->evaluateNode($item, $path.'.items.'.($index + 1), $variables, $trace);
            $compare = $candidate->compare($value);
            if (($max && $compare > 0) || (! $max && $compare < 0)) {
                $value = $candidate;
            }
        }

        return $value;
    }

    private function clamp(Decimal $value, Decimal $min, Decimal $max): Decimal
    {
        if ($min->compare($max) > 0) {
            throw new InvalidArgumentException('Payroll rule clamp minimum exceeds maximum.');
        }

        return $value->compare($min) < 0 ? $min : ($value->compare($max) > 0 ? $max : $value);
    }

    private function compare(string $op, Decimal $left, Decimal $right): Decimal
    {
        $comparison = $left->compare($right);
        $passed = match ($op) {
            'GT' => $comparison > 0,
            'GTE' => $comparison >= 0,
            'LT' => $comparison < 0,
            'LTE' => $comparison <= 0,
            'EQ' => $comparison === 0,
            'NEQ' => $comparison !== 0,
            default => throw new InvalidArgumentException("Unknown comparison operator {$op}."),
        };

        return Decimal::fromString($passed ? '1' : '0');
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, Decimal>  $variables
     * @param  list<array{path:string,type:string,value:string}>  $trace
     */
    private function booleanCollection(array $items, string $path, array $variables, array &$trace, bool $and): Decimal
    {
        foreach ($items as $index => $item) {
            $value = $this->evaluateNode($item, $path.'.items.'.$index, $variables, $trace);
            if ($and && $value->isZero()) {
                return Decimal::fromString('0');
            }
            if (! $and && ! $value->isZero()) {
                return Decimal::fromString('1');
            }
        }

        return Decimal::fromString($and ? '1' : '0');
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, Decimal>  $variables
     * @param  list<array{path:string,type:string,value:string}>  $trace
     */
    private function tier(array $node, string $path, array $variables, array &$trace): Decimal
    {
        $value = $this->evaluateNode($node['value'], $path.'.value', $variables, $trace);
        if ($value->compare(Decimal::fromString('0')) < 0) {
            throw new InvalidArgumentException('Payroll tier base cannot be negative.');
        }

        $lower = Decimal::fromString('0');
        $total = Decimal::fromString('0');
        foreach ($node['tiers'] as $index => $tier) {
            if ($value->compare($lower) <= 0) {
                break;
            }
            $upper = $tier['up_to'] === null ? null : Decimal::fromString((string) $tier['up_to']);
            $sliceEnd = $upper === null || $value->compare($upper) <= 0 ? $value : $upper;
            $slice = $sliceEnd->subtract($lower);
            $total = $total->add($slice->multiply(Decimal::fromString((string) $tier['rate'])));
            if ($upper === null || $value->compare($upper) <= 0) {
                break;
            }
            $lower = $upper;
        }

        return $total;
    }
}
