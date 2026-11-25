<?php

declare(strict_types=1);

namespace Tests\Feature\Talent;

use App\Modules\Talent\Models\TrainingCheckin;
use App\Modules\Talent\Models\TrainingEnrollment;
use App\Modules\Talent\Models\TrainingExam;
use App\Modules\Talent\Models\TrainingFeedback;
use Tests\Fakes\SpySensitiveAccessAuditor;
use Tests\Support\TalentTestCase;

/**
 * K10 — full training cycle.
 *
 * These tests pin the invariants the frontend must never have to guess:
 * enrolment capacity is enforced, check-ins are idempotent and study time is
 * derived, exams are versioned, feedback is append-only, personal records are
 * live aggregates, and exports are field-whitelisted and audited.
 */
final class TrainingLifecycleTest extends TalentTestCase
{
    public function test_checkin_is_idempotent_and_records_are_derived(): void
    {
        $session = $this->seedTrainingSession();

        $first = $this->postJson("/v1/training/sessions/{$session->id}/checkins", [
            'employment_id' => 1,
            'checked_in_at' => '2026-12-01T09:00:00Z',
            'checked_out_at' => '2026-12-01T11:30:00Z',
        ], ['Idempotency-Key' => 'ci-1'])->assertCreated();

        $second = $this->postJson("/v1/training/sessions/{$session->id}/checkins", [
            'employment_id' => 1,
        ], ['Idempotency-Key' => 'ci-2'])->assertOk();

        $this->assertSame($first->json('data.checkin_id'), $second->json('data.checkin_id'));
        $this->assertSame(150, $second->json('data.study_minutes'));
        $this->assertSame(1, TrainingCheckin::query()->where('session_id', $session->id)->count());

        $this->getJson('/v1/training/checkins?session_id='.$session->id)
            ->assertOk()
            ->assertJsonPath('data.items.0.checkin_id', $first->json('data.checkin_id'))
            ->assertJsonPath('data.items.0.study_minutes', 150);

        $this->getJson('/v1/training/records')
            ->assertOk()
            ->assertJsonStructure(['data' => ['items', 'total', 'as_of', 'freshness', 'source_hash']])
            ->assertJsonPath('data.items.0.employment_id', 1)
            ->assertJsonPath('data.items.0.study_minutes', 150);
    }

    public function test_session_capacity_rejects_a_full_session_and_reenrolment_is_idempotent(): void
    {
        $program = $this->postJson('/v1/training/programs', [
            'code' => 'SAFE-CAP-01',
            'title' => 'Synthetic capacity programme',
        ], ['Idempotency-Key' => 'program-cap-1'])->assertCreated();

        $session = $this->postJson('/v1/training/sessions', [
            'program_id' => (int) $program->json('data.program_id'),
            'starts_on' => '2026-12-03',
            'ends_on' => '2026-12-03',
            'starts_at' => '2026-12-03T09:00:00Z',
            'ends_at' => '2026-12-03T17:00:00Z',
            'capacity' => 1,
        ], ['Idempotency-Key' => 'session-cap-1'])->assertCreated();

        $sessionId = (int) $session->json('data.session_id');

        $first = $this->postJson('/v1/training/enrollments', [
            'session_id' => $sessionId,
            'employment_id' => 101,
        ], ['Idempotency-Key' => 'enrol-cap-1'])->assertCreated();

        $replay = $this->postJson('/v1/training/enrollments', [
            'session_id' => $sessionId,
            'employment_id' => 101,
        ], ['Idempotency-Key' => 'enrol-cap-2'])->assertOk();

        $this->assertSame($first->json('data.enrollment_id'), $replay->json('data.enrollment_id'));

        $full = $this->postJson('/v1/training/enrollments', [
            'session_id' => $sessionId,
            'employment_id' => 102,
        ], ['Idempotency-Key' => 'enrol-cap-3'])->assertStatus(409);

        $this->assertSame('TRAINING_CAPACITY_REACHED', $full->json('data.reason'));
        $this->assertSame(1, TrainingEnrollment::query()->where('session_id', $sessionId)->count());
    }

