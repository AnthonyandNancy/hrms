<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

use App\Shared\Money\Decimal;

final class PayrollRuleCompiler
{
    private const MAX_NODES = 500;

    private const MAX_DEPTH = 32;

    private const TYPES = [
        'const', 'var', 'add', 'sub', 'mul', 'div', 'neg', 'abs',
        'round', 'min', 'max', 'clamp', 'if', 'compare', 'and', 'or', 'not', 'tier',
    ];

    /**
     * @param  array<string, mixed>  $ast
     * @param  list<string>  $allowedVariables
     */
    public function compile(array $ast, array $allowedVariables = []): CompiledPayrollRule
    {
        $errors = [];
        $count = 0;
        $variables = [];
        $this->validate($ast, '$', 0, array_values(array_unique($allowedVariables)), $count, $variables, $errors);

        if ($errors !== []) {
            throw new PayrollRuleCompileException($errors);
        }

        $encoded = json_encode($ast, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return new CompiledPayrollRule(
            ast: $ast,
            hash: hash('sha256', $encoded),
            nodeCount: $count,
            depth: $this->depth($ast),
            variables: array_values(array_unique($variables)),
        );
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  list<string>  $allowedVariables
     * @param  list<string>  $variables
     * @param  list<array{path:string,code:string,message:string}>  $errors
     */
    private function validate(
        array $node,
        string $path,
        int $depth,
        array $allowedVariables,
        int &$count,
        array &$variables,
        array &$errors,
    ): void {
        $count++;
        if ($count > self::MAX_NODES) {
            $errors[] = ['path' => $path, 'code' => 'RULE_NODE_LIMIT', 'message' => 'Rule contains too many nodes.'];

            return;
        }
        if ($depth > self::MAX_DEPTH) {
            $errors[] = ['path' => $path, 'code' => 'RULE_DEPTH_LIMIT', 'message' => 'Rule nesting is too deep.'];

            return;
        }

        $type = $node['type'] ?? null;
        if (! is_string($type) || ! in_array($type, self::TYPES, true)) {
            $errors[] = ['path' => $path, 'code' => 'RULE_TYPE_UNKNOWN', 'message' => 'Unknown rule node type.'];

            return;
        }

        $allowedKeys = match ($type) {
            'const' => ['type', 'value'],
            'var' => ['type', 'name'],
            'add', 'sub', 'mul', 'div' => ['type', 'left', 'right'],
            'neg', 'abs', 'not' => ['type', 'value'],
            'round' => ['type', 'value', 'scale', 'mode'],
            'min', 'max', 'and', 'or' => ['type', 'items'],
            'clamp' => ['type', 'value', 'min', 'max'],
            'if' => ['type', 'condition', 'then', 'else'],
            'compare' => ['type', 'op', 'left', 'right'],
            'tier' => ['type', 'value', 'tiers'],
        };

        if (array_diff(array_keys($node), $allowedKeys) !== []) {
            $errors[] = ['path' => $path, 'code' => 'RULE_PROPERTY_UNKNOWN', 'message' => 'Rule node contains an unknown property.'];

            return;
        }

        if ($type === 'const') {
            if (! is_string($node['value'] ?? null) || preg_match('/^-?\d+(?:\.\d+)?$/D', (string) $node['value']) !== 1) {
                $errors[] = ['path' => $path.'.value', 'code' => 'RULE_CONST_INVALID', 'message' => 'Constant must be a plain decimal string.'];
            }

            return;
        }

        if ($type === 'var') {
            $name = $node['name'] ?? null;
            if (! is_string($name) || $name === '') {
                $errors[] = ['path' => $path.'.name', 'code' => 'RULE_VAR_INVALID', 'message' => 'Variable name is required.'];

                return;
            }
            if (! in_array($name, $allowedVariables, true)) {
                $errors[] = ['path' => $path.'.name', 'code' => 'RULE_VAR_UNKNOWN', 'message' => 'Variable is not allowed in this rule.'];

                return;
            }
            $variables[] = $name;

            return;
        }

        foreach (['left', 'right', 'value', 'min', 'max', 'condition', 'then', 'else'] as $childKey) {
            if (! array_key_exists($childKey, $node)) {
                continue;
            }
            if (! is_array($node[$childKey])) {
                $errors[] = ['path' => $path.'.'.$childKey, 'code' => 'RULE_CHILD_INVALID', 'message' => 'Rule child must be an object.'];

                continue;
            }
            $this->validate($node[$childKey], $path.'.'.$childKey, $depth + 1, $allowedVariables, $count, $variables, $errors);
        }

        if (in_array($type, ['min', 'max', 'and', 'or', 'tier'], true) && ! is_array($node['items'] ?? $node['tiers'] ?? null)) {
            $errors[] = ['path' => $path, 'code' => 'RULE_COLLECTION_INVALID', 'message' => 'Rule collection must be an array.'];

            return;
        }

        if (in_array($type, ['min', 'max', 'and', 'or'], true)) {
            $items = $node['items'];
            if (count($items) < 2) {
                $errors[] = ['path' => $path.'.items', 'code' => 'RULE_ARITY', 'message' => 'Rule collection requires at least two items.'];
            }
            foreach ($items as $index => $child) {
                if (! is_array($child)) {
                    $errors[] = ['path' => $path.'.items.'.$index, 'code' => 'RULE_CHILD_INVALID', 'message' => 'Rule child must be an object.'];

                    continue;
                }
                $this->validate($child, $path.'.items.'.$index, $depth + 1, $allowedVariables, $count, $variables, $errors);
            }
        }

        if ($type === 'tier') {
            $tiers = $node['tiers'];
            if (count($tiers) === 0 || count($tiers) > 50) {
                $errors[] = ['path' => $path.'.tiers', 'code' => 'RULE_TIER_COUNT', 'message' => 'Tier rule requires 1..50 tiers.'];
            }
            $previous = null;
            foreach ($tiers as $index => $tier) {
                if (! is_array($tier) || array_diff(array_keys($tier), ['up_to', 'rate']) !== []) {
                    $errors[] = ['path' => $path.'.tiers.'.$index, 'code' => 'RULE_TIER_INVALID', 'message' => 'Tier entry must contain only up_to and rate.'];

                    continue;
                }
                if (! is_string($tier['rate'] ?? null) || preg_match('/^-?\d+(?:\.\d+)?$/D', (string) $tier['rate']) !== 1) {
                    $errors[] = ['path' => $path.'.tiers.'.$index.'.rate', 'code' => 'RULE_TIER_RATE', 'message' => 'Tier rate must be a decimal string.'];
                }
                $upTo = $tier['up_to'] ?? null;
                if ($upTo === null) {
                    if ($index !== count($tiers) - 1) {
                        $errors[] = ['path' => $path.'.tiers.'.$index.'.up_to', 'code' => 'RULE_TIER_ORDER', 'message' => 'Only the last tier may be unbounded.'];
                    }
                } elseif (! is_string($upTo) || preg_match('/^\d+(?:\.\d+)?$/D', $upTo) !== 1) {
                    $errors[] = ['path' => $path.'.tiers.'.$index.'.up_to', 'code' => 'RULE_TIER_BOUND', 'message' => 'Tier bound must be a non-negative decimal or null.'];
                } elseif ($previous !== null && Decimal::fromString($upTo)->compare($previous) <= 0) {
                    $errors[] = ['path' => $path.'.tiers.'.$index.'.up_to', 'code' => 'RULE_TIER_ORDER', 'message' => 'Tier bounds must increase.'];
                } else {
                    $previous = Decimal::fromString($upTo);
                }
            }
        }

        if ($type === 'compare' && ! in_array($node['op'] ?? null, ['GT', 'GTE', 'LT', 'LTE', 'EQ', 'NEQ'], true)) {
            $errors[] = ['path' => $path.'.op', 'code' => 'RULE_COMPARE_OP', 'message' => 'Comparison operator is invalid.'];
        }

        if ($type === 'round' && (! is_int($node['scale'] ?? null) || $node['scale'] < 0 || $node['scale'] > 6)) {
            $errors[] = ['path' => $path.'.scale', 'code' => 'RULE_ROUND_SCALE', 'message' => 'Round scale must be 0..6.'];
        }

        if ($type === 'round' && ! in_array($node['mode'] ?? null, ['HALF_UP', 'HALF_EVEN', 'DOWN', 'UP'], true)) {
            $errors[] = ['path' => $path.'.mode', 'code' => 'RULE_ROUND_MODE', 'message' => 'Round mode is invalid.'];
        }
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function depth(array $node): int
    {
        $max = 0;
        foreach ($node as $value) {
            if (is_array($value)) {
                $max = max($max, $this->depth($value));
            }
        }

        return $max + 1;
    }
}
