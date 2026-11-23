<?php

declare(strict_types=1);

namespace App\Modules\Workflow\Console;

use App\Modules\Workflow\Services\ReassignmentService;
use Illuminate\Console\Command;

final class ReassignWorkflowTasksCommand extends Command
{
    protected $signature = 'workflow:reassign {--case= : Limit reassignment to one case id}';

    protected $description = 'Reassign pending tasks whose assignee is terminated, transferred or on long leave';

    public function handle(ReassignmentService $reassignments): int
    {
        $caseId = $this->option('case');
        $result = $reassignments->reassign($caseId !== null ? (int) $caseId : null);

        $this->info("Checked {$result['checked']} pending task(s): {$result['reassigned']} reassigned, {$result['exhausted']} exhausted.");

        return self::SUCCESS;
    }
}
