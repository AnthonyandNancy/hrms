<?php

declare(strict_types=1);

namespace App\Modules\Lifecycle\Domain;

use App\Shared\Exceptions\ValidationException;

/**
 * Checklist resolution rules. A required task must be DONE or explicitly
 * WAIVED with a reason; optional tasks may remain pending.
 */
final class ChecklistPolicy
{
    /**
     * @param  list<array{task_code:string, required:bool, status:string, waiver_reason:?string}>  $tasks
     */
    public function assertResolved(array $tasks, string $reason = 'CHECKLIST_INCOMPLETE'): void
    {
        $unresolved = [];

        foreach ($tasks as $task) {
            if (! $task['required']) {
                continue;
            }

            $status = strtoupper($task['status']);

            if ($status === 'DONE') {
                continue;
            }

            if ($status === 'WAIVED' && is_string($task['waiver_reason'] ?? null) && trim($task['waiver_reason']) !== '') {
                continue;
            }

            $unresolved[] = $task['task_code'];
        }

        if ($unresolved !== []) {
            throw (new ValidationException(
                ['checklist' => ['Checklist items must be completed or explicitly waived with a reason: '.implode(', ', $unresolved).'.']],
                'Checklist is incomplete.',
            ))->withReason($reason);
        }
    }

    public function canComplete(bool $required, string $decision): bool
    {
        return ! $required || in_array($decision, ['DONE', 'WAIVED'], true);
    }
}
