<?php

declare(strict_types=1);

namespace App\Modules\Talent\Services;

use App\Modules\Talent\Models\Candidate;
use App\Modules\Talent\Models\CandidateApplication;
use App\Modules\Talent\Models\Requisition;
use App\Modules\Talent\Models\RequisitionOpening;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Observability\AuditRecorder;
use App\Shared\Time\Clock;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Single writer for candidate applications.
 *
 * One candidate can apply to the same requisition only once. Interviews,
 * evaluations and offers all hang off that candidate+requisition application;
 * list projections read this table instead of reverse-inferring a requisition
 * from whichever interview or offer row sorts first.
 */
final class CandidateApplicationService
{
    public function __construct(
        private readonly Clock $clock,
        private readonly AuditRecorder $audit,
        private readonly CandidateApplicationStateMachine $machine,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function listForCandidate(int $candidateId): array
    {
        $candidate = Candidate::query()->whereKey($candidateId)->first();

        if ($candidate === null) {
            throw NotFoundException::forResource();
        }

        return CandidateApplication::query()
            ->where('candidate_id', $candidateId)
            ->orderByDesc('id')
            ->get()
            ->map(fn (CandidateApplication $application): array => $this->applicationData($application))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function store(int $actorUserId, int $candidateId, array $input): array
    {
        return DB::transaction(function () use ($actorUserId, $candidateId, $input): array {
            $candidate = Candidate::query()
                ->whereKey($candidateId)
                ->lockForUpdate()
                ->first();

            if ($candidate === null) {
                throw NotFoundException::forResource();
            }

            $requisition = Requisition::query()
                ->whereKey((int) $input['requisition_id'])
                ->lockForUpdate()
                ->first();

            if ($requisition === null) {
                throw new ValidationException(['requisition_id' => ['The requisition does not exist.']]);
            }

            $existing = CandidateApplication::query()
                ->where('candidate_id', $candidateId)
                ->where('requisition_id', (int) $requisition->id)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return [...$this->applicationData($existing), 'replayed' => true];
            }

            $this->assertRequisitionAcceptsApplications($requisition);
            $openingId = isset($input['opening_id']) ? (int) $input['opening_id'] : null;

            if ($openingId !== null) {
                $opening = RequisitionOpening::query()->whereKey($openingId)->first();

                if ($opening === null
                    || (int) $opening->requisition_id !== (int) $requisition->id
                    || (string) $opening->status !== 'OPEN') {
                    throw new ValidationException(['opening_id' => ['The opening must belong to the requisition and be open.']]);
                }
            }

            try {
                $application = CandidateApplication::query()->create([
                    'candidate_id' => $candidateId,
                    'requisition_id' => (int) $requisition->id,
                    'opening_id' => $openingId,
                    'stage' => 'NEW',
                    'status' => 'ACTIVE',
                    'source' => $this->nullableString($input['source'] ?? null),
                    'recorded_at' => $this->clock->now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                $application = CandidateApplication::query()
                    ->where('candidate_id', $candidateId)
                    ->where('requisition_id', (int) $requisition->id)
                    ->firstOrFail();

                return [...$this->applicationData($application), 'replayed' => true];
            }

            $this->audit->record(
                actorUserId: $actorUserId,
                action: 'talent.candidate_application.created',
                aggregateType: 'candidate_application',
                aggregateId: (int) $application->id,
                after: [
                    'candidate_id' => $candidateId,
                    'requisition_id' => (int) $requisition->id,
                    'opening_id' => $openingId,
                    'stage' => 'NEW',
                ],
            );

            return [...$this->applicationData($application), 'replayed' => false];
        });
    }

    /**
     * Materialise the application that a downstream recruitment write attaches
     * to. This keeps legacy offer rows linkable without inventing a candidate
     * or an offer.
     */
    public function ensureForWrite(int $actorUserId, int $candidateId, int $requisitionId, string $source): CandidateApplication
    {
        return DB::transaction(function () use ($actorUserId, $candidateId, $requisitionId, $source): CandidateApplication {
            $candidate = Candidate::query()->whereKey($candidateId)->lockForUpdate()->first();

            if ($candidate === null) {
                throw NotFoundException::forResource();
            }

            $requisition = Requisition::query()->whereKey($requisitionId)->lockForUpdate()->first();

            if ($requisition === null) {
                throw new ValidationException(['requisition_id' => ['The requisition does not exist.']]);
            }

            $existing = CandidateApplication::query()
                ->where('candidate_id', $candidateId)
                ->where('requisition_id', $requisitionId)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $this->assertRequisitionAcceptsApplications($requisition);

            try {
                $application = CandidateApplication::query()->create([
                    'candidate_id' => $candidateId,
                    'requisition_id' => $requisitionId,
                    'opening_id' => null,
                    'stage' => 'NEW',
                    'status' => 'ACTIVE',
                    'source' => $source,
                    'recorded_at' => $this->clock->now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                return CandidateApplication::query()
                    ->where('candidate_id', $candidateId)
                    ->where('requisition_id', $requisitionId)
                    ->firstOrFail();
            }

            $this->audit->record(
                actorUserId: $actorUserId,
                action: 'talent.candidate_application.created',
                aggregateType: 'candidate_application',
                aggregateId: (int) $application->id,
                after: [
                    'candidate_id' => $candidateId,
                    'requisition_id' => $requisitionId,
                    'stage' => 'NEW',
                    'source' => $source,
                ],
            );

            return $application;
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function moveStage(int $actorUserId, int $candidateId, array $input): array
    {
        return DB::transaction(function () use ($actorUserId, $candidateId, $input): array {
            $candidate = Candidate::query()
                ->whereKey($candidateId)
                ->lockForUpdate()
                ->first();

            if ($candidate === null) {
                throw NotFoundException::forResource();
            }

            if ((string) $candidate->status === 'CONVERTED') {
                throw new ConflictException('A converted candidate cannot be moved back through the pipeline.', [
                    'reason' => 'CANDIDATE_ALREADY_HIRED',
                    'status' => (string) $candidate->status,
                ]);
            }

            $applicationQuery = CandidateApplication::query()
                ->where('candidate_id', $candidateId)
                ->lockForUpdate();

            if (isset($input['application_id']) && (int) $input['application_id'] > 0) {
                $applicationQuery->whereKey((int) $input['application_id']);
            } elseif (isset($input['requisition_id']) && (int) $input['requisition_id'] > 0) {
                $applicationQuery->where('requisition_id', (int) $input['requisition_id']);
            } else {
                $applicationQuery->orderByDesc('id');
            }

            $application = $applicationQuery->first();

            if ($application === null) {
                throw new ConflictException('A candidate application is required before moving a stage.', [
                    'reason' => 'APPLICATION_REQUIRED',
                ]);
            }

            $from = (string) $application->stage;
            $to = mb_strtoupper(trim((string) $input['status']));

            if ($from === $to) {
                return [
                    'candidate_id' => $candidateId,
                    'application_id' => (int) $application->id,
                    'status' => $to,
                    'application_stage' => $to,
                    'previous_status' => $from,
                    'replayed' => true,
                ];
            }

            $this->machine->assertManualTransition($from, $to);

            $application->forceFill([
                'stage' => $to,
                'status' => $to === 'REJECTED' ? 'REJECTED' : 'ACTIVE',
            ])->save();

            $candidate->forceFill(['status' => $to])->save();

            $this->audit->record(
                actorUserId: $actorUserId,
                action: 'talent.candidate_application.stage_moved',
                aggregateType: 'candidate_application',
                aggregateId: (int) $application->id,
                before: ['stage' => $from],
                after: ['stage' => $to],
                metadata: ['reason' => $input['reason'] ?? null],
            );

            return [
                'candidate_id' => $candidateId,
                'application_id' => (int) $application->id,
                'status' => $to,
                'application_stage' => $to,
                'previous_status' => $from,
                'replayed' => false,
            ];
        });
    }

    /**
     * Advance the application to a stage owned by a specific recruitment
     * command (interview scheduled, offer drafted). The generic stage endpoint
     * uses moveStage() and cannot reach CONVERTED.
     */
    public function advanceStage(int $actorUserId, CandidateApplication $application, string $stage, string $action): void
    {
        $from = (string) $application->stage;

        if ($from === $stage || in_array($from, ['OFFER_ACCEPTED', 'CONVERTED', 'REJECTED'], true)) {
            return;
        }

        $this->machine->assertManualTransition($from, $stage);

        $application->forceFill(['stage' => $stage, 'status' => 'ACTIVE'])->save();

        $candidate = Candidate::query()->whereKey((int) $application->candidate_id)->first();

        if ($candidate !== null) {
            $candidate->forceFill(['status' => $stage])->save();
        }

        $this->audit->record(
            actorUserId: $actorUserId,
            action: $action,
            aggregateType: 'candidate_application',
            aggregateId: (int) $application->id,
            before: ['stage' => $from],
            after: ['stage' => $stage],
        );
    }

    public function markOfferAccepted(int $actorUserId, CandidateApplication $application): void
    {
        if ((string) $application->stage === 'OFFER_ACCEPTED') {
            return;
        }

        $before = (string) $application->stage;
        $application->forceFill(['stage' => 'OFFER_ACCEPTED', 'status' => 'ACTIVE'])->save();
        Candidate::query()->whereKey((int) $application->candidate_id)->update(['status' => 'OFFER_ACCEPTED']);

        $this->audit->record(
            actorUserId: $actorUserId,
            action: 'talent.candidate_application.offer_accepted',
            aggregateType: 'candidate_application',
            aggregateId: (int) $application->id,
            before: ['stage' => $before],
            after: ['stage' => 'OFFER_ACCEPTED'],
        );
    }

    public function markConverted(int $actorUserId, CandidateApplication $application): void
    {
        if ((string) $application->stage === 'CONVERTED' && (string) $application->status === 'CONVERTED') {
            return;
        }

        $before = ['stage' => (string) $application->stage, 'status' => (string) $application->status];
        $application->forceFill(['stage' => 'CONVERTED', 'status' => 'CONVERTED'])->save();

        $this->audit->record(
            actorUserId: $actorUserId,
            action: 'talent.candidate_application.converted',
            aggregateType: 'candidate_application',
            aggregateId: (int) $application->id,
            before: $before,
            after: ['stage' => 'CONVERTED', 'status' => 'CONVERTED'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function applicationData(CandidateApplication $application): array
    {
        $requisition = Requisition::query()->whereKey((int) $application->requisition_id)->first();

        return [
            'application_id' => (int) $application->id,
            'candidate_id' => (int) $application->candidate_id,
            'requisition_id' => (int) $application->requisition_id,
            'opening_id' => $application->opening_id !== null ? (int) $application->opening_id : null,
            'stage' => (string) $application->stage,
            'status' => (string) $application->status,
            'source' => $application->source,
            'recorded_at' => $application->recorded_at?->toIso8601ZuluString(),
            'requisition_no' => $requisition?->requisition_no,
            'requisition_title' => $requisition?->title,
            'requisition_status' => $requisition?->status,
        ];
    }

    private function assertRequisitionAcceptsApplications(Requisition $requisition): void
    {
        if ((string) $requisition->status === 'CLOSED') {
            throw new ConflictException('A closed requisition cannot accept new applications.', [
                'reason' => 'REQUISITION_CLOSED',
                'status' => 'CLOSED',
            ]);
        }

        if ((string) $requisition->status !== 'APPROVED') {
            throw new ConflictException('A requisition must be approved before it accepts applications.', [
                'reason' => 'REQUISITION_NOT_APPROVED',
                'status' => (string) $requisition->status,
            ]);
        }
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }
}
