<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Console;

use App\Modules\Workflow\Services\TemplateService;
use Illuminate\Console\Command;

/**
 * Idempotent demo workflow catalog. Definitions stay deliberately minimal:
 * published templates for the seven documented HR flows with no arbitrary
 * script, SQL or BPMN.
 */
final class SeedWorkflowTemplatesCommand extends Command
{
    protected $signature = 'workflow:seed-templates {--tenant-id=1}';

    protected $description = 'Create idempotent reference workflow templates for the demo tenant';

    public function handle(TemplateService $templates): int
    {
        $tenantId = max(1, (int) $this->option('tenant-id'));
        $seeded = 0;

        foreach ($this->definitions() as $definition) {
            $existing = \App\Modules\Workflow\Models\WorkflowTemplate::query()
                ->where('tenant_id', $tenantId)
                ->where('code', $definition['code'])
                ->where('version', $definition['version'])
                ->exists();

            $templates->create($tenantId, 0, $definition);

            if (! $existing) {
                $seeded++;
            }
        }

        $this->info("Seeded {$seeded} workflow template(s) for tenant {$tenantId}.");

        return self::SUCCESS;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function definitions(): array
    {
        $codes = [
            'leave.request',
            'transfer.request',
            'contract.renewal',
            'onboarding.request',
            'offboarding.request',
            'payroll.approval',
            'generic.approval',
        ];

        $definitions = [];

        foreach ($codes as $code) {
            $definitions[] = [
                'code' => $code,
                'name' => ucwords(str_replace(['.', '-'], ' ', $code)),
                'version' => 1,
                'status' => 'PUBLISHED',
                'definition' => [
                    'nodes' => [
                        ['key' => 'start', 'name' => 'Start', 'type' => 'START', 'sequence' => 1],
                        [
                            'key' => 'approval',
                            'name' => 'Manager approval',
                            'type' => 'APPROVAL',
                            'sequence' => 2,
                            'assignee_rule' => ['type' => 'MANAGER_OF_SUBJECT'],
                            'required_count' => 1,
                            'sla_hours' => 24,
                        ],
                        ['key' => 'end', 'name' => 'End', 'type' => 'END', 'sequence' => 3],
                    ],
                    'effects' => [
                        [
                            'type' => 'workflow.noop',
                            'execution' => 'LOCAL',
                            'aggregate_type' => 'case',
                            'aggregate_id_field' => 'case_id',
                        ],
                    ],
                ],
            ];
        }

        return $definitions;
    }
}
