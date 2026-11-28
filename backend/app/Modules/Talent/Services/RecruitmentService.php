<?php

declare(strict_types=1);

namespace App\Modules\Talent\Services;

use App\Modules\Talent\Domain\OfferStateMachine;
use App\Modules\Talent\Models\Candidate;
use App\Modules\Talent\Models\CandidateApplication;
use App\Modules\Talent\Models\Interview;
use App\Modules\Talent\Models\Offer;
use App\Modules\Talent\Models\Requisition;
use App\Modules\Workflow\Contracts\WorkflowCaseGateway;
use App\Shared\Authorization\Contracts\SensitiveAccessAuditor;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Observability\AuditRecorder;
use App\Shared\Time\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class RecruitmentService
{
    public function __construct(
        private readonly Clock $clock,
        private readonly AuditRecorder $audit,
        private readonly WorkflowCaseGateway $workflow,
        private readonly OfferStateMachine $offerMachine,
        private readonly \App\Modules\Lifecycle\Contracts\TalentOnboardingDraftPort $onboarding,
        private readonly SensitiveAccessAuditor $sensitiveAuditor,
        private readonly CandidateApplicationService $applications,
        private readonly RecruitmentOnboardingStateService $onboardingState,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function createRequisition(int $actorUserId, array $input, string $businessDate): array
    {
        return DB::transaction(function () use ($actorUserId, $input, $businessDate): array {
            $requisition = Requisition::query()->create([
                'org_unit_id' => (int) $input['org_unit_id'],
                'requisition_no' => (string) $input['requisition_no'],
                'title' => (string) $input['title'],
                'status' => 'PENDING_APPROVAL',
                'headcount' => (int) $input['headcount'],
                'budget_minor' => isset($input['budget_minor']) ? (int) $input['budget_minor'] : null,
                'budget_currency' => isset($input['budget_currency']) ? mb_strtoupper((string) $input['budget_currency']) : null,
                'target_start_date' => $input['target_start_date'] ?? null,
                'recorded_at' => $this->clock->now(),
            ]);

            $this->ensureWorkflowTemplate();
            $case = $this->workflow->createCase(
                requesterUserId: $actorUserId,
                type: 'REQUISITION_APPROVAL',
                subjectType: 'recruitment_requisition',
                subjectId: (int) $requisition->id,
                title: 'Approve requisition '.$requisition->requisition_no,
                payload: [
                    'requisition_id' => (int) $requisition->id,
                    'headcount' => (int) $requisition->headcount,
                ],
                templateCode: 'talent.requisition',
                businessDate: $businessDate,
                submit: true,
            );

            $requisition->forceFill(['case_id' => $case->id])->save();

            $this->audit->record($actorUserId, 'talent.requisition.created', 'recruitment_requisition', (int) $requisition->id, after: [
                'case_id' => $case->id,
                'headcount' => (int) $requisition->headcount,
            ]);

            return [
                'requisition_id' => (int) $requisition->id,
                'requisition_no' => (string) $requisition->requisition_no,
                'status' => (string) $requisition->status,
                'headcount' => (int) $requisition->headcount,
                'budget_minor' => $requisition->budget_minor !== null ? (int) $requisition->budget_minor : null,
                'budget_currency' => $requisition->budget_currency !== null ? (string) $requisition->budget_currency : null,
                'case_id' => $case->id,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function createCandidate(int $actorUserId, array $input): array
    {
        $email = isset($input['email']) ? mb_strtolower(trim((string) $input['email'])) : null;
        $mobile = isset($input['mobile']) ? trim((string) $input['mobile']) : null;
        $emailHash = $email !== null && $email !== '' ? hash_hmac('sha256', $email, (string) config('hrms.pii_hmac_key')) : null;
        $mobileHash = $mobile !== null && $mobile !== '' ? hash_hmac('sha256', $mobile, (string) config('hrms.pii_hmac_key')) : null;

        $existing = Candidate::query()
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
            'display_name' => (string) $input['display_name'],
            'email' => $email,
            'mobile_ciphertext' => $mobile,
            'email_hash' => $emailHash,
            'mobile_hash' => $mobileHash,
            'source' => $input['source'] ?? null,
            'status' => 'NEW',
            'recorded_at' => $this->clock->now(),
        ]);

        $this->audit->record($actorUserId, 'talent.candidate.created', 'candidate', (int) $candidate->id, after: [
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
    public function createInterview(int $actorUserId, int $candidateId, array $input): array
    {
        $candidate = Candidate::query()->whereKey($candidateId)->first();

        if ($candidate === null) {
            throw NotFoundException::forResource();
        }

        $requisitionId = (int) $input['requisition_id'];
        $requisition = Requisition::query()->whereKey($requisitionId)->first();

        if ($requisition === null) {
            throw new ValidationException(['requisition_id' => ['The requisition does not exist.']]);
        }

        if ((string) $requisition->status === 'CLOSED') {
            throw new ConflictException('A closed requisition cannot schedule new interviews.', [
                'reason' => 'REQUISITION_CLOSED',
                'status' => 'CLOSED',
            ]);
        }

        if ((string) $requisition->status !== 'APPROVED') {
            throw new ValidationException(['requisition_id' => ['Interviews require an approved requisition.']]);
        }

        $application = $this->applications->ensureForWrite($actorUserId, $candidateId, $requisitionId, 'INTERVIEW');
        $this->applications->advanceStage($actorUserId, $application, 'INTERVIEW', 'talent.candidate_application.interview_scheduled');

        $interview = Interview::query()->create([
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
            'application_id' => (int) $application->id,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function createOffer(int $actorUserId, array $input, string $businessDate): array
    {
        return DB::transaction(function () use ($actorUserId, $input, $businessDate): array {
            $candidate = Candidate::query()->whereKey((int) $input['candidate_id'])->lockForUpdate()->first();
            $requisition = Requisition::query()
                ->whereKey((int) $input['requisition_id'])
                ->first();

            if ($candidate === null) {
                throw NotFoundException::forResource();
            }

            if ($requisition === null) {
                throw new ValidationException(['requisition_id' => ['Offers require an approved requisition.']]);
            }

            if ((string) $requisition->status !== 'APPROVED') {
                throw new ConflictException('Offers require an approved requisition.', [
                    'reason' => 'REQUISITION_NOT_APPROVED',
                    'status' => (string) $requisition->status,
                ]);
            }

            $application = $this->applications->ensureForWrite($actorUserId, (int) $candidate->id, (int) $requisition->id, 'OFFER');
            $version = ((int) Offer::query()
                ->where('candidate_id', (int) $candidate->id)
                ->max('version')) + 1;

            $offer = Offer::query()->create([
                'candidate_id' => (int) $candidate->id,
                'requisition_id' => (int) $requisition->id,
                'version' => max(1, $version),
                'status' => 'PENDING_APPROVAL',
                'base_salary_minor' => (int) $input['base_salary_minor'],
                'salary_currency' => (string) $input['salary_currency'],
                'salary_scheme_code' => isset($input['salary_scheme_code']) ? (string) $input['salary_scheme_code'] : null,
                'start_date' => (string) $input['start_date'],
                'recorded_at' => $this->clock->now(),
            ]);

            $this->ensureWorkflowTemplate();
            $case = $this->workflow->createCase(
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
            $this->applications->advanceStage($actorUserId, $application, 'OFFER', 'talent.candidate_application.offer_drafted');

            return [
                'offer_id' => (int) $offer->id,
                'version' => (int) $offer->version,
                'status' => (string) $offer->status,
                'case_id' => $case->id,
                'application_id' => (int) $application->id,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function acceptOffer(int $actorUserId, int $offerId, array $input, string $businessDate): array
    {
        return DB::transaction(function () use ($actorUserId, $offerId, $input, $businessDate): array {
            $offer = Offer::query()
                ->whereKey($offerId)
                ->lockForUpdate()
                ->first();

            if ($offer === null) {
                throw NotFoundException::forResource();
            }

            if ($offer->status === 'ACCEPTED') {
                $state = $this->onboardingState->onboardingStateForOffer((int) $offer->id, (string) ($input['hire_date'] ?? $offer->start_date));

                if ($offer->onboarding_case_id === null && $state['status'] === 'NOT_CREATED') {
                    $draft = $this->onboarding->createDraftFromAcceptedOffer([
                        'actor_user_id' => $actorUserId,
                        'candidate_id' => (int) $offer->candidate_id,
                        'person' => $this->onboardingPerson((int) $offer->candidate_id),
                        'offer_id' => (int) $offer->id,
                        'org_unit_id' => (int) $input['org_unit_id'],
                        'employee_no' => (string) $input['employee_no'],
                        'hire_date' => (string) ($input['hire_date'] ?? $offer->start_date),
                        'business_date' => $businessDate,
                        'terms' => $this->offerCompensationTerms($offer),
                    ]);
                    $offer->forceFill(['onboarding_case_id' => $draft['case_id']])->save();
                }

                return [
                    'offer_id' => (int) $offer->id,
                    'offer_status' => 'ACCEPTED',
                    'status' => 'ACCEPTED',
                    'onboarding_case_id' => $offer->onboarding_case_id,
                    'replayed' => true,
                ];
            }

            $this->offerMachine->assertCanAccept((string) $offer->status);
            $requisition = Requisition::query()->whereKey((int) $offer->requisition_id)->first();

            if ($requisition === null || (string) $requisition->status === 'CLOSED') {
                throw new ConflictException('A closed requisition cannot accept an offer.', [
                    'reason' => 'REQUISITION_CLOSED',
                    'status' => $requisition?->status,
                ]);
            }

            $offer->forceFill(['status' => 'ACCEPTED', 'accepted_at' => $this->clock->now()])->save();

            $application = CandidateApplication::query()
                ->where('candidate_id', (int) $offer->candidate_id)
                ->where('requisition_id', (int) $offer->requisition_id)
                ->first();

            if ($application !== null) {
                $this->applications->markOfferAccepted($actorUserId, $application);
            }

            foreach (Offer::query()
                ->where('candidate_id', (int) $offer->candidate_id)
                ->where('id', '!=', $offer->id)
                ->whereIn('status', ['APPROVED', 'PENDING_APPROVAL'])
                ->get() as $other) {
                $other->forceFill(['status' => 'SUPERSEDED', 'superseded_by_id' => $offer->id])->save();
            }

            $draft = $this->onboarding->createDraftFromAcceptedOffer([
                'actor_user_id' => $actorUserId,
                'candidate_id' => (int) $offer->candidate_id,
                'person' => $this->onboardingPerson((int) $offer->candidate_id),
                'offer_id' => (int) $offer->id,
                'org_unit_id' => (int) $input['org_unit_id'],
                'employee_no' => (string) $input['employee_no'],
                'hire_date' => (string) ($input['hire_date'] ?? $offer->start_date),
                'business_date' => $businessDate,
                'terms' => $this->offerCompensationTerms($offer),
            ]);

            $offer->forceFill(['onboarding_case_id' => $draft['case_id']])->save();

            return [
                'offer_id' => (int) $offer->id,
                'offer_status' => 'ACCEPTED',
                'status' => 'ACCEPTED',
                'onboarding_case_id' => $draft['case_id'],
                'replayed' => false,
            ];
        });
    }

    /**
     * @return array{base_salary_minor:int,salary_currency:string,salary_scheme_code:string|null}
     */
    private function offerCompensationTerms(Offer $offer): array
    {
        return [
            'base_salary_minor' => (int) $offer->base_salary_minor,
            'salary_currency' => (string) $offer->salary_currency,
            'salary_scheme_code' => $offer->salary_scheme_code !== null ? (string) $offer->salary_scheme_code : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{items: list<array<string, mixed>>, page: int, page_size: int, total: int}
     */
    public function offers(array $filters = [], int $page = 1, int $pageSize = 20): array
    {
        $query = Offer::query()
            ->when(isset($filters['candidate_id']) && (int) $filters['candidate_id'] > 0, fn ($builder) => $builder->where('candidate_id', (int) $filters['candidate_id']))
            ->when(isset($filters['requisition_id']) && (int) $filters['requisition_id'] > 0, fn ($builder) => $builder->where('requisition_id', (int) $filters['requisition_id']))
            ->when(isset($filters['status']) && $filters['status'] !== '', fn ($builder) => $builder->where('status', (string) $filters['status']))
            ->orderBy('candidate_id')
            ->orderBy('version');

        $total = (int) (clone $query)->count();
        $items = $query->forPage($page, $pageSize)->get()->map(function (Offer $offer): array {
            $this->sensitiveAuditor->record(
                userId: app(\App\Shared\Authorization\PrincipalResolver::class)->resolve()->userId(),
                capabilityCode: 'recruitment.offer.manage',
                resourceType: 'offer',
                resourceId: (int) $offer->id,
                fields: ['base_salary_minor'],
                context: ['profile' => 'P3_RESTRICTED', 'access' => 'LIST'],
            );

            return [
                'offer_id' => (int) $offer->id,
                'candidate_id' => (int) $offer->candidate_id,
                'requisition_id' => (int) $offer->requisition_id,
                'version' => (int) $offer->version,
                'status' => (string) $offer->status,
                'base_salary_minor' => (int) $offer->base_salary_minor,
                'salary_currency' => (string) $offer->salary_currency,
                'salary_scheme_code' => $offer->salary_scheme_code !== null ? (string) $offer->salary_scheme_code : null,
                'start_date' => (string) $offer->start_date,
                'case_id' => $offer->case_id !== null ? (int) $offer->case_id : null,
                'onboarding_case_id' => $offer->onboarding_case_id !== null ? (int) $offer->onboarding_case_id : null,
                'accepted_at' => $this->iso($offer->accepted_at),
                'declined_at' => $this->iso($offer->declined_at),
            ];
        })->values()->all();

        return ['items' => $items, 'page' => $page, 'page_size' => $pageSize, 'total' => $total];
    }

    private function iso(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return CarbonImmutable::parse((string) $value)->toIso8601ZuluString();
    }

    private function ensureWorkflowTemplate(): void
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

        $this->workflow->ensureTemplate('talent.requisition', 'Talent requisition approval', $definition);
        $definition['effects'][0]['type'] = 'talent.offer.approve';
        $this->workflow->ensureTemplate('talent.offer', 'Talent offer approval', $definition);
    }

    /**
     * @return array{legal_name:string,email?:string}
     */
    private function onboardingPerson(int $candidateId): array
    {
        $candidate = Candidate::query()->findOrFail($candidateId);
        $person = ['legal_name' => (string) $candidate->display_name];

        if (is_string($candidate->email) && $candidate->email !== '') {
            $person['email'] = $candidate->email;
        }

        return $person;
    }
}
