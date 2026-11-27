<?php

declare(strict_types=1);

namespace App\Modules\Talent\Http\Controllers;

use App\Modules\Talent\Models\TrainingCourse;
use App\Modules\Talent\Models\TrainingEnrollment;
use App\Modules\Talent\Models\TrainingProgram;
use App\Modules\Talent\Models\TrainingSession;
use App\Modules\Talent\Services\TrainingLifecycleService;
use App\Modules\Talent\Services\TrainingService;
use App\Shared\ApiResponse;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\PrincipalResolver;
use App\Shared\Commands\CommandDefinition;
use App\Shared\Commands\CommandRunner;
use App\Shared\Projections\ProjectionResponder;
use App\Shared\Projections\ProjectionResult;
use App\Shared\Time\CompanyBusinessDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Thin HTTP adapter for the K10 training lifecycle.
 *
 * Writes go through CommandRunner (Idempotency-Key, company context and one
 * transaction); reads go through ProjectionResponder (ADR-0029 envelope).
 * Capability and anchor enforcement stays in route middleware.
 */
final class TrainingController
{
    public function __construct(
        private readonly PrincipalResolver $principals,
        private readonly CommandRunner $commands,
        private readonly ProjectionResponder $projections,
        private readonly CompanyBusinessDate $businessDates,
        private readonly TrainingService $training,
        private readonly TrainingLifecycleService $lifecycle,
    ) {}

    public function plans(Request $request): JsonResponse
    {

        return $this->projections->ok(
            $this->lifecycle->plans($this->asOf($request)),
        );
    }

