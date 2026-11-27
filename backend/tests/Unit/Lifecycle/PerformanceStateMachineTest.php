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
        $this->assertFalse($machine->canAppeal('SUBMITTED'));
        $this->assertFalse($machine->canAppeal('SUBMITTED', 'ACTIVE'));
        $this->assertTrue($machine->canAppeal('SUBMITTED', 'PUBLISHED'));
        $this->assertTrue($machine->canAppeal('APPEALED', 'PUBLISHED'));
        $this->assertFalse($machine->canAppeal('DRAFT', 'PUBLISHED'));

        $this->expectException(ConflictException::class);
        $machine->assertCanSubmit('SUBMITTED');
    }
}
