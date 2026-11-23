<?php

declare(strict_types=1);

namespace App\Modules\Lifecycle\Console;

use App\Modules\Lifecycle\Domain\CaseTemplateCatalog;
use App\Modules\Lifecycle\Models\OnboardingChecklistTemplate;
use App\Modules\Lifecycle\Services\LifecycleEffectSupport;
use App\Modules\Workflow\Contracts\WorkflowCaseGateway;
use App\Shared\Time\Clock;
use Illuminate\Console\Command;

final class SeedLifecycleTemplatesCommand extends Command
{
    protected $signature = 'lifecycle:seed-templates {--tenant=1 : Tenant id}';

    protected $description = 'Idempotently seed lifecycle workflow templates and onboarding checklist tasks.';

    public function handle(WorkflowCaseGateway $workflow, CaseTemplateCatalog $catalog, LifecycleEffectSupport $support, Clock $clock): int
    {
        $tenantId = max(1, (int) $this->option('tenant'));

        foreach ($catalog->all() as $template) {
            $workflow->ensureTemplate(
                tenantId: $tenantId,
                code: (string) $template['code'],
                name: (string) $template['name'],
                definition: (array) $template['definition'],
            );
        }

        foreach ($support->checklistForTenant($tenantId) as $index => $task) {
            OnboardingChecklistTemplate::query()->updateOrCreate(
                ['tenant_id' => $tenantId, 'task_code' => $task['task_code']],
                [
                    'title' => $task['title'],
                    'required' => $task['required'],
                    'assignee_role' => $task['assignee_role'],
                    'sort_order' => $index,
                    'recorded_at' => $clock->now(),
                ],
            );
        }

        $this->info('Lifecycle templates and checklist defaults are seeded for tenant '.$tenantId.'.');

        return self::SUCCESS;
    }
}
