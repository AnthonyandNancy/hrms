<?php

declare(strict_types=1);

namespace App\Modules\Talent\Services;

use App\Modules\Talent\Models\TrainingCheckin;
use App\Modules\Talent\Models\TrainingEnrollment;
use App\Modules\Talent\Models\TrainingExam;
use App\Modules\Talent\Models\TrainingExamAttempt;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Observability\AuditRecorder;
use App\Shared\Time\Clock;
use Illuminate\Support\Facades\DB;

final class TrainingExamAttemptService
{
    public function __construct(
        private readonly Clock $clock,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * A score may only enter the training record through a valid attempt:
     * published exam + active enrollment + matching session/course + check-in.
     *
     * @param  array<string, mixed>  $answers
     */
    public function submit(int $actorUserId, int $examId, int $enrollmentId, int $score, array $answers = []): TrainingExamAttempt
    {
        return DB::transaction(function () use ($actorUserId, $examId, $enrollmentId, $score, $answers): TrainingExamAttempt {
            $exam = TrainingExam::query()->whereKey($examId)->lockForUpdate()->first();
            $enrollment = TrainingEnrollment::query()->whereKey($enrollmentId)->lockForUpdate()->first();

            if ($exam === null || $enrollment === null) {
                throw NotFoundException::forResource();
            }
            if ((string) $exam->status !== 'PUBLISHED' || $exam->published_at === null) {
                throw new ConflictException('Only a published exam can receive an attempt.', [
                    'reason' => 'TRAINING_EXAM_NOT_PUBLISHED',
                    'exam_id' => $examId,
                ]);
            }
            if (! in_array((string) $enrollment->status, ['ENROLLED', 'ATTENDED'], true)) {
                throw new ConflictException('Only an active enrollment can submit an attempt.', [
                    'reason' => 'TRAINING_ENROLLMENT_NOT_ACTIVE',
                    'enrollment_id' => $enrollmentId,
                ]);
            }
            if ($score < 0 || $score > (int) $exam->max_score) {
                throw new ValidationException(['score' => ['The score must be within the exam maximum.']]);
            }

            $sessionMatches = $exam->session_id !== null
                ? (int) $exam->session_id === (int) $enrollment->session_id
                : true;

            if (! $sessionMatches) {
                throw new ConflictException('The exam does not belong to the enrollment session.', [
                    'reason' => 'TRAINING_EXAM_SESSION_MISMATCH',
                ]);
            }

            $checkedIn = TrainingCheckin::query()
                ->where('session_id', (int) $enrollment->session_id)
                ->where('employment_id', (int) $enrollment->employment_id)
                ->exists();

            if (! $checkedIn) {
                throw new ConflictException('A check-in is required before an exam attempt.', [
                    'reason' => 'TRAINING_ATTEMPT_CHECKIN_REQUIRED',
                    'enrollment_id' => $enrollmentId,
                ]);
            }

            $attemptNo = ((int) TrainingExamAttempt::query()
                ->where('exam_id', $examId)
                ->where('enrollment_id', $enrollmentId)
                ->max('attempt_no')) + 1;

            $attempt = TrainingExamAttempt::query()->create([
                'exam_id' => $examId,
                'session_id' => $enrollment->session_id !== null ? (int) $enrollment->session_id : null,
                'enrollment_id' => $enrollmentId,
                'employment_id' => (int) $enrollment->employment_id,
                'attempt_no' => $attemptNo,
                'score' => $score,
                'passed' => $score >= (int) $exam->passing_score,
                'answers_json' => $answers === [] ? null : $answers,
                'source_hash' => hash('sha256', implode('|', [$examId, $enrollmentId, $attemptNo, $score, json_encode($answers, JSON_THROW_ON_ERROR)])),
                'submitted_at' => $this->clock->now(),
                'recorded_by' => $actorUserId,
                'created_at' => $this->clock->now(),
                'updated_at' => $this->clock->now(),
            ]);

            $this->audit->record(
                actorUserId: $actorUserId,
                action: 'talent.training_exam.attempted',
                aggregateType: 'training_exam_attempt',
                aggregateId: (int) $attempt->id,
                after: [
                    'exam_id' => $examId,
                    'enrollment_id' => $enrollmentId,
                    'score' => $score,
                    'passed' => (bool) $attempt->passed,
                ],
            );

            return $attempt;
        });
    }
}
