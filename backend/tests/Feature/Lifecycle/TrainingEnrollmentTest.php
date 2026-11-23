<?php

declare(strict_types=1);

namespace Tests\Feature\Lifecycle;

use App\Modules\Talent\Models\TrainingEnrollment;
use Tests\Support\TalentTestCase;

final class TrainingEnrollmentTest extends TalentTestCase
{
    public function test_enrollment_capacity_is_enforced_under_repeated_attempts(): void
    {
        $program = $this->postJson('/v1/training/programs', [
            'code' => 'SAFE-001',
            'title' => 'Workplace Safety',
            'default_capacity' => 2,
        ], ['Idempotency-Key' => 'training-program-1'])->assertCreated();

        $programId = (int) $program->json('data.program_id');

        $session = $this->postJson('/v1/training/sessions', [
            'program_id' => $programId,
            'starts_on' => '2026-12-01',
            'ends_on' => '2026-12-01',
            'starts_at' => '2026-12-01T09:00:00Z',
            'ends_at' => '2026-12-01T17:00:00Z',
            'capacity' => 2,
            'location' => 'Shanghai',
        ], ['Idempotency-Key' => 'training-session-1'])->assertCreated();

        $sessionId = (int) $session->json('data.session_id');

        $this->postJson('/v1/training/enrollments', ['session_id' => $sessionId, 'employment_id' => 1], ['Idempotency-Key' => 'enroll-1'])->assertCreated();
        $this->postJson('/v1/training/enrollments', ['session_id' => $sessionId, 'employment_id' => 2], ['Idempotency-Key' => 'enroll-2'])->assertCreated();
        $this->postJson('/v1/training/enrollments', ['session_id' => $sessionId, 'employment_id' => 3], ['Idempotency-Key' => 'enroll-3'])
            ->assertConflict()
            ->assertJsonPath('data.reason', 'TRAINING_CAPACITY_REACHED');

        $this->assertSame(2, TrainingEnrollment::query()->where('session_id', $sessionId)->count());
    }

    public function test_completion_records_attendance_and_result(): void
    {
        $program = $this->postJson('/v1/training/programs', ['code' => 'SAFE-002', 'title' => 'Safety 2'], ['Idempotency-Key' => 'training-program-2'])->assertCreated();
        $session = $this->postJson('/v1/training/sessions', [
            'program_id' => (int) $program->json('data.program_id'),
            'starts_on' => '2026-12-02', 'ends_on' => '2026-12-02',
            'starts_at' => '2026-12-02T09:00:00Z', 'ends_at' => '2026-12-02T17:00:00Z',
            'capacity' => 5,
        ], ['Idempotency-Key' => 'training-session-2'])->assertCreated();

        $enrollment = $this->postJson('/v1/training/enrollments', [
            'session_id' => (int) $session->json('data.session_id'),
            'employment_id' => 7,
        ], ['Idempotency-Key' => 'enroll-complete-1'])->assertCreated();

        $this->postJson('/v1/training/enrollments/'.(int) $enrollment->json('data.enrollment_id').'/complete', [
            'attended' => true,
            'completion_result' => 'PASS',
            'score' => 90,
        ], ['Idempotency-Key' => 'complete-1'])->assertOk()
            ->assertJsonPath('data.status', 'COMPLETED')
            ->assertJsonPath('data.completion_result', 'PASS');
    }
}
