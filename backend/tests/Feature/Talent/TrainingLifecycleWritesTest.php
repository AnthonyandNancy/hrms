<?php

declare(strict_types=1);

namespace Tests\Feature\Talent;

use App\Modules\Talent\Models\TrainingCourse;
use App\Modules\Talent\Models\TrainingEnrollment;
use App\Modules\Talent\Models\TrainingProgram;
use App\Modules\Talent\Models\TrainingSession;
use Tests\Support\TalentTestCase;

/**
 * M17 Task 1 — guarded catalogue/session update commands.
 *
 * The tests are intentionally written against HTTP commands so they pin the
 * route capability, Idempotency-Key, validation envelope and audit side effect
 * together with the service state machine.
 */
final class TrainingLifecycleWritesTest extends TalentTestCase
{
    public function test_program_conflict_update_and_session_time_validation(): void
    {
        $created = $this->postJson('/v1/training/programs', [
            'code' => 'LEAD-01',
            'title' => 'Synthetic leadership programme',
            'default_capacity' => 20,
        ], ['Idempotency-Key' => 'p-create-1'])->assertCreated();

        $programId = (int) $created->json('data.program_id');

        $this->postJson('/v1/training/programs', [
            'code' => 'LEAD-01',
            'title' => 'Synthetic different title',
        ], ['Idempotency-Key' => 'p-create-2'])
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'TRAINING_PROGRAM_DUPLICATE');

