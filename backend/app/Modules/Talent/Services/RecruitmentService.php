<?php

declare(strict_types=1);

namespace App\Modules\Talent\Services;

use App\Modules\Lifecycle\Contracts\TalentOnboardingDraftPort;
use App\Modules\Talent\Domain\OfferStateMachine;
use App\Modules\Talent\Models\Candidate;
use App\Modules\Talent\Models\Interview;
use App\Modules\Talent\Models\Offer;
use App\Modules\Talent\Models\Requisition;
use App\Modules\Workflow\Contracts\WorkflowCaseGateway;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Observability\AuditRecorder;
use App\Shared\Time\Clock;
use Illuminate\Support\Facades\DB;

final class RecruitmentService
{
    public function __construct(
        private readonly Clock $clock,
        private readonly AuditRecorder $audit,
        private readonly WorkflowCaseGateway $workflow,
        private readonly OfferStateMachine $offerMachine,
        private readonly TalentOnboardingDraftPort $onboarding,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function createRequisition(int $tenantId, int $actorUserId, array $input, string $businessDate): array
    {
        return DB::transaction(function () use ($tenantId, $actorUserId, $input, $businessDate): array {
            $requisition = Requisition::query()->create([
                'tenant_id' => $tenantId,
                'legal_entity_id' => (int) $input['legal_entity_id'],
                'org_unit_id' => (int) $input['org_unit_id'],
                'requisition_no' => (string) $input['requisition_no'],
                'title' => (string) $input['title'],
                'status' => 'PENDING_APPROVAL',
                'headcount' => (int) $input['headcount'],
                'target_start_date' => $input['target_start_date'] ?? null,
                'recorded_at' => $this->clock->now(),
            ]);

            $this->ensureWorkflowTemplate($tenantId);
            $case = $this->workflow->createCase(
                tenantId: $tenantId,
                requesterUserId: $actorUserId,
                type: 'REQUISITION_APPROVAL',
                subjectType: 'recruitment_requisition',
                subjectId: (int) $requisition->id,
                title: 'Approve requisition '.$requisition->requisition_no,
                payload: [
                    'requisition_id' => (int) $requisition->id,
                    'headcount' => (int) $requisition->headcount,
                    'legal_entity_id' => (int) $requisition->legal_entity_id,
                ],
                templateCode: 'talent.requisition',
                businessDate: $businessDate,
                submit: true,
            );

            $requisition->forceFill(['case_id' => $case->id])->save();

            $this->audit->record($tenantId, $actorUserId, 'talent.requisition.created', 'recruitment_requisition', (int) $requisition->id, after: [
                'case_id' => $case->id,
                'headcount' => (int) $requisition->headcount,
            ]);

            return [
                'requisition_id' => (int) $requisition->id,
                'requisition_no' => (string) $requisition->requisition_no,
                'status' => (string) $requisition->status,
                'case_id' => $case->id,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function createCandidate(int $tenantId, int $actorUserId, array $input): array
    {
        $email = isset($input['email']) ? mb_strtolower(trim((string) $input['email'])) : null;
        $mobile = isset($input['mobile']) ? trim((string) $input['mobile']) : null;
        $emailHash = $email !== null && $email !== '' ? hash_hmac('sha256', $email, (string) config('hrms.pii_hmac_key')) : null;
        $mobileHash = $mobile !== null && $mobile !== '' ? hash_hmac('sha256', $mobile, (string) config('hrms.pii_hmac_key')) : null;

        $existing = Candidate::query()
            ->where('tenant_id', $tenantId)
            ->where(function ($query) use ($emailHash, $mobileHash): void {
                if ($emailHash !== null) {
                    $query->where('email_hash', $emailHash);
                }
                if ($mobileHash !== null) {
                    $query->orWhere('mobile_hash', $mobileHash);
                }
            })
            ->first();

        if ($existing !== null) {
            throw new ConflictException('A candidate with the same contact hash already exists.', [
                'reason' => 'CANDIDATE_DUPLICATE',
                'candidate_id' => (int) $existing->id,
            ]);
        }

        $candidate = Candidate::query()->create([
            'tenant_id' => $tenantId,
            'display_name' => (string) $input['display_name'],
            'email' => $email,
            'mobile_ciphertext' => $mobile,
            'email_hash' => $emailHash,
            'mobile_hash' => $mobileHash,
            'source' => $input['source'] ?? null,
            'status' => 'NEW',
            'recorded_at' => $this->clock->now(),
        ]);

        $this->audit->record($tenantId, $actorUserId, 'talent.candidate.created', 'candidate', (int) $candidate->id, after: [
            'has_email' => $emailHash !== null,
            'has_mobile' => $mobileHash !== null,
        ]);

        return [
            'candidate_id' => (int) $candidate->id,
            'display_name' => (string) $candidate->display_name,
            'status' => (string) $candidate->status,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function createInterview(int $tenantId, int $actorUserId, int $candidateId, array $input): array
    {
        $candidate = Candidate::query()->where('tenant_id', $tenantId)->whereKey($candidateId)->first();

        if ($candidate === null) {
            throw NotFoundException::forResource();
        }

        $requisitionId = (int) $input['requisition_id'];
        $requisition = Requisition::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($requisitionId)
            ->where('status', 'APPROVED')
            ->first();

        if ($requisition === null) {
            throw new ValidationException(['requisition_id' => ['Interviews require an approved requisition.']]);
        }

        $interview = Interview::query()->create([
            'tenant_id' => $tenantId,
            'candidate_id' => $candidateId,
            'requisition_id' => $requisitionId,
            'scheduled_at' => (string) $input['scheduled_at'],
            'duration_minutes' => (int) ($input['duration_minutes'] ?? 60),
            'interviewer' => (string) $input['interviewer'],
            'mode' => (string) ($input['mode'] ?? 'VIDEO'),
            'status' => 'SCHEDULED',
            'recorded_at' => $this->clock->now(),
        ]);

        return [
            'interview_id' => (int) $interview->id,
            'candidate_id' => $candidateId,
            'requisition_id' => $requisitionId,
            'status' => (string) $interview->status,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function createOffer(int $tenantId, int $actorUserId, array $input, string $businessDate): array
    {
        return DB::transaction(function () use ($tenantId, $actorUserId, $input, $businessDate): array {
            $candidate = Candidate::query()->where('tenant_id', $tenantId)->whereKey((int) $input['candidate_id'])->lockForUpdate()->first();
            $requisition = Requisition::query()
                ->where('tenant_id', $tenantId)
                ->whereKey((int) $input['requisition_id'])
                ->where('status', 'APPROVED')
                ->first();

            if ($candidate === null) {
                throw NotFoundException::forResource();
            }

            if ($requisition === null) {
                throw new ValidationException(['requisition_id' => ['Offers require an approved requisition.']]);
            }

            $version = ((int) Offer::query()
                ->where('tenant_id', $tenantId)
                ->where('candidate_id', (int) $candidate->id)
                ->max('version')) + 1;

            $offer = Offer::query()->create([
                'tenant_id' => $tenantId,
                'candidate_id' => (int) $candidate->id,
                'requisition_id' => (int) $requisition->id,
                'version' => max(1, $version),
                'status' => 'PENDING_APPROVAL',
                'base_salary_minor' => (int) $input['base_salary_minor'],
                'salary_currency' => (string) $input['salary_currency'],
                'start_date' => (string) $input['start_date'],
                'recorded_at' => $this->clock->now(),
            ]);

            $this->ensureWorkflowTemplate($tenantId);
            $case = $this->workflow->createCase(
                tenantId: $tenantId,
                requesterUserId: $actorUserId,
                type: 'OFFER_APPROVAL',
                subjectType: 'offer',
                subjectId: (int) $offer->id,
                title: 'Approve offer v'.$offer->version,
                payload: [
                    'offer_id' => (int) $offer->id,
                    'candidate_id' => (int) $candidate->id,
                    'base_salary_minor' => (int) $offer->base_salary_minor,
                ],
                templateCode: 'talent.offer',
                businessDate: $businessDate,
                submit: true,
            );

            $offer->forceFill(['case_id' => $case->id])->save();

            return [
                'offer_id' => (int) $offer->id,
                'version' => (int) $offer->version,
                'status' => (string) $offer->status,
                'case_id' => $case->id,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function acceptOffer(int $tenantId, int $actorUserId, int $offerId, array $input, string $businessDate): array
    {
        return DB::transaction(function () use ($tenantId, $actorUserId, $offerId, $input, $businessDate): array {
            $offer = Offer::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($offerId)
                ->lockForUpdate()
                ->first();

            if ($offer === null) {
                throw NotFoundException::forResource();
            }

            if ($offer->status === 'ACCEPTED') {
                return [
                    'offer_id' => (int) $offer->id,
                    'status' => 'ACCEPTED',
                    'onboarding_case_id' => $offer->onboarding_case_id,
                    'replayed' => true,
                ];
            }

            $this->offerMachine->assertCanAccept((string) $offer->status);

            $offer->forceFill(['status' => 'ACCEPTED', 'accepted_at' => $this->clock->now()])->save();

            foreach (Offer::query()
                ->where('tenant_id', $tenantId)
                ->where('candidate_id', (int) $offer->candidate_id)
                ->where('id', '!=', $offer->id)
                ->whereIn('status', ['APPROVED', 'PENDING_APPROVAL'])
                ->get() as $other) {
                $other->forceFill(['status' => 'SUPERSEDED', 'superseded_by_id' => $offer->id])->save();
            }

            $draft = $this->onboarding->createDraftFromAcceptedOffer([
                'tenant_id' => $tenantId,
                'actor_user_id' => $actorUserId,
                'candidate_id' => (int) $offer->candidate_id,
                'offer_id' => (int) $offer->id,
                'legal_entity_id' => (int) $input['legal_entity_id'],
                'org_unit_id' => (int) $input['org_unit_id'],
                'employee_no' => (string) $input['employee_no'],
                'hire_date' => (string) ($input['hire_date'] ?? $offer->start_date),
                'business_date' => $businessDate,
            ]);

            $offer->forceFill(['onboarding_case_id' => $draft['case_id']])->save();

            return [
                'offer_id' => (int) $offer->id,
                'status' => 'ACCEPTED',
                'onboarding_case_id' => $draft['case_id'],
                'replayed' => false,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{items: list<array<string, mixed>>, page: int, page_size: int, total: int}
     */
    public function offers(int $tenantId, array $filters = [], int $page = 1, int $pageSize = 20): array
    {
        $query = Offer::query()
            ->where('tenant_id', $tenantId)
            ->when(isset($filters['candidate_id']) && (int) $filters['candidate_id'] > 0, fn ($builder) => $builder->where('candidate_id', (int) $filters['candidate_id']))
            ->when(isset($filters['requisition_id']) && (int) $filters['requisition_id'] > 0, fn ($builder) => $builder->where('requisition_id', (int) $filters['requisition_id']))
            ->when(isset($filters['status']) && $filters['status'] !== '', fn ($builder) => $builder->where('status', (string) $filters['status']))
            ->orderBy('candidate_id')
            ->orderBy('version');

        $total = (int) (clone $query)->count();
        $items = $query->forPage($page, $pageSize)->get()->map(static fn (Offer $offer): array => [
            'offer_id' => (int) $offer->id,
            'candidate_id' => (int) $offer->candidate_id,
            'requisition_id' => (int) $offer->requisition_id,
            'version' => (int) $offer->version,
            'status' => (string) $offer->status,
            'base_salary_minor' => (int) $offer->base_salary_minor,
            'salary_currency' => (string) $offer->salary_currency,
            'start_date' => (string) $offer->start_date,
            'case_id' => $offer->case_id !== null ? (int) $offer->case_id : null,
            'onboarding_case_id' => $offer->onboarding_case_id !== null ? (int) $offer->onboarding_case_id : null,
            'accepted_at' => $offer->accepted_at?->toIso8601ZuluString(),
            'declined_at' => $offer->declined_at?->toIso8601ZuluString(),
        ])->all();

        return ['items' => $items, 'page' => $page, 'page_size' => $pageSize, 'total' => $total];
    }

    private function ensureWorkflowTemplate(int $tenantId): void
    {
        $definition = [
            'nodes' => [
                ['key' => 'start', 'name' => 'Start', 'type' => 'START', 'sequence' => 1],
                ['key' => 'hr_review', 'name' => 'Talent review', 'type' => 'APPROVAL', 'sequence' => 2, 'assignee_rule' => ['type' => 'ROLE', 'role_code' => 'HR_ADMIN'], 'required_count' => 1],
                ['key' => 'end', 'name' => 'End', 'type' => 'END', 'sequence' => 3],
            ],
            'effects' => [[
                'type' => 'talent.requisition.approve',
                'execution' => 'LOCAL',
                'aggregate_type' => 'case',
                'aggregate_id_field' => 'case_id',
            ]],
        ];

        $this->workflow->ensureTemplate($tenantId, 'talent.requisition', 'Talent requisition approval', $definition);
        $definition['effects'][0]['type'] = 'talent.offer.approve';
        $this->workflow->ensureTemplate($tenantId, 'talent.offer', 'Talent offer approval', $definition);
    }
}
