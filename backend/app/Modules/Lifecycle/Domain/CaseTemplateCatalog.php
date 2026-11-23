<?php

declare(strict_types=1);

namespace App\Modules\Lifecycle\Domain;

/**
 * Code-owned lifecycle approval templates. The definition schema is the same
 * closed schema Workflow validates; no script/SQL/BPMN is accepted.
 */
final class CaseTemplateCatalog
{
    /**
     * @return list<array{code:string,name:string,definition:array<string,mixed>,case_type:LifecycleCaseType}>
     */
    public function all(): array
    {
        $templates = [];

        foreach (LifecycleCaseType::cases() as $type) {
            $templates[] = [
                'code' => $type->templateCode(),
                'name' => $this->name($type),
                'case_type' => $type,
                'definition' => [
                    'nodes' => [
                        ['key' => 'start', 'name' => 'Start', 'type' => 'START', 'sequence' => 1],
                        [
                            'key' => 'hr_review',
                            'name' => 'HR review',
                            'type' => 'APPROVAL',
                            'sequence' => 2,
                            'assignee_rule' => ['type' => 'ROLE', 'role_code' => 'HR_ADMIN'],
                            'required_count' => 1,
                            'sla_hours' => 48,
                        ],
                        ['key' => 'end', 'name' => 'End', 'type' => 'END', 'sequence' => 3],
                    ],
                    'effects' => [[
                        'type' => $type->effectType(),
                        'execution' => 'LOCAL',
                        'aggregate_type' => 'case',
                        'aggregate_id_field' => 'case_id',
                        'outbox_event_type' => 'lifecycle.'.strtolower($type->value).'.applied.v1',
                    ]],
                ],
            ];
        }

        return $templates;
    }

    private function name(LifecycleCaseType $type): string
    {
        return match ($type) {
            LifecycleCaseType::ONBOARDING => 'Employment onboarding',
            LifecycleCaseType::PROBATION_CONFIRM => 'Probation confirmation',
            LifecycleCaseType::ASSIGNMENT_CHANGE => 'Assignment change or transfer',
            LifecycleCaseType::TERMS_CHANGE => 'Employment terms change',
            LifecycleCaseType::OFFBOARD => 'Employment offboarding',
            LifecycleCaseType::CONTRACT_RENEW => 'Contract renewal',
            LifecycleCaseType::REHIRE => 'Rehire',
        };
    }
}