        $this->patchJson("/v1/training/programs/{$programId}", [
            'title' => 'Synthetic leadership advanced',
            'default_capacity' => 30,
        ], ['Idempotency-Key' => 'p-update-1'])
            ->assertOk()
            ->assertJsonPath('data.title', 'Synthetic leadership advanced')
            ->assertJsonPath('data.default_capacity', 30);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'talent.training_program.updated',
            'aggregate_type' => 'training_program',
            'aggregate_id' => (string) $programId,
        ]);

        $this->postJson('/v1/training/sessions', [
            'program_id' => $programId,
            'starts_on' => '2026-12-01',
            'ends_on' => '2026-12-01',
            'starts_at' => '2026-12-01T17:00:00Z',
            'ends_at' => '2026-12-01T09:00:00Z',
            'capacity' => 10,
        ], ['Idempotency-Key' => 's-bad-time'])
            ->assertUnprocessable()
            ->assertJsonPath('data.reason', 'TRAINING_SESSION_TIME_INVALID');
    }

    public function test_program_capacity_cannot_drop_below_live_enrollments_and_archive_requires_no_scheduled_sessions(): void
    {
        $program = TrainingProgram::query()->create([
            'code' => 'SYN-PROGRAM-CAPACITY',
            'title' => 'Synthetic capacity programme',
            'status' => 'ACTIVE',
            'default_capacity' => 5,
            'recorded_at' => $this->clock->now(),
        ]);
        $session = TrainingSession::query()->create([
            'program_id' => (int) $program->id,
            'starts_on' => '2026-12-02',
            'ends_on' => '2026-12-02',
            'starts_at' => '2026-12-02 09:00:00',
            'ends_at' => '2026-12-02 17:00:00',
            'capacity' => 5,
            'status' => 'SCHEDULED',
            'recorded_at' => $this->clock->now(),
        ]);
        TrainingEnrollment::query()->create([
            'session_id' => (int) $session->id,
            'employment_id' => 9101,
            'status' => 'ENROLLED',
            'attended' => false,
            'recorded_at' => $this->clock->now(),
        ]);
        TrainingEnrollment::query()->create([
            'session_id' => (int) $session->id,
            'employment_id' => 9102,
            'status' => 'ENROLLED',
            'attended' => false,
            'recorded_at' => $this->clock->now(),
        ]);

        $this->patchJson("/v1/training/programs/{$program->id}", [
            'default_capacity' => 1,
        ], ['Idempotency-Key' => 'p-capacity-below'])
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'TRAINING_CAPACITY_BELOW_ENROLLED');

        $this->patchJson("/v1/training/programs/{$program->id}", [
            'status' => 'ARCHIVED',
        ], ['Idempotency-Key' => 'p-archive-active-session'])
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'TRAINING_PROGRAM_HAS_SESSIONS');

        $this->assertSame('ACTIVE', (string) $program->fresh()?->status);
    }

    public function test_course_update_only_allows_mutable_fields_and_audits_the_change(): void
    {
        $course = TrainingCourse::query()->create([
            'plan_id' => null,
            'code' => 'SYN-COURSE-EDIT',
            'title' => 'Synthetic course',
            'instructor' => 'Synthetic Instructor',
            'delivery_mode' => 'ONSITE',
            'duration_minutes' => 60,
            'cost_cents' => 5000,
            'currency' => 'CNY',
            'status' => 'ACTIVE',
            'recorded_at' => $this->clock->now(),
        ]);

        $this->patchJson("/v1/training/courses/{$course->id}", [
            'title' => 'Synthetic course revised',
            'instructor' => 'Synthetic Instructor B',
            'duration_minutes' => 90,
            'materials' => 'Synthetic updated material',
        ], ['Idempotency-Key' => 'c-update-1'])
            ->assertOk()
            ->assertJsonPath('data.title', 'Synthetic course revised')
            ->assertJsonPath('data.duration_minutes', 90)
            ->assertJsonPath('data.code', 'SYN-COURSE-EDIT');

        $this->assertDatabaseHas('audit_events', [
            'action' => 'talent.training_course.updated',
            'aggregate_type' => 'training_course',
            'aggregate_id' => (string) $course->id,
        ]);

        // The request contract has no code/cost fields; callers cannot slide an
        // immutable field through validation, and an unknown field is ignored
        // by the validated payload rather than written.
        $this->patchJson("/v1/training/courses/{$course->id}", [
            'code' => 'SHOULD-NOT-CHANGE',
            'cost_cents' => 999999,
        ], ['Idempotency-Key' => 'c-update-immutable'])
            ->assertOk();

        $fresh = TrainingCourse::query()->whereKey((int) $course->id)->firstOrFail();
        $this->assertSame('SYN-COURSE-EDIT', (string) $fresh->code);
        $this->assertSame(5000, (int) $fresh->cost_cents);
    }

    public function test_session_update_rejects_instructor_overlap_and_requires_no_active_enrollments_to_finish(): void
    {
        $program = TrainingProgram::query()->create([
            'code' => 'SYN-PROGRAM-OVERLAP',
            'title' => 'Synthetic overlap programme',
            'status' => 'ACTIVE',
            'default_capacity' => 10,
            'recorded_at' => $this->clock->now(),
        ]);
        $course = TrainingCourse::query()->create([
            'plan_id' => null,
            'code' => 'SYN-COURSE-OVERLAP',
            'title' => 'Synthetic instructor overlap course',
            'instructor' => 'Synthetic Shared Instructor',
            'delivery_mode' => 'ONSITE',
            'duration_minutes' => 120,
            'cost_cents' => 0,
            'currency' => 'CNY',
            'status' => 'ACTIVE',
            'recorded_at' => $this->clock->now(),
        ]);
        $first = TrainingSession::query()->create([
            'program_id' => (int) $program->id,
            'course_id' => (int) $course->id,
            'starts_on' => '2026-12-04',
            'ends_on' => '2026-12-04',
            'starts_at' => '2026-12-04 09:00:00',
            'ends_at' => '2026-12-04 11:00:00',
            'capacity' => 10,
            'status' => 'SCHEDULED',
            'recorded_at' => $this->clock->now(),
        ]);
        $second = TrainingSession::query()->create([
            'program_id' => (int) $program->id,
            'course_id' => (int) $course->id,
            'starts_on' => '2026-12-04',
            'ends_on' => '2026-12-04',
            'starts_at' => '2026-12-04 10:00:00',
            'ends_at' => '2026-12-04 12:00:00',
            'capacity' => 10,
            'status' => 'SCHEDULED',
            'recorded_at' => $this->clock->now(),
        ]);

        $this->patchJson("/v1/training/sessions/{$second->id}", [
            'starts_at' => '2026-12-04T09:30:00Z',
            'ends_at' => '2026-12-04T11:30:00Z',
        ], ['Idempotency-Key' => 's-overlap'])
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'TRAINING_SESSION_CONFLICT');

        TrainingEnrollment::query()->create([
            'session_id' => (int) $first->id,
            'employment_id' => 9201,
            'status' => 'ENROLLED',
            'attended' => false,
            'recorded_at' => $this->clock->now(),
        ]);

        $this->patchJson("/v1/training/sessions/{$first->id}", [
            'status' => 'COMPLETED',
        ], ['Idempotency-Key' => 's-complete-with-active'])
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'TRAINING_SESSION_HAS_ACTIVE_ENROLLMENTS');

        $this->assertSame('SCHEDULED', (string) $first->fresh()?->status);
    }

    public function test_training_update_commands_require_authentication_capabilities_and_idempotency_key(): void
    {
        $program = TrainingProgram::query()->create([
            'code' => 'SYN-PROGRAM-AUTH',
            'title' => 'Synthetic auth programme',
            'status' => 'ACTIVE',
            'default_capacity' => 5,
            'recorded_at' => $this->clock->now(),
        ]);
        $course = TrainingCourse::query()->create([
            'plan_id' => null,
            'code' => 'SYN-COURSE-AUTH',
            'title' => 'Synthetic auth course',
            'instructor' => null,
            'delivery_mode' => 'ONSITE',
            'duration_minutes' => 30,
            'cost_cents' => 0,
            'currency' => 'CNY',
            'status' => 'ACTIVE',
            'recorded_at' => $this->clock->now(),
        ]);

        $this->patchJson("/v1/training/programs/{$program->id}", ['title' => 'Synthetic updated'], ['Idempotency-Key' => 'p-auth-ok'])
            ->assertOk();

        $this->patchJson("/v1/training/programs/{$program->id}", ['title' => 'Synthetic changed'], [])
            ->assertUnprocessable()
            ->assertJsonPath('data.reason', 'IDEMPOTENCY_KEY_REQUIRED');

        $this->authorizer->deniedCapabilities = ['training.course.manage'];
        $this->patchJson("/v1/training/courses/{$course->id}", ['title' => 'Denied title'], ['Idempotency-Key' => 'c-denied'])
            ->assertForbidden();

        $this->app->forgetInstance(\App\Modules\IAM\Contracts\Principal::class);
        $this->patchJson("/v1/training/programs/{$program->id}", ['title' => 'Anonymous'], ['Idempotency-Key' => 'p-anon'])
            ->assertUnauthorized();
    }
}
