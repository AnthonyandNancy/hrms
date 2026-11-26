<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Services;

use App\Modules\Workflow\Domain\ConditionEvaluator;
use App\Modules\Workflow\Models\WorkflowTemplate;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Time\Clock;
use Illuminate\Support\Facades\DB;

/**
 * Template validation and persistence. Definitions are a closed declarative
 * schema: node types and assignee rules are whitelisted; conditions are
 * interpreted by ConditionEvaluator, never evaluated as code.
 */
final class TemplateService
{
    public const NODE_TYPES = ['START', 'APPROVAL', 'CC', 'CONDITION', 'END'];

    public const ASSIGNEE_RULE_TYPES = ['USER', 'MANAGER_OF_SUBJECT', 'ORG_HEAD', 'ROLE', 'CASE_MEMBER'];

    public function __construct(
        private readonly Clock $clock,
        private readonly ConditionEvaluator $conditions,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(int $actorUserId, array $data): WorkflowTemplate
    {
        $definition = $this->validateDefinition($data['definition'] ?? []);

        return DB::transaction(function () use ($data, $definition): WorkflowTemplate {
            $template = WorkflowTemplate::query()->firstOrCreate(
                [
                    'code' => (string) $data['code'],
                    'version' => (int) $data['version'],
                ],
                [
                    'name' => (string) $data['name'],
                    'status' => (string) ($data['status'] ?? 'DRAFT'),
                    'definition_json' => $definition,
                    'created_at' => $this->clock->now(),
                ],
            );

            return $template;
        });
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    public function validateDefinition(mixed $definition): array
    {
        if (! is_array($definition) || ! isset($definition['nodes']) || ! is_array($definition['nodes']) || $definition['nodes'] === []) {
            throw new ValidationException(['definition' => ['A workflow definition needs at least one node.']], 'Invalid workflow definition.');
        }

        $nodes = array_values($definition['nodes']);
        $keys = [];
        $sequences = [];
        $hasStart = false;
        $hasEnd = false;
        $hasApproval = false;

        foreach ($nodes as $index => $node) {
            if (! is_array($node)) {
                throw new ValidationException(['definition' => ["Node {$index} must be an object."]], 'Invalid workflow definition.');
            }

            $type = $node['type'] ?? null;
            if (! is_string($type) || ! in_array($type, self::NODE_TYPES, true)) {
                throw new ValidationException(['definition' => ["Node {$index} has an unsupported type."]], 'Invalid workflow definition.');
            }

            $key = $node['key'] ?? null;
            if (! is_string($key) || preg_match('/^[a-z][a-z0-9_]{0,63}$/', $key) !== 1) {
                throw new ValidationException(['definition' => ["Node {$index} has an invalid key."]], 'Invalid workflow definition.');
            }

            if (isset($keys[$key])) {
                throw new ValidationException(['definition' => ["Node key {$key} is duplicated."]], 'Invalid workflow definition.');
            }
            $keys[$key] = true;

            $sequence = $node['sequence'] ?? null;
            if (! is_int($sequence) || $sequence < 1) {
                throw new ValidationException(['definition' => ["Node {$key} needs a positive sequence."]], 'Invalid workflow definition.');
            }
            if (isset($sequences[$sequence])) {
                throw new ValidationException(['definition' => ["Node sequence {$sequence} is duplicated."]], 'Invalid workflow definition.');
            }
            $sequences[$sequence] = true;

            $hasStart = $hasStart || $type === 'START';
            $hasEnd = $hasEnd || $type === 'END';

            if ($type === 'APPROVAL') {
                $hasApproval = true;
                $assigneeRule = $node['assignee_rule'] ?? null;

                if (! is_array($assigneeRule) || ! isset($assigneeRule['type']) || ! is_string($assigneeRule['type']) || ! in_array($assigneeRule['type'], self::ASSIGNEE_RULE_TYPES, true)) {
                    throw new ValidationException(['definition' => ["Approval node {$key} has an unsupported assignee rule."]], 'Invalid workflow definition.');
                }

                $this->validateAssigneeRule($key, $assigneeRule);

                $requiredCount = $node['required_count'] ?? 1;
                if (! is_int($requiredCount) || $requiredCount < 1 || $requiredCount > 50) {
                    throw new ValidationException(['definition' => ["Approval node {$key} needs required_count between 1 and 50."]], 'Invalid workflow definition.');
                }

                if (isset($node['sla_hours']) && (! is_int($node['sla_hours']) || $node['sla_hours'] < 1 || $node['sla_hours'] > 8760)) {
                    throw new ValidationException(['definition' => ["Approval node {$key} has an invalid sla_hours."]], 'Invalid workflow definition.');
                }
            }

            if ($type === 'CONDITION') {
                if (! array_key_exists('condition', $node)) {
                    throw new ValidationException(['definition' => ["Condition node {$key} has no condition."]], 'Invalid workflow definition.');
                }

                $this->conditions->evaluate($node['condition'], []);
            }
        }

        if (! $hasStart || ! $hasEnd || ! $hasApproval) {
            throw new ValidationException(
                ['definition' => ['A template needs START, at least one APPROVAL and END nodes.']],
                'Invalid workflow definition.',
            );
        }

        foreach ((array) ($definition['effects'] ?? []) as $effect) {
            if (! is_array($effect) || ! is_string($effect['type'] ?? null) || $effect['type'] === '') {
                throw new ValidationException(['definition' => ['Each effect needs a type.']], 'Invalid workflow definition.');
            }

            if (! is_string($effect['aggregate_type'] ?? null)) {
                throw new ValidationException(['definition' => ['Each effect needs aggregate_type.']], 'Invalid workflow definition.');
            }

            $hasAggregateId = (is_string($effect['aggregate_id'] ?? null) && $effect['aggregate_id'] !== '')
                || (is_string($effect['aggregate_id_field'] ?? null) && $effect['aggregate_id_field'] !== '');

            if (! $hasAggregateId) {
                throw new ValidationException(['definition' => ['Each effect needs aggregate_id or aggregate_id_field.']], 'Invalid workflow definition.');
            }

            if (isset($effect['condition'])) {
                $this->conditions->evaluate($effect['condition'], []);
            }
        }

        $definition['nodes'] = $nodes;

        return $definition;
    }

    /**
     * @param  array<string, mixed>  $rule
     */
    private function validateAssigneeRule(string $nodeKey, array $rule): void
    {
        $allowedKeys = match ($rule['type']) {
            'USER' => ['type', 'user_id'],
            'ROLE' => ['type', 'role_code'],
            'CASE_MEMBER' => ['type', 'role'],
            default => ['type'],
        };

        foreach (array_keys($rule) as $key) {
            if (! in_array($key, $allowedKeys, true)) {
                throw new ValidationException(['definition' => ["Assignee rule on {$nodeKey} contains unsupported key {$key}."]], 'Invalid workflow definition.');
            }
        }

        if ($rule['type'] === 'USER' && (! is_int($rule['user_id'] ?? null) || $rule['user_id'] < 1)) {
            throw new ValidationException(['definition' => ["USER assignee on {$nodeKey} needs user_id."]], 'Invalid workflow definition.');
        }

        if ($rule['type'] === 'ROLE' && (! is_string($rule['role_code'] ?? null) || $rule['role_code'] === '')) {
            throw new ValidationException(['definition' => ["ROLE assignee on {$nodeKey} needs role_code."]], 'Invalid workflow definition.');
        }

        if ($rule['type'] === 'CASE_MEMBER' && isset($rule['role']) && ! in_array($rule['role'], ['REQUESTER', 'APPROVER', 'OBSERVER', 'HR_ADMIN'], true)) {
            throw new ValidationException(['definition' => ["CASE_MEMBER assignee on {$nodeKey} has an invalid role."]], 'Invalid workflow definition.');
        }
    }
}