    public function test_publishing_an_exam_freezes_its_version_and_a_change_creates_the_next_version(): void
    {
        $first = $this->postJson('/v1/training/exams', [
            'code' => 'EXAM-SAFE-01',
            'title' => 'Synthetic safety exam',
            'passing_score' => 60,
            'max_score' => 100,
            'publish' => true,
        ], ['Idempotency-Key' => 'exam-1'])->assertCreated();

        $examId = (int) $first->json('data.exam_id');
        $this->assertSame(1, $first->json('data.version'));
        $this->assertSame('PUBLISHED', $first->json('data.status'));

        $second = $this->postJson('/v1/training/exams', [
            'code' => 'EXAM-SAFE-01',
            'title' => 'Synthetic safety exam revised',
            'passing_score' => 70,
            'max_score' => 100,
        ], ['Idempotency-Key' => 'exam-2'])->assertCreated();

        $this->assertSame(2, $second->json('data.version'));
        $this->assertSame('DRAFT', $second->json('data.status'));
        $this->assertSame('Synthetic safety exam', TrainingExam::query()->whereKey($examId)->value('title'));

        $this->postJson("/v1/training/exams/{$examId}/publish", [], ['Idempotency-Key' => 'exam-publish-1'])
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'EXAM_ALREADY_PUBLISHED');

