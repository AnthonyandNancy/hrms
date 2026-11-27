<?php

declare(strict_types=1);

namespace App\Modules\Talent\Services;

use App\Modules\Talent\Models\TrainingCourse;
use App\Modules\Talent\Models\TrainingEnrollment;
use App\Modules\Talent\Models\TrainingProgram;
use App\Modules\Talent\Models\TrainingSession;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Observability\AuditRecorder;
use App\Shared\Time\BusinessDate;
use App\Shared\Time\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class TrainingService
{
    public function __construct(
        private readonly Clock $clock,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function createProgram(int $actorUserId, array $input): array
    {
        $program = TrainingProgram::query()->firstOrCreate(
            ['code' => (string) $input['code']],
            [
                'title' => (string) $input['title'],
                'status' => 'ACTIVE',
                'default_capacity' => (int) ($input['default_capacity'] ?? 0),
                'recorded_at' => $this->clock->now(),
            ],
        );

        return [
            'program_id' => (int) $program->id,
            'code' => (string) $program->code,
            'title' => (string) $program->title,
            'status' => (string) $program->status,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function createSession(int $actorUserId, array $input): array
    {
        $program = TrainingProgram::query()
            ->whereKey((int) $input['program_id'])
            ->first();

        if ($program === null) {
            throw new ValidationException(['program_id' => ['The training program does not exist.']]);
        }

        $planId = isset($input['plan_id']) ? (int) $input['plan_id'] : null;
        $courseId = isset($input['course_id']) ? (int) $input['course_id'] : null;

        if ($courseId !== null) {
            $course = TrainingCourse::query()->whereKey($courseId)->first();

            if ($course === null) {
                throw new ValidationException(['course_id' => ['The training course does not exist.']]);
            }

            if ($planId !== null && (int) $course->plan_id !== $planId) {
                throw new ValidationException(['course_id' => ['The training course does not belong to the selected plan.']]);
            }

            $planId = (int) $course->plan_id;
        }

        $session = TrainingSession::query()->create([
            'program_id' => (int) $program->id,
            'plan_id' => $planId,
            'course_id' => $courseId,
            'starts_on' => (string) $input['starts_on'],
            'ends_on' => (string) $input['ends_on'],
            'starts_at' => (string) $input['starts_at'],
            'ends_at' => (string) $input['ends_at'],
            'capacity' => (int) $input['capacity'],
            'location' => $input['location'] ?? null,
            'status' => 'SCHEDULED',
            'recorded_at' => $this->clock->now(),
        ]);

        return $this->sessionData($session);
    }

    /**
     * Re-enrolment is idempotent. A different Idempotency-Key still returns
     * the existing row with outcome=REPLAY so the HTTP adapter can answer 200.
     *
     * @return array<string, mixed>
     */
    public function enroll(int $actorUserId, int $sessionId, int $employmentId): array
    {
        return DB::transaction(function () use ($actorUserId, $sessionId, $employmentId): array {
            /** @var TrainingSession $session */
            $session = TrainingSession::query()
                ->whereKey($sessionId)
                ->lockForUpdate()
                ->firstOrFail();

            $existing = TrainingEnrollment::query()
                ->where('session_id', $sessionId)
                ->where('employment_id', $employmentId)
                ->first();

            if ($existing !== null) {
                return [...$this->enrollmentData($existing), 'outcome' => 'REPLAY'];
            }

            $count = TrainingEnrollment::query()
                ->where('session_id', $sessionId)
                ->whereIn('status', ['ENROLLED', 'ATTENDED', 'COMPLETED'])
                ->count();

            // Capacity is enforced under the session row lock; the unique
            // enrollment key closes the double-enroll race as a second guard.
            if ($count >= (int) $session->capacity) {
                throw new ConflictException('Training session capacity has been reached.', [
                    'reason' => 'TRAINING_CAPACITY_REACHED',
                    'session_id' => $sessionId,
                    'capacity' => (int) $session->capacity,
                ]);
            }

            try {
                $enrollment = TrainingEnrollment::query()->create([
                    'session_id' => $sessionId,
                    'employment_id' => $employmentId,
                    'status' => 'ENROLLED',
                    'attended' => false,
                    'recorded_at' => $this->clock->now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                $enrollment = TrainingEnrollment::query()
                    ->where('session_id', $sessionId)
                    ->where('employment_id', $employmentId)
                    ->firstOrFail();
            }

            $this->audit->record($actorUserId, 'talent.training.enrolled', 'training_enrollment', (int) $enrollment->id, after: [
                'session_id' => $sessionId,
                'employment_id' => $employmentId,
            ]);

            return [...$this->enrollmentData($enrollment), 'outcome' => 'CREATED'];
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function complete(int $actorUserId, int $enrollmentId, array $input): array
    {
        return DB::transaction(function () use ($actorUserId, $enrollmentId, $input): array {
            $enrollment = TrainingEnrollment::query()
                ->whereKey($enrollmentId)
                ->lockForUpdate()
                ->first();

            if ($enrollment === null) {
                throw NotFoundException::forResource();
            }

            if ($enrollment->status === 'COMPLETED') {
                return $this->enrollmentData($enrollment);
            }

            // Only an active enrolment may complete. A cancelled or otherwise
            // inactive row must never be silently revived by a completion.
            if (! in_array((string) $enrollment->status, ['ENROLLED', 'ATTENDED'], true)) {
                throw new ConflictException('Only an active enrollment can be completed.', [
                    'reason' => 'TRAINING_ENROLLMENT_NOT_ACTIVE',
                    'enrollment_id' => $enrollmentId,
                    'status' => (string) $enrollment->status,
                ]);
            }

            // A supplied completion date is a business DATE with no time
            // component; anchor it at UTC midnight so it never shifts day.
            $completedOn = isset($input['completed_on']) && is_string($input['completed_on'])
                ? BusinessDate::parse($input['completed_on'])
                : $this->clock->now();

            $enrollment->forceFill([
                'status' => 'COMPLETED',
                'attended' => (bool) ($input['attended'] ?? true),
                'completion_result' => (string) $input['completion_result'],
                'score' => isset($input['score']) ? (int) $input['score'] : null,
                'completed_at' => $completedOn,
            ])->save();

            $this->audit->record($actorUserId, 'talent.training.completed', 'training_enrollment', (int) $enrollment->id, after: [
                'completion_result' => (string) $enrollment->completion_result,
            ]);

            return $this->enrollmentData($enrollment);
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function sessions(?int $programId = null, ?string $status = null): array
    {
        return TrainingSession::query()
            ->when($programId !== null, fn ($builder) => $builder->where('program_id', $programId))
            ->when($status !== null && $status !== '', fn ($builder) => $builder->where('status', $status))
            ->orderByDesc('id')
            ->get()
            ->map(fn (TrainingSession $session): array => [
                'session_id' => (int) $session->id,
                'program_id' => (int) $session->program_id,
                'plan_id' => $session->plan_id !== null ? (int) $session->plan_id : null,
                'course_id' => $session->course_id !== null ? (int) $session->course_id : null,
                'starts_on' => (string) $session->starts_on,
                'ends_on' => (string) $session->ends_on,
                'starts_at' => $this->iso($session->starts_at),
                'ends_at' => $this->iso($session->ends_at),
                'capacity' => (int) $session->capacity,
                'location' => $session->location,
                'status' => (string) $session->status,
            ])->values()->all();
    }

    private function iso(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return CarbonImmutable::parse((string) $value)->toIso8601ZuluString();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function report(?int $programId = null, ?int $employmentId = null): array
    {
        return TrainingEnrollment::query()
            ->when($employmentId !== null, fn ($query) => $query->where('employment_id', $employmentId))
            ->when($programId !== null, fn ($query) => $query->whereIn(
                'session_id',
                TrainingSession::query()->where('program_id', $programId)->select('id'),
            ))
            ->orderBy('id')
            ->get()
            ->map(fn (TrainingEnrollment $enrollment): array => $this->enrollmentData($enrollment))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionData(TrainingSession $session): array
    {
        return [
            'session_id' => (int) $session->id,
            'program_id' => (int) $session->program_id,
            'plan_id' => $session->plan_id !== null ? (int) $session->plan_id : null,
            'course_id' => $session->course_id !== null ? (int) $session->course_id : null,
            'starts_on' => (string) $session->starts_on,
            'ends_on' => (string) $session->ends_on,
            'capacity' => (int) $session->capacity,
            'status' => (string) $session->status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function enrollmentData(TrainingEnrollment $enrollment): array
    {
        return [
            'enrollment_id' => (int) $enrollment->id,
            'session_id' => (int) $enrollment->session_id,
            'employment_id' => (int) $enrollment->employment_id,
            'status' => (string) $enrollment->status,
            'attended' => (bool) $enrollment->attended,
            'completion_result' => $enrollment->completion_result,
            'score' => $enrollment->score,
            'completed_at' => $enrollment->completed_at !== null ? CarbonImmutable::parse((string) $enrollment->completed_at)->toIso8601ZuluString() : null,
        ];
    }
}
