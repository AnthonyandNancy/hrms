<?php

declare(strict_types=1);

namespace Tests\Feature\Talent;

use App\Modules\Talent\Models\TrainingEnrollment;
use App\Modules\Talent\Models\TrainingProgram;
use App\Modules\Talent\Models\TrainingSession;
use Tests\Support\TalentTestCase;

/**
 * M17 Task 2 — cancellation releases capacity exactly once.
 */
final class TrainingEnrollmentLifecycleTest extends TalentTestCase
{
    public function test_cancel_is_idempotent_releases_capacity_and_preserves_historical_rows(): void
    {
        $sessionId = $this->sessionWithCapacity(1);

        $first = $this->postJson('/v1/training/enrollments', [
            'session_id' => $sessionId,
            'employment_id' => 201,
        ], ['Idempotency-Key' => 'e-cancel-1'])->assertCreated();

        $enrollmentId = (int) $first->json('data.enrollment_id');

        $cancelled = $this->postJson("/v1/training/enrollments/{$enrollmentId}/cancel", [
            'reason' => 'Synthetic schedule conflict',
        ], ['Idempotency-Key' => 'c-cancel-1'])
            ->assertOk()
            ->assertJsonPath('data.status', 'CANCELLED');

        $this->assertSame($enrollmentId, (int) $cancelled->json('data.enrollment_id'));

        $replayed = $this->postJson("/v1/training/enrollments/{$enrollmentId}/cancel", [
            'reason' => 'Synthetic schedule conflict',
        ], ['Idempotency-Key' => 'c-cancel-2'])
            ->assertOk()
            ->assertJsonPath('data.status', 'CANCELLED');

        $this->assertSame($enrollmentId, (int) $replayed->json('data.enrollment_id'));

        // Capacity was released by the status transition, so the second
        // employment can now take the only seat.
        $this->postJson('/v1/training/enrollments', [
            'session_id' => $sessionId,
            'employment_id' => 202,
        ], ['Idempotency-Key' => 'e-cancel-2'])->assertCreated();

        $this->assertSame(2, TrainingEnrollment::query()->where('session_id', $sessionId)->count());
        $this->assertSame(
            'CANCELLED',
            (string) TrainingEnrollment::query()->whereKey($enrollmentId)->value('status'),
        );
        $this->assertDatabaseHas('audit_events', [
            'action' => 'talent.training_enrollment.cancelled',
            'aggregate_type' => 'training_enrollment',
            'aggregate_id' => (string) $enrollmentId,
        ]);
    }

    public function test_completed_enrollment_is_not_cancellable(): void
    {
        $sessionId = $this->sessionWithCapacity(3);
        $enrollment = $this->postJson('/v1/training/enrollments', [
            'session_id' => $sessionId,
            'employment_id' => 301,
        ], ['Idempotency-Key' => 'e-completed-1'])->assertCreated();

        $enrollmentId = (int) $enrollment->json('data.enrollment_id');

        $this->postJson("/v1/training/enrollments/{$enrollmentId}/complete", [
            'attended' => true,
            'completion_result' => 'PASS',
        ], ['Idempotency-Key' => 'complete-before-cancel'])->assertOk();

        $this->postJson("/v1/training/enrollments/{$enrollmentId}/cancel", [
            'reason' => 'Synthetic late cancellation',
        ], ['Idempotency-Key' => 'c-completed'])
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'TRAINING_ENROLLMENT_NOT_CANCELLABLE');

        $this->assertSame(
            'COMPLETED',
            (string) TrainingEnrollment::query()->whereKey($enrollmentId)->value('status'),
        );
    }

    public function test_cancel_requires_capability_idempotency_and_a_real_enrollment(): void
    {
        $sessionId = $this->sessionWithCapacity(2);
        $enrollment = $this->postJson('/v1/training/enrollments', [
            'session_id' => $sessionId,
            'employment_id' => 401,
        ], ['Idempotency-Key' => 'e-auth-1'])->assertCreated();
        $enrollmentId = (int) $enrollment->json('data.enrollment_id');

        $this->postJson("/v1/training/enrollments/{$enrollmentId}/cancel", ['reason' => 'Synthetic'], [])
            ->assertUnprocessable()
            ->assertJsonPath('data.reason', 'IDEMPOTENCY_KEY_REQUIRED');

        $this->authorizer->deniedCapabilities = ['training.enrollment.manage'];
        $this->postJson("/v1/training/enrollments/{$enrollmentId}/cancel", ['reason' => 'Synthetic'], ['Idempotency-Key' => 'c-denied'])
            ->assertForbidden();

        $this->authorizer->deniedCapabilities = [];
        $this->postJson('/v1/training/enrollments/999999/cancel', ['reason' => 'Synthetic'], ['Idempotency-Key' => 'c-missing'])
            ->assertNotFound();
    }

    private function sessionWithCapacity(int $capacity): int
    {
        $program = TrainingProgram::query()->create([
            'code' => 'SYN-CANCEL-'.bin2hex(random_bytes(3)),
            'title' => 'Synthetic cancellation programme',
            'status' => 'ACTIVE',
            'default_capacity' => $capacity,
            'recorded_at' => $this->clock->now(),
        ]);

        return (int) TrainingSession::query()->create([
            'program_id' => (int) $program->id,
            'starts_on' => '2026-12-05',
            'ends_on' => '2026-12-05',
            'starts_at' => '2026-12-05 09:00:00',
            'ends_at' => '2026-12-05 17:00:00',
            'capacity' => $capacity,
            'status' => 'SCHEDULED',
            'recorded_at' => $this->clock->now(),
        ])->id;
    }
}
