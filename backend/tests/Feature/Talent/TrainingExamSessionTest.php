<?php

declare(strict_types=1);

namespace Tests\Feature\Talent;

use App\Modules\Talent\Models\TrainingCourse;
use App\Modules\Talent\Models\TrainingExam;
use App\Modules\Talent\Models\TrainingProgram;
use App\Modules\Talent\Models\TrainingSession;
use Tests\Support\TalentTestCase;

/**
 * M17 Task 2 — exam versions may target a session only when the session course
 * is the exam course, and published versions stay frozen.
 */
final class TrainingExamSessionTest extends TalentTestCase
{
    public function test_exam_session_must_match_the_session_course_and_course_is_derived_when_omitted(): void
    {
        [$sessionId, $courseId, $otherCourseId] = $this->sessionWithCourse();

        $this->postJson('/v1/training/exams', [
            'session_id' => $sessionId,
            'course_id' => $otherCourseId,
            'code' => 'EXAM-SESSION-MISMATCH',
            'title' => 'Synthetic session mismatch exam',
            'passing_score' => 60,
            'max_score' => 100,
        ], ['Idempotency-Key' => 'x-mismatch'])
            ->assertUnprocessable()
            ->assertJsonPath('data.reason', 'TRAINING_EXAM_SESSION_MISMATCH');

        $created = $this->postJson('/v1/training/exams', [
            'session_id' => $sessionId,
            'code' => 'EXAM-SESSION-OK',
            'title' => 'Synthetic session exam',
            'passing_score' => 60,
            'max_score' => 100,
        ], ['Idempotency-Key' => 'x-ok'])
            ->assertCreated()
            ->assertJsonPath('data.session_id', $sessionId)
            ->assertJsonPath('data.course_id', $courseId);

        $examId = (int) $created->json('data.exam_id');

        $this->getJson('/v1/training/exams?session_id='.$sessionId)
            ->assertOk()
            ->assertJsonPath('data.items.0.exam_id', $examId)
            ->assertJsonPath('data.items.0.session_id', $sessionId);
    }

    public function test_published_session_exam_is_frozen_and_next_save_creates_a_version_with_the_same_session(): void
    {
        [$sessionId] = $this->sessionWithCourse();

        $first = $this->postJson('/v1/training/exams', [
            'session_id' => $sessionId,
            'code' => 'EXAM-SESSION-FREEZE',
            'title' => 'Synthetic frozen exam',
            'passing_score' => 60,
            'max_score' => 100,
            'publish' => true,
        ], ['Idempotency-Key' => 'x-freeze-1'])->assertCreated();

        $firstId = (int) $first->json('data.exam_id');
        $this->assertSame('PUBLISHED', (string) $first->json('data.status'));

        $second = $this->postJson('/v1/training/exams', [
            'session_id' => $sessionId,
            'code' => 'EXAM-SESSION-FREEZE',
            'title' => 'Synthetic frozen exam v2',
            'passing_score' => 70,
            'max_score' => 100,
        ], ['Idempotency-Key' => 'x-freeze-2'])
            ->assertCreated()
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.session_id', $sessionId)
            ->assertJsonPath('data.status', 'DRAFT');

        $this->assertSame(
            'Synthetic frozen exam',
            (string) TrainingExam::query()->whereKey($firstId)->value('title'),
        );
        $this->assertSame(2, TrainingExam::query()->where('code', 'EXAM-SESSION-FREEZE')->count());

        $this->postJson("/v1/training/exams/{$firstId}/publish", [], ['Idempotency-Key' => 'x-freeze-publish'])
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'EXAM_ALREADY_PUBLISHED');
    }

    public function test_session_exam_requires_a_real_session_and_capability(): void
    {
        [$sessionId] = $this->sessionWithCourse();

        $this->postJson('/v1/training/exams', [
            'session_id' => 999999,
            'code' => 'EXAM-MISSING-SESSION',
            'title' => 'Synthetic missing session exam',
            'passing_score' => 60,
            'max_score' => 100,
        ], ['Idempotency-Key' => 'x-missing-session'])
            ->assertUnprocessable()
            ->assertJsonPath('data.reason', 'TRAINING_EXAM_SESSION_MISMATCH');

        $this->authorizer->deniedCapabilities = ['training.exam.manage'];
        $this->postJson('/v1/training/exams', [
            'session_id' => $sessionId,
            'code' => 'EXAM-DENIED',
            'title' => 'Synthetic denied exam',
            'passing_score' => 60,
            'max_score' => 100,
        ], ['Idempotency-Key' => 'x-denied'])->assertForbidden();
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function sessionWithCourse(): array
    {
        $program = TrainingProgram::query()->create([
            'code' => 'SYN-EXAM-'.bin2hex(random_bytes(3)),
            'title' => 'Synthetic exam programme',
            'status' => 'ACTIVE',
            'default_capacity' => 10,
            'recorded_at' => $this->clock->now(),
        ]);
        $course = TrainingCourse::query()->create([
            'plan_id' => null,
            'code' => 'SYN-EXAM-COURSE-'.bin2hex(random_bytes(3)),
            'title' => 'Synthetic exam course',
            'instructor' => null,
            'delivery_mode' => 'ONSITE',
            'duration_minutes' => 60,
            'cost_cents' => 0,
            'currency' => 'CNY',
            'status' => 'ACTIVE',
            'recorded_at' => $this->clock->now(),
        ]);
        $otherCourse = TrainingCourse::query()->create([
            'plan_id' => null,
            'code' => 'SYN-EXAM-OTHER-'.bin2hex(random_bytes(3)),
            'title' => 'Synthetic other course',
            'instructor' => null,
            'delivery_mode' => 'ONSITE',
            'duration_minutes' => 60,
            'cost_cents' => 0,
            'currency' => 'CNY',
            'status' => 'ACTIVE',
            'recorded_at' => $this->clock->now(),
        ]);
        $session = TrainingSession::query()->create([
            'program_id' => (int) $program->id,
            'course_id' => (int) $course->id,
            'starts_on' => '2026-12-06',
            'ends_on' => '2026-12-06',
            'starts_at' => '2026-12-06 09:00:00',
            'ends_at' => '2026-12-06 17:00:00',
            'capacity' => 10,
            'status' => 'SCHEDULED',
            'recorded_at' => $this->clock->now(),
        ]);

        return [(int) $session->id, (int) $course->id, (int) $otherCourse->id];
    }
}
