<?php

declare(strict_types=1);

namespace App\Modules\Talent\Services;

use App\Modules\Talent\Models\TrainingCourse;
use App\Modules\Talent\Models\TrainingExam;
use App\Modules\Talent\Models\TrainingSession;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Observability\AuditRecorder;
use App\Shared\Projections\ProjectionResult;
use App\Shared\Time\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Versioned training exams with an optional session association.
 *
 * Draft saves are mutable; a published version is frozen and any later save
 * creates the next immutable version. When a session is supplied, the exam
 * course must equal the session course: the service derives it from the
 * session when the request omits course_id and rejects a mismatch with
 * TRAINING_EXAM_SESSION_MISMATCH.
 */
final class TrainingExamService
{
    public function __construct(
        private readonly Clock $clock,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function createExam(int $actorUserId, array $input): array
    {
        return DB::transaction(function () use ($actorUserId, $input): array {
            $code = (string) $input['code'];
            $courseId = array_key_exists('course_id', $input) && $input['course_id'] !== null
                ? (int) $input['course_id']
                : null;
            $sessionId = array_key_exists('session_id', $input) && $input['session_id'] !== null
                ? (int) $input['session_id']
                : null;

            [$courseId, $sessionId] = $this->resolveAssociation($courseId, $sessionId);

            $latest = TrainingExam::query()
                ->where('code', $code)
                ->orderByDesc('version')
                ->lockForUpdate()
                ->first();

            $publish = (bool) ($input['publish'] ?? false);
            $version = $latest === null ? 1 : (int) $latest->version + 1;

            // A draft is still mutable and must not multiply versions when an
            // author saves it again. Published versions are frozen, so any new
            // save creates the next immutable version instead.
            if ($latest !== null && (string) $latest->status === 'DRAFT') {
                if ((int) ($latest->session_id ?? 0) !== (int) ($sessionId ?? 0)
                    || (int) ($latest->course_id ?? 0) !== (int) ($courseId ?? 0)) {
                    // The association is part of the draft identity. Changing
                    // it in place could silently move an existing assessment.
                    throw new ConflictException('A draft exam session cannot be changed after creation.', [
                        'reason' => 'TRAINING_EXAM_SESSION_IMMUTABLE',
                        'exam_id' => (int) $latest->id,
                    ]);
                }

                $latest->forceFill([
                    'course_id' => $courseId,
                    'session_id' => $sessionId,
                    'title' => (string) $input['title'],
                    'passing_score' => (int) $input['passing_score'],
                    'max_score' => (int) $input['max_score'],
                    'status' => $publish ? 'PUBLISHED' : 'DRAFT',
                    'published_at' => $publish ? $this->clock->now() : null,
                ])->save();

                $this->audit->record($actorUserId, 'talent.training_exam.updated', 'training_exam', (int) $latest->id, after: [
                    'code' => $code,
                    'version' => (int) $latest->version,
                    'status' => (string) $latest->status,
                ]);

                return $this->examData($latest);
            }

            $exam = TrainingExam::query()->create([
                'course_id' => $courseId,
                'session_id' => $sessionId,
                'code' => $code,
                'title' => (string) $input['title'],
                'version' => $version,
                'passing_score' => (int) $input['passing_score'],
                'max_score' => (int) $input['max_score'],
                'status' => $publish ? 'PUBLISHED' : 'DRAFT',
                'published_at' => $publish ? $this->clock->now() : null,
                'recorded_at' => $this->clock->now(),
            ]);

            $this->audit->record($actorUserId, 'talent.training_exam.created', 'training_exam', (int) $exam->id, after: [
                'code' => $code,
                'version' => (int) $exam->version,
                'session_id' => $sessionId,
                'status' => (string) $exam->status,
            ]);

            return $this->examData($exam);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function publishExam(int $actorUserId, int $examId): array
    {
        return DB::transaction(function () use ($actorUserId, $examId): array {
            $exam = TrainingExam::query()->whereKey($examId)->lockForUpdate()->first();

            if ($exam === null) {
                throw NotFoundException::forResource();
            }

            if ((string) $exam->status === 'PUBLISHED') {
                throw new ConflictException('The exam version is already published and immutable.', [
                    'reason' => 'EXAM_ALREADY_PUBLISHED',
                    'exam_id' => $examId,
                ]);
            }

            $exam->forceFill(['status' => 'PUBLISHED', 'published_at' => $this->clock->now()])->save();

            $this->audit->record($actorUserId, 'talent.training_exam.published', 'training_exam', (int) $exam->id, after: [
                'version' => (int) $exam->version,
            ]);

            return $this->examData($exam);
        });
    }

    public function exams(string $asOf, ?int $sessionId = null): ProjectionResult
    {
        $items = TrainingExam::query()
            ->when($sessionId !== null, fn ($query) => $query->where('session_id', $sessionId))
            ->orderByDesc('id')
            ->get()
            ->map(fn (TrainingExam $exam): array => $this->examData($exam))
            ->values()
            ->all();

        return $this->projection($items, $asOf);
    }

    /**
     * @return array{0: int|null, 1: int|null}
     */
    private function resolveAssociation(?int $courseId, ?int $sessionId): array
    {
        if ($sessionId === null) {
            if ($courseId !== null && ! TrainingCourse::query()->whereKey($courseId)->exists()) {
                throw new ValidationException(['course_id' => ['The training course does not exist.']]);
            }

            return [$courseId, null];
        }

        $session = TrainingSession::query()->whereKey($sessionId)->first();

        if ($session === null) {
            throw new ValidationException(['session_id' => ['The training session does not exist.']], 'The given data was invalid.', [
                'reason' => 'TRAINING_EXAM_SESSION_MISMATCH',
            ]);
        }

        $sessionCourseId = $session->course_id !== null ? (int) $session->course_id : null;

        if ($courseId === null) {
            $courseId = $sessionCourseId;
        }

        if ($courseId === null || $sessionCourseId === null || $courseId !== $sessionCourseId) {
            throw new ValidationException(['session_id' => ['The exam course must match the session course.']], 'The given data was invalid.', [
                'reason' => 'TRAINING_EXAM_SESSION_MISMATCH',
                'session_id' => $sessionId,
                'session_course_id' => $sessionCourseId,
                'course_id' => $courseId,
            ]);
        }

        if (! TrainingCourse::query()->whereKey($courseId)->exists()) {
            throw new ValidationException(['course_id' => ['The training course does not exist.']]);
        }

        return [$courseId, $sessionId];
    }

    /**
     * @return array<string, mixed>
     */
    public function examData(TrainingExam $exam): array
    {
        return [
            'exam_id' => (int) $exam->id,
            'course_id' => $exam->course_id !== null ? (int) $exam->course_id : null,
            'session_id' => $exam->session_id !== null ? (int) $exam->session_id : null,
            'code' => (string) $exam->code,
            'title' => (string) $exam->title,
            'version' => (int) $exam->version,
            'passing_score' => (int) $exam->passing_score,
            'max_score' => (int) $exam->max_score,
            'status' => (string) $exam->status,
            'published_at' => $this->iso($exam->published_at),
            'recorded_at' => $this->iso($exam->recorded_at),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function projection(array $items, string $asOf): ProjectionResult
    {
        return new ProjectionResult(
            items: $items,
            page: 1,
            pageSize: max(1, min(ProjectionResult::MAX_PAGE_SIZE, count($items))),
            total: count($items),
            asOf: $asOf,
            freshness: ProjectionResult::FRESHNESS_LIVE,
            sourceHash: hash('sha256', json_encode($items, JSON_THROW_ON_ERROR)),
            partial: false,
            partialReason: null,
            drilldown: null,
        );
    }

    private function iso(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return CarbonImmutable::parse((string) $value)->toIso8601ZuluString();
    }
}
