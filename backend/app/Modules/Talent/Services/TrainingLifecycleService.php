<?php

declare(strict_types=1);

namespace App\Modules\Talent\Services;

use App\Modules\Talent\Models\TrainingCheckin;
use App\Modules\Talent\Models\TrainingCourse;
use App\Modules\Talent\Models\TrainingEnrollment;
use App\Modules\Talent\Models\TrainingExam;
use App\Modules\Talent\Models\TrainingFeedback;
use App\Modules\Talent\Models\TrainingPlan;
use App\Modules\Talent\Models\TrainingSession;
use App\Shared\Authorization\Contracts\SensitiveAccessAuditor;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Observability\AuditRecorder;
use App\Shared\Projections\ProjectionResult;
use App\Shared\Time\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * K10 training-lifecycle write model and live read projections.
 *
 * Invariants owned here:
 *  - study minutes are derived only from check-in/check-out evidence;
 *  - a check-in is unique per (session, employment) and a repeated command is
 *    a replay rather than a second row;
 *  - a published exam version can never be edited in place;
 *  - feedback rows are append-only and never updated;
 *  - records and reports are live aggregates over enrolments, check-ins,
 *    exams and feedback; no denormalized study-hour or rate columns exist.
 */
final class TrainingLifecycleService
{
    /** @var list<string> */
    public const RECORD_EXPORT_FIELDS = [
        'employment_id',
        'session_id',
        'enrollment_id',
        'course_code',
        'course_title',
        'status',
        'completion_result',
        'score',
        'study_minutes',
        'checkin_count',
        'feedback_count',
        'latest_exam_version',
    ];