    public function storePlan(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64'],
            'title' => ['required', 'string', 'max:160'],
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'budget_cents' => ['nullable', 'integer', 'min:0'],
            'currency' => ['nullable', 'string', 'regex:/^[A-Z]{3}$/'],
            'status' => ['nullable', 'string', 'in:ACTIVE,ARCHIVED'],
        ]);

        return $this->runCommand($request, 'talent.training.plan.create', 'training.plan.manage', $data, function (int $actorUserId) use ($data): array {
            return ['status' => 201, 'body' => $this->lifecycle->createPlan($actorUserId, $data)];
        });
    }

    public function courses(Request $request): JsonResponse
    {
        $planId = $request->query('plan_id') !== null ? (int) $request->query('plan_id') : null;

        return $this->projections->ok(
            $this->lifecycle->courses($planId, $this->asOf($request)),
        );
    }

    public function storeCourse(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plan_id' => ['nullable', 'integer', 'min:1'],
            'code' => ['required', 'string', 'max:64'],
            'title' => ['required', 'string', 'max:160'],
            'instructor' => ['nullable', 'string', 'max:120'],
            'delivery_mode' => ['nullable', 'string', 'in:ONSITE,ONLINE,HYBRID'],
            'duration_minutes' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'cost_cents' => ['nullable', 'integer', 'min:0'],
            'currency' => ['nullable', 'string', 'regex:/^[A-Z]{3}$/'],
            'materials' => ['nullable', 'string', 'max:20000'],
            'status' => ['nullable', 'string', 'in:ACTIVE,ARCHIVED'],
        ]);

        return $this->runCommand($request, 'talent.training.course.create', 'training.course.manage', $data, function (int $actorUserId) use ($data): array {
            return ['status' => 201, 'body' => $this->lifecycle->createCourse($actorUserId, $data)];
        });
    }

    public function programs(Request $request): JsonResponse
    {
        $asOf = $this->asOf($request);

        // Workspace rows are derived from the live session/enrollment facts:
        // capacity, enrolled count and the dominant delivery mode are never
        // stored on the program row and must not be guessed by the client.
        $programs = TrainingProgram::query()->orderBy('id')->get();
        $sessions = TrainingSession::query()
            ->whereIn('program_id', $programs->pluck('id')->all())
            ->get();
        $enrollments = TrainingEnrollment::query()
            ->whereIn('session_id', $sessions->pluck('id')->all())
            ->get();
        $courses = TrainingCourse::query()
            ->whereIn('id', $sessions->pluck('course_id')->filter()->all())
            ->get()
            ->keyBy('id');

        $items = $programs->map(function (TrainingProgram $program) use ($sessions, $enrollments, $courses): array {
            $programSessions = $sessions->where('program_id', (int) $program->id);
            $programSessionIds = $programSessions->pluck('id')->all();
            $programEnrollments = $enrollments->whereIn('session_id', $programSessionIds);

            $capacity = $programSessions->sum(static fn (TrainingSession $session): int => (int) $session->capacity);
            $enrolled = $programEnrollments
                ->whereIn('status', ['ENROLLED', 'ATTENDED', 'COMPLETED'])
                ->count();
            $completed = $programEnrollments->where('status', 'COMPLETED')->count();

            $deliveryMode = null;
            $trainingCostCents = 0;
            $countedCourseIds = [];
            foreach ($programSessions as $session) {
                $course = $session->course_id !== null ? $courses->get((int) $session->course_id) : null;
                if ($course === null) {
                    continue;
                }
                $deliveryMode ??= (string) $course->delivery_mode;
                $courseId = (int) $course->id;
                if (! isset($countedCourseIds[$courseId])) {
                    $countedCourseIds[$courseId] = true;
                    $trainingCostCents += (int) $course->cost_cents;
                }
            }

            return [
                'program_id' => (int) $program->id,
                'code' => (string) $program->code,
                'title' => (string) $program->title,
                'category' => null,
                'delivery_mode' => $deliveryMode,
                'capacity' => $capacity > 0 ? $capacity : (int) $program->default_capacity,
                'enrolled_count' => $enrolled,
                'completed_count' => $completed,
                'remaining_seats' => max(0, ($capacity > 0 ? $capacity : (int) $program->default_capacity) - $enrolled),
                'session_count' => $programSessions->count(),
                'training_cost_cents' => $trainingCostCents,
                'status' => (string) $program->status,
            ];
        })->values()->all();

        return $this->projections->ok($this->projection($items, $asOf));
    }

    public function storeProgram(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:64'],
            'title' => ['required', 'string', 'max:160'],
            'default_capacity' => ['nullable', 'integer', 'min:0', 'max:10000'],
        ]);

        return $this->runCommand($request, 'talent.training.program.create', 'training.program.manage', $data, function (int $actorUserId) use ($data): array {
            return ['status' => 201, 'body' => $this->training->createProgram($actorUserId, $data)];
        });
    }

    public function sessions(Request $request): JsonResponse
    {
        $items = $this->training->sessions(
            $request->query('program_id') !== null ? (int) $request->query('program_id') : null,
            is_string($request->query('status')) ? $request->query('status') : null,
        );

        return $this->projections->ok($this->projection($items, $this->asOf($request)));
    }

    public function storeSession(Request $request): JsonResponse
    {
        $data = $request->validate([
            'program_id' => ['required', 'integer', 'min:1'],
            'plan_id' => ['nullable', 'integer', 'min:1'],
            'course_id' => ['nullable', 'integer', 'min:1'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'capacity' => ['required', 'integer', 'min:1', 'max:10000'],
            'location' => ['nullable', 'string', 'max:160'],
        ]);

        return $this->runCommand($request, 'talent.training.session.create', 'training.session.manage', $data, function (int $actorUserId) use ($data): array {
            return ['status' => 201, 'body' => $this->training->createSession($actorUserId, $data)];
        });
    }

    public function enrollments(Request $request): JsonResponse
    {
        /** @var \Illuminate\Database\Eloquent\Collection<int, TrainingEnrollment> $rows */
        $rows = TrainingEnrollment::query()
            ->when($request->query('session_id') !== null, fn ($query) => $query->where('session_id', (int) $request->query('session_id')))
            ->when($request->query('employment_id') !== null, fn ($query) => $query->where('employment_id', (int) $request->query('employment_id')))
            ->orderByDesc('id')
            ->get();

        $items = $rows->map(static function (TrainingEnrollment $enrollment): array {
            $completedAt = $enrollment->completed_at;

            return [
                'enrollment_id' => (int) $enrollment->id,
                'session_id' => (int) $enrollment->session_id,
                'employment_id' => (int) $enrollment->employment_id,
                'status' => (string) $enrollment->status,
                'attended' => (bool) $enrollment->attended,
                'completion_result' => $enrollment->completion_result,
                'score' => $enrollment->score !== null ? (int) $enrollment->score : null,
                'completed_at' => $completedAt !== null && $completedAt !== ''
                    ? \Carbon\CarbonImmutable::parse((string) $completedAt)->toIso8601ZuluString()
                    : null,
            ];
        })->values()->all();

        return $this->projections->ok($this->projection($items, $this->asOf($request)));
    }

    public function storeEnrollment(Request $request): JsonResponse
    {
        $data = $request->validate([
            'session_id' => ['required', 'integer', 'min:1'],
            'employment_id' => ['required', 'integer', 'min:1'],
        ]);

        return $this->runCommand($request, 'talent.training.enrollment.create', 'training.enrollment.manage', $data, function (int $actorUserId) use ($data): array {
            $result = $this->training->enroll($actorUserId, (int) $data['session_id'], (int) $data['employment_id']);
            $status = ($result['outcome'] ?? 'CREATED') === 'REPLAY' ? 200 : 201;
            unset($result['outcome']);

            return ['status' => $status, 'body' => $result];
        });
    }

    public function completeEnrollment(Request $request, int $enrollmentId): JsonResponse
    {
        $data = $request->validate([
            'attended' => ['sometimes', 'boolean'],
            'completion_result' => ['required', 'string', 'in:PASS,FAIL,ATTENDED'],
            'score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'completed_on' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return $this->runCommand($request, 'talent.training.enrollment.complete', 'training.completion.manage', ['enrollment_id' => $enrollmentId, ...$data], function (int $actorUserId) use ($enrollmentId, $data): array {
            return ['status' => 200, 'body' => $this->training->complete($actorUserId, $enrollmentId, $data)];
        });
    }

    public function checkins(Request $request, ?int $sessionId = null): JsonResponse
    {
        $filter = $sessionId ?? ($request->query('session_id') !== null ? (int) $request->query('session_id') : null);

        return $this->projections->ok(
            $this->lifecycle->checkins($filter, $this->asOf($request)),
        );
    }

    public function storeCheckin(Request $request, int $sessionId): JsonResponse
    {
        $data = $request->validate([
            'employment_id' => ['required', 'integer', 'min:1'],
            'checked_in_at' => ['nullable', 'date'],
            'checked_out_at' => ['nullable', 'date'],
        ]);
        $payload = ['session_id' => $sessionId, ...$data];

        return $this->runCommand($request, 'talent.training.checkin.record', 'training.checkin.manage', $payload, function (int $actorUserId) use ($sessionId, $data): array {
            $result = $this->lifecycle->checkin($actorUserId, $sessionId, $data);

            return ['status' => $result['created'] ? 201 : 200, 'body' => $result['data']];
        });
    }

    public function exams(Request $request): JsonResponse
    {

        return $this->projections->ok(
            $this->lifecycle->exams($this->asOf($request)),
        );
    }

    public function storeExam(Request $request): JsonResponse
    {
        $data = $request->validate([
            'course_id' => ['nullable', 'integer', 'min:1'],
            'code' => ['required', 'string', 'max:64'],
            'title' => ['required', 'string', 'max:200'],
            'passing_score' => ['required', 'integer', 'min:0', 'max:1000'],
            'max_score' => ['required', 'integer', 'min:1', 'max:1000', 'gte:passing_score'],
            'publish' => ['nullable', 'boolean'],
        ]);

        return $this->runCommand($request, 'talent.training.exam.create', 'training.exam.manage', $data, function (int $actorUserId) use ($data): array {
            return ['status' => 201, 'body' => $this->lifecycle->createExam($actorUserId, $data)];
        });
    }

    public function publishExam(Request $request, int $examId): JsonResponse
    {
        return $this->runCommand($request, 'talent.training.exam.publish', 'training.exam.manage', ['exam_id' => $examId], function (int $actorUserId) use ($examId): array {
            return ['status' => 200, 'body' => $this->lifecycle->publishExam($actorUserId, $examId)];
        });
    }

    public function feedback(Request $request): JsonResponse
    {
        $enrollmentId = $request->query('enrollment_id') !== null ? (int) $request->query('enrollment_id') : null;

        return $this->projections->ok(
            $this->lifecycle->feedback($enrollmentId, $this->asOf($request)),
        );
    }

    public function storeFeedback(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enrollment_id' => ['required', 'integer', 'min:1'],
            'rating' => ['nullable', 'integer', 'min:1', 'max:5'],
            'comments' => ['required', 'string', 'max:10000'],
            'questionnaire' => ['nullable', 'array'],
        ]);

        return $this->runCommand($request, 'talent.training.feedback.record', 'training.feedback.manage', $data, function (int $actorUserId) use ($data): array {
            return ['status' => 201, 'body' => $this->lifecycle->createFeedback($actorUserId, $data)];
        });
    }

    public function records(Request $request): JsonResponse
    {
        $employmentId = $request->query('employment_id') !== null ? (int) $request->query('employment_id') : null;

        return $this->projections->ok(
            $this->lifecycle->records($employmentId, $this->asOf($request)),
        );
    }

    public function exportRecords(Request $request): JsonResponse
    {
        $principal = $this->principals->resolve();
        $fields = $request->query('fields');
        $employmentId = $request->query('employment_id') !== null ? (int) $request->query('employment_id') : null;
        $result = $this->lifecycle->exportRecords(
            $principal->userId(),
            $employmentId,
            $this->asOf($request),
            is_array($fields) ? array_values(array_map('strval', $fields)) : [],
        );

        return $this->projections->ok(
            $this->projection($result['items'], $this->asOf($request), ['fields' => $result['fields']]),
        );
    }

    public function reports(Request $request): JsonResponse
    {
        $planId = $request->query('plan_id') !== null ? (int) $request->query('plan_id') : null;

        return $this->projections->ok(
            $this->lifecycle->reports($planId, $this->asOf($request)),
        );
    }

    /**
     * Legacy report read retained for existing clients; same live enrolment
     * rows plus the ADR-0029 projection envelope.
     */
    public function report(Request $request): JsonResponse
    {
        $items = $this->training->report(
            $request->query('program_id') !== null ? (int) $request->query('program_id') : null,
            $request->query('employment_id') !== null ? (int) $request->query('employment_id') : null,
        );

        return $this->projections->ok($this->projection($items, $this->asOf($request)));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  \Closure(int): array{status: int, body: array<string, mixed>}  $operation
     */
    private function runCommand(Request $request, string $name, string $capability, array $payload, \Closure $operation): JsonResponse
    {
        $principal = $this->principals->resolve();
        $definition = new CommandDefinition(
            name: $name,
            capability: $capability,
            anchor: AnchorType::COMPANY,
            idempotent: true,
            invariants: [],
            effect: null,
            auditAction: $name,
        );

        $result = $this->commands->run(
            $definition,
            $payload,
            function () use ($operation, $principal): array {
                $outcome = $operation($principal->userId());

                return [
                    'http_status' => $outcome['status'],
                    'body' => $outcome['body'],
                    'resource_type' => 'talent.training',
                ];
            },
            (string) $request->header('Idempotency-Key', ''),
        );

        $response = $result->status === 201
            ? ApiResponse::created($result->body)
            : ApiResponse::ok($result->body);

        if ($result->replayed) {
            $response->headers->set('Idempotency-Replayed', 'true');
        }

        return $response;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>|null  $drilldown
     */
    private function projection(array $items, string $asOf, ?array $drilldown = null): ProjectionResult
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
            drilldown: $drilldown,
        );
    }

    private function asOf(Request $request): string
    {
        return $this->businessDates->resolve(
            is_string($request->query('as_of')) ? $request->query('as_of') : null,
        )->value();
    }
}