        $titles = $this->getJson('/v1/training/exams')->assertOk()->json('data.items.*.title');
        $this->assertSame(['Synthetic safety exam revised', 'Synthetic safety exam'], $titles);
    }

    public function test_feedback_is_append_only_and_command_replay_is_stable(): void
    {
        $this->seedTrainingSession();
        $enrollmentId = (int) TrainingEnrollment::query()->where('employment_id', 1)->value('id');

        $created = $this->postJson('/v1/training/feedback', [
            'enrollment_id' => $enrollmentId,
            'rating' => 5,
            'comments' => 'Synthetic first feedback',
        ], ['Idempotency-Key' => 'training-feedback-1'])->assertCreated();

        $replayed = $this->postJson('/v1/training/feedback', [
            'enrollment_id' => $enrollmentId,
            'rating' => 5,
            'comments' => 'Synthetic first feedback',
        ], ['Idempotency-Key' => 'training-feedback-1'])->assertOk();

        $replayed->assertHeader('Idempotency-Replayed', 'true');
        $this->assertSame($created->json('data.training_feedback_id'), $replayed->json('data.training_feedback_id'));

        $second = $this->postJson('/v1/training/feedback', [
            'enrollment_id' => $enrollmentId,
            'rating' => 4,
            'comments' => 'Synthetic second feedback',
        ], ['Idempotency-Key' => 'training-feedback-2'])->assertCreated();

        $this->assertNotSame($created->json('data.training_feedback_id'), $second->json('data.training_feedback_id'));
        $this->assertSame(2, TrainingFeedback::query()->where('enrollment_id', $enrollmentId)->count());
        $this->assertSame('Synthetic first feedback', TrainingFeedback::query()->whereKey((int) $created->json('data.training_feedback_id'))->value('comments'));
    }

    public function test_training_records_export_uses_an_audited_field_whitelist(): void
    {
        $auditor = new SpySensitiveAccessAuditor;
        $this->app->instance(\App\Shared\Authorization\Contracts\SensitiveAccessAuditor::class, $auditor);

        $this->seedTrainingSession();

        $exportResponse = $this->getJson('/v1/training/records/export?fields[]=employment_id&fields[]=study_minutes')
            ->assertOk()
            ->assertJsonStructure(['data' => ['items', 'total', 'as_of', 'freshness', 'source_hash']]);
        $export = $exportResponse->json('data');

        $this->assertSame(['employment_id', 'study_minutes'], $export['drilldown']['fields']);
        $this->assertSame(['employment_id', 'study_minutes'], array_keys($export['items'][0]));

        $denied = $this->getJson('/v1/training/records/export?fields[]=national_id')
            ->assertStatus(422);

        $this->assertSame('EXPORT_FIELD_NOT_ALLOWED', $denied->json('data.reason'));
        $this->assertNotEmpty($auditor->records);
        $this->assertSame('training_record_export', $auditor->records[0]['resource_type']);
        $this->assertSame(['employment_id', 'study_minutes'], $auditor->records[0]['fields']);
    }

    public function test_plan_course_and_report_aggregates_are_derived_from_live_rows(): void
    {
        $plan = $this->postJson('/v1/training/plans', [
            'code' => 'PLAN-2027-SAFE',
            'title' => 'Synthetic 2027 safety plan',
            'year' => 2027,
            'budget_cents' => 1250000,
        ], ['Idempotency-Key' => 'plan-1'])->assertCreated();

        $planId = (int) $plan->json('data.plan_id');
        $this->assertSame(1250000, $plan->json('data.budget_cents'));

        $course = $this->postJson('/v1/training/courses', [
            'plan_id' => $planId,
            'code' => 'COURSE-SAFE-01',
            'title' => 'Synthetic safety course',
            'instructor' => 'Synthetic Instructor',
            'delivery_mode' => 'ONSITE',
            'duration_minutes' => 180,
            'cost_cents' => 50000,
        ], ['Idempotency-Key' => 'course-1'])->assertCreated();

        $courseId = (int) $course->json('data.course_id');
        $this->assertSame(50000, $course->json('data.cost_cents'));

        $program = $this->postJson('/v1/training/programs', [
            'code' => 'SAFE-REPORT-01',
            'title' => 'Synthetic report programme',
        ], ['Idempotency-Key' => 'program-report-1'])->assertCreated();

        $session = $this->postJson('/v1/training/sessions', [
            'program_id' => (int) $program->json('data.program_id'),
            'plan_id' => $planId,
            'course_id' => $courseId,
            'starts_on' => '2027-01-10',
            'ends_on' => '2027-01-10',
            'starts_at' => '2027-01-10T09:00:00Z',
            'ends_at' => '2027-01-10T12:00:00Z',
            'capacity' => 5,
        ], ['Idempotency-Key' => 'session-report-1'])->assertCreated();

        $sessionId = (int) $session->json('data.session_id');

        $enrollment = $this->postJson('/v1/training/enrollments', [
            'session_id' => $sessionId,
            'employment_id' => 201,
        ], ['Idempotency-Key' => 'enrol-report-1'])->assertCreated();

        $enrollmentId = (int) $enrollment->json('data.enrollment_id');

        $this->postJson("/v1/training/sessions/{$sessionId}/checkins", [
            'employment_id' => 201,
            'checked_in_at' => '2027-01-10T09:00:00Z',
            'checked_out_at' => '2027-01-10T10:30:00Z',
        ], ['Idempotency-Key' => 'checkin-report-1'])->assertCreated();

        $this->postJson("/v1/training/enrollments/{$enrollmentId}/complete", [
            'attended' => true,
            'completion_result' => 'PASS',
            'score' => 88,
        ], ['Idempotency-Key' => 'complete-report-1'])->assertOk();

        $this->postJson('/v1/training/feedback', [
            'enrollment_id' => $enrollmentId,
            'rating' => 5,
            'comments' => 'Synthetic report feedback',
        ], ['Idempotency-Key' => 'training-feedback-report-1'])->assertCreated();

        $report = $this->getJson('/v1/training/reports?plan_id='.$planId)
            ->assertOk()
            ->assertJsonPath('data.items.0.plan_id', $planId)
            ->assertJsonPath('data.items.0.budget_cents', 1250000)
            ->assertJsonPath('data.items.0.enrolled_count', 1)
            ->assertJsonPath('data.items.0.completed_count', 1)
            ->assertJsonPath('data.items.0.completion_rate_bps', 10000)
            ->assertJsonPath('data.items.0.exam_pass_count', 1)
            ->assertJsonPath('data.items.0.study_minutes_total', 90)
            ->assertJsonPath('data.items.0.feedback_count', 1)
            ->assertJsonPath('data.items.0.average_feedback_rating_bps', 10000);

        $this->assertSame(1250000, $report->json('data.items.0.budget_cents'));
    }
}
