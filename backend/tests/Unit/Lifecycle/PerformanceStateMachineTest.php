<?php

declare(strict_types=1);

namespace Tests\Unit\Lifecycle;

use App\Modules\Talent\Domain\PerformanceStateMachine;
use App\Shared\Exceptions\ConflictException;
use PHPUnit\Framework\TestCase;

final class PerformanceStateMachineTest extends TestCase
{
    public function test_review_submission_and_appeal_are_monotonic(): void
    {
        $machine = new PerformanceStateMachine;

        $this->assertTrue($machine->canSubmit('DRAFT'));
        $this->assertFalse($machine->canSubmit('SUBMITTED'));
        $this->assertTrue($machine->canAppeal('SUBMITTED'));
        $this->assertFalse($machine->canAppeal('APPEALED'));

        $this->expectException(ConflictException::class);
        $machine->assertCanSubmit('SUBMITTED');
    }
}
