<?php

declare(strict_types=1);

namespace Tests\Feature\Talent;

use App\Modules\Talent\Services\CandidateApplicationStateMachine;
use App\Modules\Talent\Services\RecruitmentOnboardingStateService;
use App\Shared\Exceptions\ConflictException;
use Tests\Support\TalentTestCase;

/**
 * Static/service-level closure checks for M16. These do not create candidate
 * or offer fixtures; they assert the single-path guards themselves.
 */
final class RecruitmentClosureContractTest extends TalentTestCase
{
    public function test_candidate_application_state_machine_rejects_illegal_and_fake_terminal_moves(): void
    {
        $machine = app(CandidateApplicationStateMachine::class);
        $machine->assertManualTransition('NEW', 'INTERVIEW');
        $machine->assertManualTransition('OFFER', 'OFFER_ACCEPTED');
        $machine->assertManualTransition('SCREENING', 'REJECTED');

        foreach ([
            ['NEW', 'HIRED'],
            ['NEW', 'CONVERTED'],
            ['INTERVIEW', 'NEW'],
            ['CONVERTED', 'OFFER'],
        ] as [$from, $to]) {
            try {
                $machine->assertManualTransition($from, $to);
                $this->fail("Expected illegal transition {$from} -> {$to} to be rejected.");
            } catch (ConflictException $exception) {
                $this->assertContains(
                    (string) $exception->details()['reason'],
                    ['INVALID_APPLICATION_TRANSITION', 'APPLICATION_TERMINAL'],
                );
            }
        }
    }

    public function test_onboarding_state_directory_is_a_real_bound_port(): void
    {
        $directory = app(RecruitmentOnboardingStateService::class);
        $state = $directory->onboardingStateForOffer(999999, '2026-12-01');

        $this->assertSame('NOT_CREATED', $state['status']);
        $this->assertNull($state['case_id']);
        $this->assertNull($state['employment_id']);
        $this->assertNull($state['person_id']);
    }
}
