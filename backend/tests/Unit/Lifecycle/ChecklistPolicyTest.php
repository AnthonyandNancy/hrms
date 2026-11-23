<?php

declare(strict_types=1);

namespace Tests\Unit\Lifecycle;

use App\Modules\Lifecycle\Domain\ChecklistPolicy;
use App\Shared\Exceptions\ValidationException;
use PHPUnit\Framework\TestCase;

final class ChecklistPolicyTest extends TestCase
{
    public function test_required_tasks_must_be_done_or_waived_with_reason(): void
    {
        $policy = new ChecklistPolicy;

        $policy->assertResolved([
            ['task_code' => 'ACCOUNT', 'required' => true, 'status' => 'DONE', 'waiver_reason' => null],
            ['task_code' => 'EQUIPMENT', 'required' => false, 'status' => 'PENDING', 'waiver_reason' => null],
        ]);

        try {
            $policy->assertResolved([
                ['task_code' => 'CONTRACT', 'required' => true, 'status' => 'PENDING', 'waiver_reason' => null],
            ]);
            $this->fail('Incomplete required checklist must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertSame('CHECKLIST_INCOMPLETE', $exception->details()['reason'] ?? null);
        }

        try {
            $policy->assertResolved([
                ['task_code' => 'CONTRACT', 'required' => true, 'status' => 'WAIVED', 'waiver_reason' => ' '],
            ]);
            $this->fail('A waiver without reason must be rejected.');
        } catch (ValidationException) {
        }
    }
}
