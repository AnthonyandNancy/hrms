<?php

declare(strict_types=1);

namespace App\Modules\Talent\Services;

use App\Modules\Talent\Models\TrainingEnrollment;
use App\Modules\Talent\Models\TrainingProgram;
use App\Modules\Talent\Models\TrainingSession;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Observability\AuditRecorder;
use App\Shared\Time\Clock;
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
    public function createProgram(int $tenantId, int $actorUserId, array $input): array
    {
        $program = TrainingProgram::query()->firstOrCreate(
            ['tenant_id' => $tenantId, 'code' => (string) $input['code']],
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
    public function createSession(int $tenantId, int $actorUserId, array $input): array
    {
        $program = TrainingProgram::query()
            ->where('tenant_id', $tenantId)
            ->whereKey((int) $input['program_id'])
            ->first();

        if ($program === null) {
            throw new ValidationException(['program_id' => ['The training program does not exist.']]);
        }

        $session = TrainingSession::query()->create([
            'tenant_id' => $tenantId,
            'program_id' => (int) $program->id,
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
     * @return array<string, mixed>
     */
    public function enroll(int $tenantId, int $actorUserId, int $sessionId, int $employmentId): array
    {
        return DB::transaction(function () use ($tenantId, $actorUserId, $sessionId, $employmentId): array {
            /** @var TrainingSession $session */
            $session = TrainingSession::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($sessionId)
                ->lockForUpdate()
                ->firstOrFail();

            $existing = TrainingEnrollment::query()
                ->where('tenant_id', $tenantId)
                ->where('session_id', $sessionId)
                ->where('employment_id', $employmentId)
                ->first();

            if ($existing !== null) {
                return $this->enrollmentData($existing);
            }

            $count = TrainingEnrollment::query()
                ->where('tenant_id', $tenantId)
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
                    'tenant_id' => $tenantId,
                    'session_id' => $sessionId,
                    'employment_id' => $employmentId,
                    'status' => 'ENROLLED',
                    'attended' => false,
                    'recorded_at' => $this->clock->now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                $enrollment = TrainingEnrollment::query()
                    ->where('tenant_id', $tenantId)
                    ->where('session_id', $sessionId)
                    ->where('employment_id', $employmentId)
                    ->firstOrFail();
            }

            $this->audit->record($tenantId, $actorUserId, 'talent.training.enrolled', 'training_enrollment', (int) $enrollment->id, after: [
                'session_id' => $sessionId,
                'employment_id' => $employmentId,
            ]);

            return $this->enrollmentData($enrollment);
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function complete(int $tenantId, int $actorUserId, int $enrollmentId, array $input): array
    {
        return DB::transaction(function () use ($tenantId, $actorUserId, $enrollmentId, $input): array {
            $enrollment = TrainingEnrollment::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($enrollmentId)
                ->lockForUpdate()
                ->first();

            if ($enrollment === null) {
                throw NotFoundException::forResource();
            }

            if ($enrollment->status === 'COMPLETED') {
                return $this->enrollmentData($enrollment);
            }

            $enrollment->forceFill([
                'status' => 'COMPLETED',
                'attended' => (bool) ($input['attended'] ?? true),
                'completion_result' => (string) $input['completion_result'],
                'score' => isset($input['score']) ? (int) $input['score'] : null,
                'completed_at' => $this->clock->now(),
            ])->save();

            $this->audit->record($tenantId, $actorUserId, 'talent.training.completed', 'training_enrollment', (int) $enrollment->id, after: [
                'completion_result' => (string) $enrollment->completion_result,
            ]);

            return $this->enrollmentData($enrollment);
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    /**
     * @return list<array<string, mixed>>
     */
    public function sessions(int $tenantId, ?int $programId = null, ?string $status = null): array
    {
        return \App\Modules\Talent\Models\TrainingSession::query()
            ->where('tenant_id', $tenantId)
            ->when($programId !== null, fn ($builder) => $builder->where('program_id', $programId))
            ->when($status !== null && $status !== '', fn ($builder) => $builder->where('status', $status))
            ->orderByDesc('id')
            ->get()
            ->map(static fn (\App\Modules\Talent\Models\TrainingSession $session): array => [
                'session_id' => (int) $session->id,
                'program_id' => (int) $session->program_id,
                'starts_on' => (string) $session->starts_on,
                'ends_on' => (string) $session->ends_on,
                'starts_at' => $session->starts_at?->toIso8601ZuluString(),
                'ends_at' => $session->ends_at?->toIso8601ZuluString(),
                'capacity' => (int) $session->capacity,
                'location' => $session->location,
                'status' => (string) $session->status,
            ])->all();
    }

    public function report(int $tenantId, ?int $programId = null, ?int $employmentId = null): array
    {
        return TrainingEnrollment::query()
            ->where('tenant_id', $tenantId)
            ->when($employmentId !== null, fn ($query) => $query->where('employment_id', $employmentId))
            ->when($programId !== null, fn ($query) => $query->whereIn(
                'session_id',
                TrainingSession::query()->where('tenant_id', $tenantId)->where('program_id', $programId)->select('id'),
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
            'completed_at' => $enrollment->completed_at !== null ? \Carbon\CarbonImmutable::parse((string) $enrollment->completed_at)->toIso8601ZuluString() : null,
        ];
    }
}
