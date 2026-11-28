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
    protected $signature = 'workflow:seed-templates';

    protected $description = 'Create idempotent reference workflow templates for the company';

    public function handle(TemplateService $templates): int
    {
        $seeded = 0;

        foreach ($this->definitions() as $definition) {
            $existing = \App\Modules\Workflow\Models\WorkflowTemplate::query()
                ->where('code', $definition['code'])
                ->where('version', $definition['version'])
                ->exists();

            $templates->create(0, $definition);

            if (! $existing) {
                $seeded++;
            }
        }

        $this->info("Seeded {$seeded} workflow template(s) for the company.");

        return self::SUCCESS;
    }

    /**
     * Leave approval must run the real Time effect. Every other reference
     * template keeps the explicit no-op until its owning module wires one.
     *
     * @return list<array<string, mixed>>
     */
    private function effectsFor(string $code): array
    {
        if ($code === 'leave.request') {
            return [[
                'type' => 'time.leave.apply',
                'execution' => 'LOCAL',
                'aggregate_type' => 'case',
                'aggregate_id_field' => 'case_id',
                'reverse_effect_type' => 'time.leave.reverse',
                'outcome_effect_type' => 'time.leave.pending.release',
            ]];
        }

        return [[
            'type' => 'workflow.noop',
            'execution' => 'LOCAL',
            'aggregate_type' => 'case',
            'aggregate_id_field' => 'case_id',
        ]];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function definitions(): array
    {
        // Keep this list aligned with CaseTemplateCatalog: every case type a
        // business module can open must have a published template here, and an
        // unmapped type must stay unresolvable rather than fall back.
        $codes = [
            'leave.request',
            'attendance.correction',
            'travel.request',
            'overtime.request',
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
                'version' => $code === 'leave.request' ? 2 : 1,
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
                    'effects' => $this->effectsFor((string) $code),
                ],
            ];
        }

        return $definitions;
    }
}