    public function __construct(
        private readonly Clock $clock,
        private readonly AuditRecorder $audit,
        private readonly SensitiveAccessAuditor $sensitiveAuditor,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function createPlan(int $actorUserId, array $input): array
    {
        return DB::transaction(function () use ($actorUserId, $input): array {
            if (TrainingPlan::query()->where('code', (string) $input['code'])->exists()) {
                throw new ConflictException('A training plan with this code already exists.', ['reason' => 'TRAINING_PLAN_DUPLICATE']);
            }

            $plan = TrainingPlan::query()->create([
                'code' => (string) $input['code'],
                'title' => (string) $input['title'],
                'year' => (int) $input['year'],
                'budget_cents' => (int) ($input['budget_cents'] ?? 0),
                'currency' => (string) ($input['currency'] ?? 'CNY'),
                'status' => (string) ($input['status'] ?? 'ACTIVE'),
                'recorded_at' => $this->clock->now(),
            ]);

            $this->audit->record($actorUserId, 'talent.training_plan.created', 'training_plan', (int) $plan->id, after: [
                'code' => (string) $plan->code,
                'year' => (int) $plan->year,
                'budget_cents' => (int) $plan->budget_cents,
            ]);

            return $this->planData($plan);
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function createCourse(int $actorUserId, array $input): array
    {
        return DB::transaction(function () use ($actorUserId, $input): array {
            $planId = isset($input['plan_id']) ? (int) $input['plan_id'] : null;

            if ($planId !== null && ! TrainingPlan::query()->whereKey($planId)->exists()) {
                throw new ValidationException(['plan_id' => ['The training plan does not exist.']]);
            }

            if (TrainingCourse::query()->where('code', (string) $input['code'])->exists()) {
                throw new ConflictException('A training course with this code already exists.', ['reason' => 'TRAINING_COURSE_DUPLICATE']);
            }

            $course = TrainingCourse::query()->create([
                'plan_id' => $planId,
                'code' => (string) $input['code'],
                'title' => (string) $input['title'],
                'instructor' => $this->nullableString($input['instructor'] ?? null),
                'delivery_mode' => (string) ($input['delivery_mode'] ?? 'ONSITE'),
                'duration_minutes' => (int) ($input['duration_minutes'] ?? 0),
                'cost_cents' => (int) ($input['cost_cents'] ?? 0),
                'currency' => (string) ($input['currency'] ?? 'CNY'),
                'materials' => $this->nullableString($input['materials'] ?? null),
                'status' => (string) ($input['status'] ?? 'ACTIVE'),
                'recorded_at' => $this->clock->now(),
            ]);

            $this->audit->record($actorUserId, 'talent.training_course.created', 'training_course', (int) $course->id, after: [
                'code' => (string) $course->code,
                'plan_id' => $planId,
            ]);

            return $this->courseData($course);
        });
    }

    /**
     * Check-in is idempotent per (session, employment). The first call creates
     * the clock evidence; any later call returns the same row without moving
     * the recorded times. Study minutes are calculated on read.
     *
     * @param  array<string, mixed>  $input
     * @return array{created: bool, data: array<string, mixed>}
     */
    public function checkin(int $actorUserId, int $sessionId, array $input): array
    {
        return DB::transaction(function () use ($actorUserId, $sessionId, $input): array {
            $session = TrainingSession::query()->whereKey($sessionId)->lockForUpdate()->first();

            if ($session === null) {
                throw NotFoundException::forResource();
            }

            $employmentId = (int) $input['employment_id'];
            $enrollment = TrainingEnrollment::query()
                ->where('session_id', $sessionId)
                ->where('employment_id', $employmentId)
                ->first();

            if ($enrollment === null) {
                throw new ConflictException('The employment is not enrolled in this training session.', [
                    'reason' => 'TRAINING_ENROLLMENT_REQUIRED',
                    'session_id' => $sessionId,
                ]);
            }

            $existing = TrainingCheckin::query()
                ->where('session_id', $sessionId)
                ->where('employment_id', $employmentId)
                ->first();

            if ($existing !== null) {
                return ['created' => false, 'data' => $this->checkinData($existing)];
            }

            $checkedInAt = $this->parseInstant($input['checked_in_at'] ?? null) ?? $this->clock->now();
            $checkedOutAt = $this->parseInstant($input['checked_out_at'] ?? null);

            if ($checkedOutAt !== null && $checkedOutAt->lessThan($checkedInAt)) {
                throw new ValidationException(['checked_out_at' => ['The check-out time must not precede check-in.']]);
            }

            try {
                $checkin = TrainingCheckin::query()->create([
                    'session_id' => $sessionId,
                    'employment_id' => $employmentId,
                    'checked_in_at' => $checkedInAt,
                    'checked_out_at' => $checkedOutAt,
                    'recorded_by' => $actorUserId,
                    'recorded_at' => $this->clock->now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                $checkin = TrainingCheckin::query()
                    ->where('session_id', $sessionId)
                    ->where('employment_id', $employmentId)
                    ->firstOrFail();
            }

            $this->audit->record($actorUserId, 'talent.training_checkin.recorded', 'training_checkin', (int) $checkin->id, after: [
                'session_id' => $sessionId,
                'employment_id' => $employmentId,
            ]);

            return ['created' => true, 'data' => $this->checkinData($checkin)];
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function createFeedback(int $actorUserId, array $input): array
    {
        return DB::transaction(function () use ($actorUserId, $input): array {
            $enrollment = TrainingEnrollment::query()
                ->whereKey((int) $input['enrollment_id'])
                ->first();

            if ($enrollment === null) {
                throw new ValidationException(['enrollment_id' => ['The training enrollment does not exist.']]);
            }

            $feedback = TrainingFeedback::query()->create([
                'enrollment_id' => (int) $enrollment->id,
                'session_id' => (int) $enrollment->session_id,
                'employment_id' => (int) $enrollment->employment_id,
                'rating' => isset($input['rating']) ? (int) $input['rating'] : null,
                'comments' => (string) $input['comments'],
                'questionnaire_json' => isset($input['questionnaire']) && is_array($input['questionnaire']) ? $input['questionnaire'] : null,
                'status' => 'RECORDED',
                'recorded_at' => $this->clock->now(),
            ]);

            $this->audit->record($actorUserId, 'talent.training_feedback.recorded', 'training_feedback', (int) $feedback->id, after: [
                'enrollment_id' => (int) $feedback->enrollment_id,
                'rating' => $feedback->rating,
            ]);

            return $this->feedbackData($feedback);
        });
    }

    public function plans(string $asOf): ProjectionResult
    {
        $items = TrainingPlan::query()
            ->orderByDesc('year')
            ->orderBy('code')
            ->get()
            ->map(fn (TrainingPlan $plan): array => $this->planData($plan))
            ->values()
            ->all();

        return $this->projection($items, $asOf);
    }

    public function courses(?int $planId, string $asOf): ProjectionResult
    {
        $items = TrainingCourse::query()
            ->when($planId !== null, fn ($query) => $query->where('plan_id', $planId))
            ->orderBy('code')
            ->get()
            ->map(fn (TrainingCourse $course): array => $this->courseData($course))
            ->values()
            ->all();

        return $this->projection($items, $asOf);
    }

    public function checkins(?int $sessionId, string $asOf): ProjectionResult
    {
        $items = TrainingCheckin::query()
            ->when($sessionId !== null, fn ($query) => $query->where('session_id', $sessionId))
            ->orderByDesc('id')
            ->get()
            ->map(fn (TrainingCheckin $checkin): array => $this->checkinData($checkin))
            ->values()
            ->all();

        return $this->projection($items, $asOf);
    }

    public function feedback(?int $enrollmentId, string $asOf): ProjectionResult
    {
        $items = TrainingFeedback::query()
            ->when($enrollmentId !== null, fn ($query) => $query->where('enrollment_id', $enrollmentId))
            ->orderByDesc('id')
            ->get()
            ->map(fn (TrainingFeedback $feedback): array => $this->feedbackData($feedback))
            ->values()
            ->all();

        return $this->projection($items, $asOf);
    }

    /**
     * Personal training records: one row per enrolment with completion,
     * derived check-in time and latest exam version evidence. No certificate
     * value is fabricated when no certificate row exists.
     */
    public function records(?int $employmentId, string $asOf): ProjectionResult
    {
        $items = TrainingEnrollment::query()
            ->when($employmentId !== null, fn ($query) => $query->where('employment_id', $employmentId))
            ->orderByDesc('id')
            ->get()
            ->map(function (TrainingEnrollment $enrollment): array {
                $session = TrainingSession::query()->whereKey((int) $enrollment->session_id)->first();
                $course = $session !== null && $session->course_id !== null
                    ? TrainingCourse::query()->whereKey((int) $session->course_id)->first()
                    : null;
                $checkins = TrainingCheckin::query()
                    ->where('session_id', (int) $enrollment->session_id)
                    ->where('employment_id', (int) $enrollment->employment_id)
                    ->get();

                return [
                    ...$this->enrollmentRecordData($enrollment, $session, $course, $checkins->all()),
                    'latest_exam_version' => $session === null || $session->course_id === null
                        ? null
                        : TrainingExam::query()
                            ->where('course_id', (int) $session->course_id)
                            ->where('status', 'PUBLISHED')
                            ->orderByDesc('version')
                            ->value('version'),
                ];
            })
            ->values()
            ->all();

        return $this->projection($items, $asOf);
    }

    /**
     * @param  list<string>  $requestedFields
     * @return array{fields: list<string>, items: list<array<string, mixed>>}
     */
    public function exportRecords(int $actorUserId, ?int $employmentId, string $asOf, array $requestedFields): array
    {
        $requestedFields = array_values(array_unique(array_map('strval', $requestedFields)));

        if ($requestedFields === []) {
            throw new ValidationException(['fields' => ['At least one export field is required.']], 'The given data was invalid.', ['reason' => 'EXPORT_FIELDS_REQUIRED']);
        }

        foreach ($requestedFields as $field) {
            if (! in_array($field, self::RECORD_EXPORT_FIELDS, true)) {
                throw new ValidationException(['fields' => ["Field {$field} is not available for training record export."]], 'The given data was invalid.', ['reason' => 'EXPORT_FIELD_NOT_ALLOWED']);
            }
        }

        $records = $this->records($employmentId, $asOf)->items;
        $items = [];

        foreach ($records as $record) {
            $row = [];

            foreach ($requestedFields as $field) {
                $row[$field] = $record[$field] ?? null;
            }

            $items[] = $row;
        }

        $this->sensitiveAuditor->record(
            userId: $actorUserId,
            capabilityCode: 'training.record.read',
            resourceType: 'training_record_export',
            resourceId: $employmentId,
            fields: $requestedFields,
            context: ['as_of' => $asOf, 'access' => 'EXPORT', 'row_count' => count($items)],
        );

        return ['fields' => $requestedFields, 'items' => $items];
    }

    /**
     * Plan-level training report. Every figure is derived from live rows; a
     * plan with no data stays at zero rather than producing a forecast.
     */
    public function reports(?int $planId, string $asOf): ProjectionResult
    {
        $plans = TrainingPlan::query()
            ->when($planId !== null, fn ($query) => $query->whereKey($planId))
            ->orderBy('id')
            ->get();

        $items = $plans->map(function (TrainingPlan $plan): array {
            $courseIds = TrainingCourse::query()
                ->where('plan_id', (int) $plan->id)
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();

            // A session may be linked to the plan directly or through one of
            // the plan's courses. Both belong to the same live report.
            $sessionIds = TrainingSession::query()
                ->where(function ($query) use ($plan, $courseIds): void {
                    $query->where('plan_id', (int) $plan->id);

                    if ($courseIds !== []) {
                        $query->orWhereIn('course_id', $courseIds);
                    }
                })
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();

            $enrollments = TrainingEnrollment::query()
                ->whereIn('session_id', $sessionIds === [] ? [-1] : $sessionIds)
                ->get();

            $enrollmentIds = $enrollments->map(static fn (TrainingEnrollment $enrollment): int => (int) $enrollment->id)->all();
            $completed = $enrollments->filter(static fn (TrainingEnrollment $enrollment): bool => (string) $enrollment->status === 'COMPLETED');
            $passed = $completed->filter(static fn (TrainingEnrollment $enrollment): bool => (string) $enrollment->completion_result === 'PASS');
            $studyMinutes = 0;

            if ($sessionIds !== []) {
                $checkins = TrainingCheckin::query()
                    ->whereIn('session_id', $sessionIds)
                    ->get();

                foreach ($checkins as $checkin) {
                    $studyMinutes += $this->studyMinutes($checkin);
                }
            }

            $feedback = $enrollmentIds === []
                ? collect()
                : TrainingFeedback::query()
                    ->whereIn('enrollment_id', $enrollmentIds)
                    ->get();
            $rated = $feedback->filter(static fn (TrainingFeedback $row): bool => $row->rating !== null);
            $ratingBps = $rated->isEmpty()
                ? 0
                : (int) round(((float) $rated->avg('rating') / 5.0) * 10000);

            $matchedCourses = TrainingCourse::query()
                ->whereIn('id', $courseIds === [] ? [-1] : $courseIds)
                ->get();
            $trainingCostCents = (int) $matchedCourses->sum(static fn (TrainingCourse $course): int => (int) $course->cost_cents);

            return [
                'plan_id' => (int) $plan->id,
                'code' => (string) $plan->code,
                'title' => (string) $plan->title,
                'year' => (int) $plan->year,
                'budget_cents' => (int) $plan->budget_cents,
                'training_cost_cents' => $trainingCostCents,
                'currency' => (string) $plan->currency,
                'course_count' => count($courseIds),
                'session_count' => count($sessionIds),
                'enrolled_count' => $enrollments->count(),
                'completed_count' => $completed->count(),
                'completion_rate_bps' => $enrollments->isEmpty()
                    ? 0
                    : (int) round(($completed->count() / $enrollments->count()) * 10000),
                'exam_pass_count' => $passed->count(),
                'exam_pass_rate_bps' => $completed->isEmpty()
                    ? 0
                    : (int) round(($passed->count() / $completed->count()) * 10000),
                'study_minutes_total' => $studyMinutes,
                'feedback_count' => $feedback->count(),
                'average_feedback_rating_bps' => $ratingBps,
            ];
        })->values()->all();

        return $this->projection($items, $asOf);
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

    /**
     * @return array<string, mixed>
     */
    public function planData(TrainingPlan $plan): array
    {
        return [
            'plan_id' => (int) $plan->id,
            'code' => (string) $plan->code,
            'title' => (string) $plan->title,
            'year' => (int) $plan->year,
            'budget_cents' => (int) $plan->budget_cents,
            'currency' => (string) $plan->currency,
            'status' => (string) $plan->status,
            'recorded_at' => $this->iso($plan->recorded_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function courseData(TrainingCourse $course): array
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
            'recorded_at' => $this->iso($course->recorded_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function checkinData(TrainingCheckin $checkin): array
    {
        return [
            'checkin_id' => (int) $checkin->id,
            'session_id' => (int) $checkin->session_id,
            'employment_id' => (int) $checkin->employment_id,
            'checked_in_at' => $this->iso($checkin->checked_in_at),
            'checked_out_at' => $this->iso($checkin->checked_out_at),
            'study_minutes' => $this->studyMinutes($checkin),
            'recorded_at' => $this->iso($checkin->recorded_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function feedbackData(TrainingFeedback $feedback): array
    {
        return [
            'training_feedback_id' => (int) $feedback->id,
            'enrollment_id' => (int) $feedback->enrollment_id,
            'session_id' => (int) $feedback->session_id,
            'employment_id' => (int) $feedback->employment_id,
            'rating' => $feedback->rating !== null ? (int) $feedback->rating : null,
            'comments' => (string) $feedback->comments,
            'questionnaire' => $feedback->questionnaire_json,
            'status' => (string) $feedback->status,
            'recorded_at' => $this->iso($feedback->recorded_at),
        ];
    }

    /**
     * @param  list<TrainingCheckin>  $checkins
     * @return array<string, mixed>
     */
    private function enrollmentRecordData(TrainingEnrollment $enrollment, ?TrainingSession $session, ?TrainingCourse $course, array $checkins): array
    {
        $studyMinutes = 0;

        foreach ($checkins as $checkin) {
            $studyMinutes += $this->studyMinutes($checkin);
        }

        $feedbackCount = TrainingFeedback::query()
            ->where('enrollment_id', (int) $enrollment->id)
            ->count();

        return [
            'enrollment_id' => (int) $enrollment->id,
            'employment_id' => (int) $enrollment->employment_id,
            'session_id' => (int) $enrollment->session_id,
            'course_code' => $course?->code,
            'course_title' => $course?->title,
            'session_starts_on' => $session !== null ? (string) $session->starts_on : null,
            'status' => (string) $enrollment->status,
            'attended' => (bool) $enrollment->attended,
            'completion_result' => $enrollment->completion_result,
            'score' => $enrollment->score !== null ? (int) $enrollment->score : null,
            'completed_at' => $this->iso($enrollment->completed_at),
            'checkin_count' => count($checkins),
            'study_minutes' => $studyMinutes,
            'feedback_count' => $feedbackCount,
        ];
    }

    private function studyMinutes(TrainingCheckin $checkin): int
    {
        if ($checkin->checked_out_at === null) {
            return 0;
        }

        $checkedInAt = CarbonImmutable::parse((string) $checkin->checked_in_at);
        $checkedOutAt = CarbonImmutable::parse((string) $checkin->checked_out_at);

        return max(0, (int) $checkedInAt->diffInMinutes($checkedOutAt, false));
    }

    private function parseInstant(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return CarbonImmutable::parse($value)->utc();
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
