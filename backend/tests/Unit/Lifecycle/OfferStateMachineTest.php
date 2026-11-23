<?php

declare(strict_types=1);

namespace Tests\Unit\Lifecycle;

use App\Modules\Talent\Domain\OfferStateMachine;
use App\Shared\Exceptions\ConflictException;
use PHPUnit\Framework\TestCase;

final class OfferStateMachineTest extends TestCase
{
    public function test_only_an_approved_offer_can_be_accepted_once(): void
    {
        $machine = new OfferStateMachine;

        $this->assertTrue($machine->canAccept('APPROVED'));
        $this->assertFalse($machine->canAccept('DRAFT'));
        $this->assertFalse($machine->canAccept('ACCEPTED'));
        $this->assertFalse($machine->canAccept('DECLINED'));

        $machine->assertCanAccept('APPROVED');
        $this->expectException(ConflictException::class);
        $machine->assertCanAccept('ACCEPTED');
    }
}
