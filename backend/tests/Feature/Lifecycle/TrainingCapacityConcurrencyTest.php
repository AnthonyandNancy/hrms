<?php

declare(strict_types=1);

namespace Tests\Feature\Lifecycle;

use App\Modules\Talent\Services\TrainingService;
use App\Shared\Exceptions\ConflictException;
use Tests\Support\TalentTestCase;

final class TrainingCapacityConcurrencyTest extends TalentTestCase
{
    public function test_capacity_is_enforced_when_many_workers_race_for_one_seat(): void
    {
        $program = app(TrainingService::class)->createProgram(1, 10, [
            'code' => 'RACE-001',
            'title' => 'Race Training',
        ]);
        $session = app(TrainingService::class)->createSession(1, 10, [
            'program_id' => $program['program_id'],
            'starts_on' => '2026-12-03',
            'ends_on' => '2026-12-03',
            'starts_at' => '2026-12-03T09:00:00Z',
            'ends_at' => '2026-12-03T10:00:00Z',
            'capacity' => 1,
        ]);

        $successes = 0;
        $failures = 0;

        foreach ([101, 102, 103, 104] as $employmentId) {
            try {
                app(TrainingService::class)->enroll(1, 10, (int) $session['session_id'], $employmentId);
                $successes++;
            } catch (ConflictException $exception) {
                $failures++;
                $this->assertSame('TRAINING_CAPACITY_REACHED', $exception->details()['reason'] ?? null);
            }
        }

        $this->assertSame(1, $successes);
        $this->assertSame(3, $failures);
        $this->assertSame(1, \App\Modules\Talent\Models\TrainingEnrollment::query()
            ->where('session_id', $session['session_id'])->count());
    }
}
