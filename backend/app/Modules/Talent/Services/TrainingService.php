<?php

declare(strict_types=1);

namespace App\Modules\Talent\Services;

use App\Modules\Talent\Models\TrainingCourse;
use App\Modules\Talent\Models\TrainingEnrollment;
use App\Modules\Talent\Models\TrainingPlan;
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

/**
 * Training catalogue and session command model.
 *
 * All mutating methods are called from TrainingController inside CommandRunner,
 * which supplies the Idempotency-Key and transaction boundary. This service
 * owns the business state machine, cross-row association checks and audit facts.
 */
final class TrainingService
{
    public function __construct(
        private readonly Clock $clock,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * Program codes are unique. Replaying the exact same create payload returns
     * the stored row; reusing the code with different content is a stable 409.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function createProgram(int $actorUserId, array $input): array
    {
        return DB::transaction(function () use ($actorUserId, $input): array {
            $code = (string) $input['code'];
            $title = (string) $input['title'];
            $defaultCapacity = (int) ($input['default_capacity'] ?? 0);

            $program = TrainingProgram::query()->firstOrCreate(
                ['code' => $code],
                [
                    'title' => $title,
                    'status' => 'ACTIVE',
                    'default_capacity' => $defaultCapacity,
                    'recorded_at' => $this->clock->now(),
                ],
            );

            if (! $program->wasRecentlyCreated) {
                $sameContent = (string) $program->title === $title
                    && (int) $program->default_capacity === $defaultCapacity
                    && (string) $program->status === 'ACTIVE';

                if (! $sameContent) {
                    throw new ConflictException('A training program with this code already exists.', [
                        'reason' => 'TRAINING_PROGRAM_DUPLICATE',
                        'program_id' => (int) $program->id,
                    ]);
                }

                return [...$this->programData($program), 'outcome' => 'REPLAY'];
            }

            $this->audit->record($actorUserId, 'talent.training_program.created', 'training_program', (int) $program->id, after: [
                'code' => (string) $program->code,
                'default_capacity' => (int) $program->default_capacity,
            ]);

            return [...$this->programData($program), 'outcome' => 'CREATED'];
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function updateProgram(int $actorUserId, int $programId, array $input): array
    {
        return DB::transaction(function () use ($actorUserId, $programId, $input): array {
            $program = TrainingProgram::query()->whereKey($programId)->lockForUpdate()->first();

            if ($program === null) {
                throw NotFoundException::forResource();
            }

            $status = array_key_exists('status', $input) ? (string) $input['status'] : (string) $program->status;

            if (! in_array($status, ['ACTIVE', 'ARCHIVED'], true)) {
                throw new ValidationException(['status' => ['The training program status is invalid.']], 'The given data was invalid.', [
                    'reason' => 'TRAINING_PROGRAM_STATE_INVALID',
                ]);
            }

            if ((string) $program->status === 'ARCHIVED' && $status === 'ACTIVE') {
                throw new ConflictException('An archived training program cannot be reactivated.', [
                    'reason' => 'TRAINING_PROGRAM_STATE_INVALID',
                    'program_id' => (int) $program->id,
                    'status' => (string) $program->status,
                ]);
            }

            if ($status === 'ARCHIVED' && (string) $program->status !== 'ARCHIVED') {
                $unfinishedSessions = TrainingSession::query()
                    ->where('program_id', (int) $program->id)
                    ->where('status', 'SCHEDULED')
                    ->lockForUpdate()
                    ->exists();

                if ($unfinishedSessions) {
                    throw new ConflictException('A training program with scheduled sessions cannot be archived.', [
                        'reason' => 'TRAINING_PROGRAM_HAS_SESSIONS',
                        'program_id' => (int) $program->id,
                    ]);
                }
            }

            $capacity = array_key_exists('default_capacity', $input)
                ? (int) $input['default_capacity']
                : (int) $program->default_capacity;

            if ($capacity < 0) {
                throw new ValidationException(['default_capacity' => ['The default capacity must not be negative.']], 'The given data was invalid.', [
                    'reason' => 'TRAINING_CAPACITY_INVALID',
                ]);
            }

            // Capacity zero means "not configured" rather than a zero-seat
            // limit. A positive capacity, however, may never hide live seats.
            if ($capacity > 0) {
                $enrolled = TrainingEnrollment::query()
                    ->whereIn('session_id', TrainingSession::query()
                        ->where('program_id', (int) $program->id)
                        ->select('id'))
                    ->whereIn('status', ['ENROLLED', 'ATTENDED', 'COMPLETED'])
                    ->count();

                if ($enrolled > $capacity) {
                    throw new ConflictException('The default capacity cannot be lower than the current enrollment count.', [
                        'reason' => 'TRAINING_CAPACITY_BELOW_ENROLLED',
                        'program_id' => (int) $program->id,
                        'enrolled_count' => $enrolled,
                        'capacity' => $capacity,
                    ]);
                }
            }

            $before = $this->programData($program);
            $dirty = false;

            if (array_key_exists('title', $input) && (string) $input['title'] !== (string) $program->title) {
                $program->title = (string) $input['title'];
                $dirty = true;
            }

            if (array_key_exists('default_capacity', $input) && $capacity !== (int) $program->default_capacity) {
                $program->default_capacity = $capacity;
                $dirty = true;
            }

            if ($status !== (string) $program->status) {
                $program->status = $status;
                $dirty = true;
            }

            if (! $dirty) {
                return [...$this->programData($program), 'outcome' => 'REPLAY'];
            }

            $program->save();
            $after = $this->programData($program);

            $this->audit->record($actorUserId, 'talent.training_program.updated', 'training_program', (int) $program->id, before: $before, after: $after);

            return [...$after, 'outcome' => 'UPDATED'];
        });
    }

    /**
     * Update only the mutable course catalogue fields. Codes, plan links,
     * delivery mode and money are intentionally immutable after creation.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function updateCourse(int $actorUserId, int $courseId, array $input): array
    {
        return DB::transaction(function () use ($actorUserId, $courseId, $input): array {
            $course = TrainingCourse::query()->whereKey($courseId)->lockForUpdate()->first();

            if ($course === null) {
                throw NotFoundException::forResource();
            }

            $status = array_key_exists('status', $input) ? (string) $input['status'] : (string) $course->status;

            if (! in_array($status, ['ACTIVE', 'ARCHIVED'], true)) {
                throw new ValidationException(['status' => ['The training course status is invalid.']], 'The given data was invalid.', [
                    'reason' => 'TRAINING_COURSE_STATE_INVALID',
                ]);
            }

            if ((string) $course->status === 'ARCHIVED' && $status === 'ACTIVE') {
                throw new ConflictException('An archived training course cannot be reactivated.', [
                    'reason' => 'TRAINING_COURSE_STATE_INVALID',
                    'course_id' => (int) $course->id,
                    'status' => (string) $course->status,
                ]);
            }

            $before = $this->courseData($course);
            $dirty = false;

            foreach (['title', 'instructor', 'duration_minutes', 'materials'] as $field) {
                if (! array_key_exists($field, $input)) {
                    continue;
                }

                $value = match ($field) {
                    'duration_minutes' => (int) $input[$field],
                    'instructor', 'materials' => $this->nullableString($input[$field]),
                    default => (string) $input[$field],
                };

                if ((string) ($course->{$field} ?? '') !== (string) ($value ?? '')) {
                    $course->{$field} = $value;
                    $dirty = true;
                }
            }

            if ($status !== (string) $course->status) {
                $course->status = $status;
                $dirty = true;
            }

            if (! $dirty) {
                return [...$this->courseData($course), 'outcome' => 'REPLAY'];
            }

            $course->save();
            $after = $this->courseData($course);

            $this->audit->record($actorUserId, 'talent.training_course.updated', 'training_course', (int) $course->id, before: $before, after: $after);

            return [...$after, 'outcome' => 'UPDATED'];
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function createSession(int $actorUserId, array $input): array
    {
        return DB::transaction(function () use ($actorUserId, $input): array {
            $program = TrainingProgram::query()->whereKey((int) $input['program_id'])->first();

            if ($program === null) {
                throw new ValidationException(['program_id' => ['The training program does not exist.']]);
            }

            if ((string) $program->status !== 'ACTIVE') {
                throw new ConflictException('Only an active training program can receive a session.', [
                    'reason' => 'TRAINING_PROGRAM_NOT_ACTIVE',
                    'program_id' => (int) $program->id,
                ]);
            }

            $associations = $this->resolveSessionAssociations(
                programId: (int) $program->id,
                planId: isset($input['plan_id']) ? (int) $input['plan_id'] : null,
                courseId: isset($input['course_id']) ? (int) $input['course_id'] : null,
            );

            $startsAt = $this->instant($input['starts_at']);
            $endsAt = $this->instant($input['ends_at']);
            $this->assertSessionTimeline(
                (string) $input['starts_on'],
                (string) $input['ends_on'],
                $startsAt,
                $endsAt,
            );
            $this->assertInstructorAvailable($associations['course'], $startsAt, $endsAt);

            $session = TrainingSession::query()->create([
                'program_id' => (int) $program->id,
                'plan_id' => $associations['plan_id'],
                'course_id' => $associations['course_id'],
                'starts_on' => (string) $input['starts_on'],
                'ends_on' => (string) $input['ends_on'],
                'starts_at' => $startsAt->format('Y-m-d H:i:s'),
                'ends_at' => $endsAt->format('Y-m-d H:i:s'),
                'capacity' => (int) $input['capacity'],
                'location' => $this->nullableString($input['location'] ?? null),
                'status' => 'SCHEDULED',
                'recorded_at' => $this->clock->now(),
            ]);

            $this->audit->record($actorUserId, 'talent.training_session.created', 'training_session', (int) $session->id, after: [
                'program_id' => (int) $session->program_id,
                'plan_id' => $session->plan_id !== null ? (int) $session->plan_id : null,
                'course_id' => $session->course_id !== null ? (int) $session->course_id : null,
                'starts_at' => $startsAt->toIso8601ZuluString(),
                'ends_at' => $endsAt->toIso8601ZuluString(),
                'capacity' => (int) $session->capacity,
            ]);

            return $this->sessionData($session);
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function updateSession(int $actorUserId, int $sessionId, array $input): array
    {
        return DB::transaction(function () use ($actorUserId, $sessionId, $input): array {
            $session = TrainingSession::query()->whereKey($sessionId)->lockForUpdate()->first();

            if ($session === null) {
                throw NotFoundException::forResource();
            }

            $before = $this->sessionData($session);
            $programId = array_key_exists('program_id', $input) ? (int) $input['program_id'] : (int) $session->program_id;

            if (! TrainingProgram::query()->whereKey($programId)->exists()) {
                throw new ValidationException(['program_id' => ['The training program does not exist.']]);
            }

            $associations = $this->resolveSessionAssociations(
                programId: $programId,
                planId: array_key_exists('plan_id', $input)
                    ? ($input['plan_id'] === null ? null : (int) $input['plan_id'])
                    : ($session->plan_id !== null ? (int) $session->plan_id : null),
                courseId: array_key_exists('course_id', $input)
                    ? ($input['course_id'] === null ? null : (int) $input['course_id'])
                    : ($session->course_id !== null ? (int) $session->course_id : null),
                planProvided: array_key_exists('plan_id', $input),
                courseProvided: array_key_exists('course_id', $input),
            );

            $startsOn = array_key_exists('starts_on', $input) ? (string) $input['starts_on'] : (string) $session->starts_on;
            $endsOn = array_key_exists('ends_on', $input) ? (string) $input['ends_on'] : (string) $session->ends_on;
            $startsAt = array_key_exists('starts_at', $input) ? $this->instant($input['starts_at']) : $this->instant($session->starts_at);
            $endsAt = array_key_exists('ends_at', $input) ? $this->instant($input['ends_at']) : $this->instant($session->ends_at);
            $this->assertSessionTimeline($startsOn, $endsOn, $startsAt, $endsAt);

            $capacity = array_key_exists('capacity', $input) ? (int) $input['capacity'] : (int) $session->capacity;
            $status = array_key_exists('status', $input) ? (string) $input['status'] : (string) $session->status;

            if (! in_array($status, ['SCHEDULED', 'COMPLETED', 'CANCELLED'], true)) {
                throw new ValidationException(['status' => ['The training session status is invalid.']], 'The given data was invalid.', [
                    'reason' => 'TRAINING_SESSION_STATE_INVALID',
                ]);
            }

            if ((string) $session->status !== 'SCHEDULED' && $status !== (string) $session->status) {
                throw new ConflictException('Only a scheduled training session can change state.', [
                    'reason' => 'TRAINING_SESSION_STATE_INVALID',
                    'session_id' => (int) $session->id,
                    'status' => (string) $session->status,
                ]);
            }

            if ((string) $session->status !== 'SCHEDULED' && $status === (string) $session->status) {
                $changed = array_intersect_key($input, array_flip([
                    'program_id', 'plan_id', 'course_id', 'starts_on', 'ends_on', 'starts_at', 'ends_at', 'capacity', 'location',
                ]));

                if ($changed !== []) {
                    throw new ConflictException('A finished or cancelled training session is immutable.', [
                        'reason' => 'TRAINING_SESSION_NOT_EDITABLE',
                        'session_id' => (int) $session->id,
                        'status' => (string) $session->status,
                    ]);
                }

                return [...$this->sessionData($session), 'outcome' => 'REPLAY'];
            }

            $activeEnrollments = TrainingEnrollment::query()
                ->where('session_id', (int) $session->id)
                ->whereIn('status', ['ENROLLED', 'ATTENDED'])
                ->count();

            if ($status !== 'SCHEDULED' && $activeEnrollments > 0) {
                throw new ConflictException('A training session with active enrollments cannot be completed or cancelled.', [
                    'reason' => 'TRAINING_SESSION_HAS_ACTIVE_ENROLLMENTS',
                    'session_id' => (int) $session->id,
                    'active_enrollment_count' => $activeEnrollments,
                ]);
            }

            if ($status === 'SCHEDULED' && $capacity < $activeEnrollments) {
                throw new ConflictException('The capacity cannot be lower than the active enrollment count.', [
                    'reason' => 'TRAINING_CAPACITY_BELOW_ENROLLED',
                    'session_id' => (int) $session->id,
                    'active_enrollment_count' => $activeEnrollments,
                    'capacity' => $capacity,
                ]);
            }

            if ($status === 'SCHEDULED') {
                $this->assertInstructorAvailable($associations['course'], $startsAt, $endsAt, (int) $session->id);
            }

            $location = array_key_exists('location', $input)
                ? $this->nullableString($input['location'])
                : $session->location;

            $session->forceFill([
                'program_id' => $programId,
                'plan_id' => $associations['plan_id'],
                'course_id' => $associations['course_id'],
                'starts_on' => $startsOn,
                'ends_on' => $endsOn,
                'starts_at' => $startsAt->format('Y-m-d H:i:s'),
                'ends_at' => $endsAt->format('Y-m-d H:i:s'),
                'capacity' => $capacity,
                'location' => $location,
                'status' => $status,
            ])->save();

            $after = $this->sessionData($session);

            if ($before === $after) {
                return [...$after, 'outcome' => 'REPLAY'];
            }

            $this->audit->record($actorUserId, 'talent.training_session.updated', 'training_session', (int) $session->id, before: $before, after: $after);

            return [...$after, 'outcome' => 'UPDATED'];
        });
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
     * Cancel an active enrolment. Capacity is a live count over active
     * statuses, so releasing a seat needs no mutable counter. Repeating the
     * command for an already cancelled row is a stable 200 replay and must not
     * add another audit fact or touch any other enrollment.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function cancelEnrollment(int $actorUserId, int $enrollmentId, array $input = []): array
    {
        return DB::transaction(function () use ($actorUserId, $enrollmentId, $input): array {
            $enrollment = TrainingEnrollment::query()
                ->whereKey($enrollmentId)
                ->lockForUpdate()
                ->first();

            if ($enrollment === null) {
                throw NotFoundException::forResource();
            }

            // Lock the session as well so concurrent enrol/cancel observes a
            // serialized capacity fact. No seat counter is stored.
            TrainingSession::query()
                ->whereKey((int) $enrollment->session_id)
                ->lockForUpdate()
                ->first();

            if ((string) $enrollment->status === 'CANCELLED') {
                return [...$this->enrollmentData($enrollment), 'outcome' => 'REPLAY'];
            }

            if (! in_array((string) $enrollment->status, ['ENROLLED', 'ATTENDED'], true)) {
                throw new ConflictException('Only an active training enrollment can be cancelled.', [
                    'reason' => 'TRAINING_ENROLLMENT_NOT_CANCELLABLE',
                    'enrollment_id' => (int) $enrollment->id,
                    'status' => (string) $enrollment->status,
                ]);
            }

            $beforeStatus = (string) $enrollment->status;
            $enrollment->forceFill(['status' => 'CANCELLED'])->save();

            $reason = $this->nullableString($input['reason'] ?? null);
            $this->audit->record(
                actorUserId: $actorUserId,
                action: 'talent.training_enrollment.cancelled',
                aggregateType: 'training_enrollment',
                aggregateId: (int) $enrollment->id,
                before: ['status' => $beforeStatus],
                after: ['status' => 'CANCELLED'],
                metadata: $reason === null ? [] : ['reason' => $reason],
            );

            return [...$this->enrollmentData($enrollment), 'outcome' => 'CANCELLED'];
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
            ->map(fn (TrainingSession $session): array => $this->sessionData($session))
            ->values()
            ->all();
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
     * @return array{plan_id: int|null, course_id: int|null, course: TrainingCourse|null}
     */
    private function resolveSessionAssociations(
        int $programId,
        ?int $planId,
        mixed $courseId,
        bool $planProvided = true,
        bool $courseProvided = true,
    ): array {
        if (! TrainingProgram::query()->whereKey($programId)->exists()) {
            throw new ValidationException(['program_id' => ['The training program does not exist.']]);
        }

        $course = null;

        if ($courseId !== null) {
            $course = TrainingCourse::query()->whereKey((int) $courseId)->first();

            if ($course === null) {
                throw new ValidationException(['course_id' => ['The training course does not exist.']]);
            }

            if ($planId !== null && (int) $course->plan_id !== $planId) {
                throw new ValidationException(['course_id' => ['The training course does not belong to the selected plan.']]);
            }

            if ($courseProvided && $planProvided && $planId === null && $course->plan_id !== null) {
                throw new ValidationException(['plan_id' => ['A session linked to a planned course must keep the course plan.']]);
            }

            $planId = $course->plan_id !== null ? (int) $course->plan_id : null;
        } elseif ($planId !== null && ! TrainingPlan::query()->whereKey($planId)->exists()) {
            throw new ValidationException(['plan_id' => ['The training plan does not exist.']]);
        }

        return [
            'plan_id' => $planId,
            'course_id' => $course !== null ? (int) $course->id : null,
            'course' => $course,
        ];
    }

    private function assertSessionTimeline(
        string $startsOn,
        string $endsOn,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
    ): void {
        if ($startsOn > $endsOn) {
            throw new ValidationException(['ends_on' => ['Session end date must be on or after start date.']], 'The given data was invalid.', [
                'reason' => 'TRAINING_SESSION_DATE_INVALID',
            ]);
        }

        if ($startsAt->greaterThanOrEqualTo($endsAt)) {
            throw new ValidationException(['ends_at' => ['Session end must be after start.']], 'The given data was invalid.', [
                'reason' => 'TRAINING_SESSION_TIME_INVALID',
            ]);
        }
    }

    private function assertInstructorAvailable(
        ?TrainingCourse $course,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        ?int $ignoreSessionId = null,
    ): void {
        $instructor = $this->nullableString($course?->instructor);

        if ($instructor === null || $course === null) {
            return;
        }

        $conflictingSessionIds = TrainingSession::query()
            ->where('status', 'SCHEDULED')
            ->when($ignoreSessionId !== null, fn ($query) => $query->whereKeyNot($ignoreSessionId))
            ->where('starts_at', '<', $endsAt->format('Y-m-d H:i:s'))
            ->where('ends_at', '>', $startsAt->format('Y-m-d H:i:s'))
            ->whereIn('course_id', TrainingCourse::query()->where('instructor', $instructor)->select('id'))
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        if ($conflictingSessionIds !== []) {
            throw new ConflictException('The course instructor already has an overlapping training session.', [
                'reason' => 'TRAINING_SESSION_CONFLICT',
                'instructor' => $instructor,
                'conflicting_session_ids' => $conflictingSessionIds,
            ]);
        }
    }

    private function instant(mixed $value): CarbonImmutable
    {
        return CarbonImmutable::parse((string) $value)->utc();
    }

    /**
     * @return array<string, mixed>
     */
    private function programData(TrainingProgram $program): array
    {
        return [
            'program_id' => (int) $program->id,
            'code' => (string) $program->code,
            'title' => (string) $program->title,
            'status' => (string) $program->status,
            'default_capacity' => (int) $program->default_capacity,
            'recorded_at' => $program->recorded_at !== null
                ? CarbonImmutable::parse((string) $program->recorded_at)->toIso8601ZuluString()
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function courseData(TrainingCourse $course): array
    {
        return [
            'course_id' => (int) $course->id,
            'plan_id' => $course->plan_id !== null ? (int) $course->plan_id : null,
            'code' => (string) $course->code,
            'title' => (string) $course->title,
            'instructor' => $course->instructor,
            'delivery_mode' => (string) $course->delivery_mode,
            'duration_minutes' => (int) $course->duration_minutes,
            'cost_cents' => (int) $course->cost_cents,
            'currency' => (string) $course->currency,
            'materials' => $course->materials,
            'status' => (string) $course->status,
            'recorded_at' => $course->recorded_at !== null
                ? CarbonImmutable::parse((string) $course->recorded_at)->toIso8601ZuluString()
                : null,
        ];
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
            'starts_at' => $this->iso($session->starts_at),
            'ends_at' => $this->iso($session->ends_at),
            'capacity' => (int) $session->capacity,
            'location' => $session->location,
            'status' => (string) $session->status,
            'recorded_at' => $this->iso($session->recorded_at),
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

    private function iso(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return CarbonImmutable::parse((string) $value)->toIso8601ZuluString();
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }
}
