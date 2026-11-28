<?php

declare(strict_types=1);

namespace Tests\Feature\Talent;

use App\Modules\Talent\Models\TrainingCheckin;
use App\Modules\Talent\Models\TrainingEnrollment;
use App\Modules\Talent\Models\TrainingExam;
use App\Modules\Talent\Models\TrainingExamAttempt;
use App\Modules\Talent\Services\TrainingExamAttemptService;
use App\Modules\Talent\Services\TrainingService;
use App\Shared\Exceptions\ConflictException;
use Tests\Support\TalentTestCase;

final class TrainingExamAttemptSourceTest extends TalentTestCase
{
    public function test_completion_derives_score_and_pass_from_valid_attempt(): void
    {
        [$enrollment, $exam] = $this->activeEnrollmentWithExam();
        $this->checkin((int) $enrollment->session_id, (int) $enrollment->employment_id);

        $attempt = app(TrainingExamAttemptService::class)->submit(10, (int) $exam->id, (int) $enrollment->id, 80, ['q1' => 'A']);
        $this->assertTrue((bool) $attempt->passed);
        $this->assertSame(1, TrainingExamAttempt::query()->count());

        $completed = app(TrainingService::class)->complete(10, (int) $enrollment->id, [
            'completion_result' => 'FAIL',
            'score' => 0,
            'attended' => true,
        ]);

        $this->assertSame('PASS', $completed['completion_result']);
        $this->assertSame(80, $completed['score']);
        $this->assertSame((int) $attempt->id, $completed['latest_exam_attempt_id']);
        $this->assertSame(80, $completed['latest_exam_attempt_score']);
    }

    public function test_attempt_requires_checkin_and_completion_requires_attempt_when_exam_exists(): void
    {
        [$enrollment, $exam] = $this->activeEnrollmentWithExam();

        try {
            app(TrainingExamAttemptService::class)->submit(10, (int) $exam->id, (int) $enrollment->id, 80);
            $this->fail('An attempt without check-in must be rejected.');
        } catch (ConflictException $exception) {
            $this->assertSame('TRAINING_ATTEMPT_CHECKIN_REQUIRED', $exception->details()['reason'] ?? null);
        }

        $this->checkin((int) $enrollment->session_id, (int) $enrollment->employment_id);

        try {
            app(TrainingService::class)->complete(10, (int) $enrollment->id, [
                'completion_result' => 'PASS',
                'score' => 100,
            ]);
            $this->fail('Completion with a published exam must require an attempt.');
        } catch (ConflictException $exception) {
            $this->assertSame('TRAINING_EXAM_ATTEMPT_REQUIRED', $exception->details()['reason'] ?? null);
        }

        $this->assertSame(0, TrainingExamAttempt::query()->count());
    }

    /**
     * @return array{0:TrainingEnrollment,1:TrainingExam}
     */
    private function activeEnrollmentWithExam(): array
    {
        $session = $this->seedTrainingSession(5, 1001);
        $enrollment = TrainingEnrollment::query()
            ->where('session_id', (int) $session->id)
            ->where('employment_id', 1001)
            ->firstOrFail();
        $enrollment->forceFill([
            'status' => 'ENROLLED',
            'attended' => false,
            'completion_result' => null,
            'score' => null,
            'completed_at' => null,
        ])->save();

        $exam = TrainingExam::query()->create([
            'session_id' => (int) $session->id,
            'course_id' => null,
            'code' => 'EXAM-ATTEMPT-'.(int) $enrollment->id,
            'title' => 'Synthetic attempt exam',
            'version' => 1,
            'passing_score' => 60,
            'max_score' => 100,
            'status' => 'PUBLISHED',
            'published_at' => $this->clock->now(),
            'recorded_at' => $this->clock->now(),
        ]);

        return [$enrollment, $exam];
    }

    private function checkin(int $sessionId, int $employmentId): void
    {
        TrainingCheckin::query()->create([
            'session_id' => $sessionId,
            'employment_id' => $employmentId,
            'checked_in_at' => $this->clock->now(),
            'checked_out_at' => $this->clock->now()->addHour(),
            'recorded_by' => 10,
            'recorded_at' => $this->clock->now(),
        ]);
    }
}
