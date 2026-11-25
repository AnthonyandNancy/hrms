<?php

declare(strict_types=1);

namespace App\Modules\Talent\Services;

use App\Modules\Organization\Contracts\OrganizationFacts;
use App\Modules\People\Contracts\PeopleEmploymentWriter;
use App\Modules\Talent\Models\Candidate;
use App\Modules\Talent\Models\CandidateCommunication;
use App\Modules\Talent\Models\CandidateConversion;
use App\Modules\Talent\Models\CandidateEvaluation;
use App\Modules\Talent\Models\CandidateImport;
use App\Modules\Talent\Models\Interview;
use App\Modules\Talent\Models\Offer;
use App\Modules\Talent\Models\Requisition;
use App\Modules\Talent\Models\RequisitionOpening;
use App\Modules\Talent\Models\TalentPoolTag;
use App\Shared\Authorization\Contracts\SensitiveAccessAuditor;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Observability\AuditRecorder;
use App\Shared\Projections\ProjectionResult;
use App\Shared\Time\BusinessDate;
use App\Shared\Time\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Recruitment funnel write model and live projection.
 *
 * The board, budget and report are derived from requisitions, openings,
 * interviews, offers and candidate conversions. Candidate contact values are
 * never serialized in list projections; sensitive reads record field names
 * through the shared audit port.
 */
final class RecruitmentFunnelService
{
    private const IMPORT_MAX_ROWS = 500;

    public function __construct(
        private readonly Clock $clock,
        private readonly AuditRecorder $audit,
        private readonly SensitiveAccessAuditor $sensitiveAuditor,
        private readonly OrganizationFacts $organization,
        private readonly PeopleEmploymentWriter $people,
    ) {}

    public function board(int $tenantId, string $asOf): ProjectionResult
    {
        $items = Requisition::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('id')
            ->get()
            ->map(function (Requisition $requisition) use ($tenantId): array {
                $metrics = $this->requisitionMetrics($tenantId, (int) $requisition->id);

                return [
                    'requisition_id' => (int) $requisition->id,
                    'requisition_no' => (string) $requisition->requisition_no,
                    'title' => (string) $requisition->title,
                    'status' => (string) $requisition->status,
                    'legal_entity_id' => (int) $requisition->legal_entity_id,
                    'org_unit_id' => (int) $requisition->org_unit_id,
                    'headcount' => (int) $requisition->headcount,
                    'openings_count' => $metrics['openings_count'],
                    'candidate_count' => $metrics['candidate_count'],
                    'interview_count' => $metrics['interview_count'],
                    'offer_count' => $metrics['offer_count'],
                    'accepted_offers' => $metrics['accepted_offers'],
                    'hired_count' => $metrics['hired_count'],
                    'vacancy_count' => max(0, (int) $requisition->headcount - $metrics['hired_count']),
                    'fill_rate_bps' => $this->rateBps($metrics['hired_count'], (int) $requisition->headcount),
                ];
            })
            ->values()
            ->all();

        return $this->projection($items, $asOf);
    }

    public function budget(int $tenantId, string $asOf): ProjectionResult
    {
        $items = Requisition::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('id')
            ->get()
            ->map(function (Requisition $requisition) use ($tenantId): array {
                $metrics = $this->requisitionMetrics($tenantId, (int) $requisition->id);
                $budgetMinor = $requisition->budget_minor !== null ? (int) $requisition->budget_minor : null;
                $committedMinor = $metrics['accepted_offer_minor'];

                return [
                    'requisition_id' => (int) $requisition->id,
                    'requisition_no' => (string) $requisition->requisition_no,
                    'title' => (string) $requisition->title,
                    'status' => (string) $requisition->status,
                    'legal_entity_id' => (int) $requisition->legal_entity_id,
                    'headcount' => (int) $requisition->headcount,
                    'budget_minor' => $budgetMinor,
                    'budget_currency' => $requisition->budget_currency !== null ? (string) $requisition->budget_currency : null,
                    'committed_offer_minor' => $committedMinor,
                    'budget_variance_minor' => $budgetMinor !== null ? $budgetMinor - $committedMinor : null,
                    'filled_headcount' => $metrics['hired_count'],
                    'vacancy_count' => max(0, (int) $requisition->headcount - $metrics['hired_count']),
                ];
            })
            ->values()
            ->all();

        return $this->projection($items, $asOf);
    }

    public function reports(int $tenantId, string $asOf): ProjectionResult
    {
        $items = Requisition::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('id')
            ->get()
            ->map(function (Requisition $requisition) use ($tenantId): array {
                $metrics = $this->requisitionMetrics($tenantId, (int) $requisition->id);

                return [
                    'requisition_id' => (int) $requisition->id,
                    'requisition_no' => (string) $requisition->requisition_no,
                    'title' => (string) $requisition->title,
                    'status' => (string) $requisition->status,
                    'headcount' => (int) $requisition->headcount,
                    'openings_count' => $metrics['openings_count'],
                    'candidate_count' => $metrics['candidate_count'],
                    'interview_count' => $metrics['interview_count'],
                    'offer_count' => $metrics['offer_count'],
                    'accepted_offers' => $metrics['accepted_offers'],
                    'hired_count' => $metrics['hired_count'],
                    'fill_rate_bps' => $this->rateBps($metrics['hired_count'], (int) $requisition->headcount),
                    'time_to_hire_days' => $metrics['time_to_hire_days'],
                ];
            })
            ->values()
            ->all();

        return $this->projection($items, $asOf);
    }

    public function candidates(int $tenantId, int $actorUserId, string $asOf): ProjectionResult
    {
        $items = Candidate::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('id')
            ->get()
            ->map(function (Candidate $candidate) use ($tenantId, $actorUserId): array {
                $this->auditCandidateRead($tenantId, $actorUserId, $candidate, 'recruitment.candidate.read', ['email', 'mobile']);

                return [
                    'candidate_id' => (int) $candidate->id,
                    'display_name' => (string) $candidate->display_name,
                    'status' => (string) $candidate->status,
                    'has_email' => $candidate->email_hash !== null,
                    'has_mobile' => $candidate->mobile_hash !== null,
                    'source' => $candidate->source,
                ];
            })
            ->values()
            ->all();

        return $this->projection($items, $asOf);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function importCandidates(int $tenantId, int $actorUserId, array $input): array
    {
        $rows = $input['rows'] ?? null;

        if (! is_array($rows) || $rows === []) {
            throw new ValidationException(['rows' => ['At least one candidate row is required.']]);
        }

        if (count($rows) > self::IMPORT_MAX_ROWS) {
            throw new ValidationException(['rows' => ['A candidate import is limited to '.self::IMPORT_MAX_ROWS.' rows.']]);
        }

        $requisitionId = isset($input['requisition_id']) ? (int) $input['requisition_id'] : null;

        if ($requisitionId !== null) {
            $this->requisitionOrFail($tenantId, $requisitionId);
        }

        return DB::transaction(function () use ($tenantId, $actorUserId, $input, $rows, $requisitionId): array {
            $reports = [];
            $accepted = 0;

            foreach (array_values($rows) as $index => $row) {
                $report = $this->importRow($tenantId, $actorUserId, is_array($row) ? $row : [], $index + 1);

                if ($report['status'] === 'ACCEPTED') {
                    $accepted++;
                }

                $reports[] = $report;
            }

            $import = CandidateImport::query()->create([
                'tenant_id' => $tenantId,
                'requisition_id' => $requisitionId,
                'source' => isset($input['source']) && trim((string) $input['source']) !== '' ? (string) $input['source'] : 'MANUAL',
                'status' => 'COMPLETED',
                'row_count' => count($reports),
                'accepted_count' => $accepted,
                'rejected_count' => count($reports) - $accepted,
                'rows_json' => $reports,
                'recorded_at' => $this->clock->now(),
            ]);

            $this->audit->record($tenantId, $actorUserId, 'talent.candidate_import.completed', 'candidate_import', (int) $import->id, after: [
                'row_count' => (int) $import->row_count,
                'accepted_count' => (int) $import->accepted_count,
                'rejected_count' => (int) $import->rejected_count,
            ]);

            return [
                'import_id' => (int) $import->id,
                'requisition_id' => $requisitionId,
                'source' => (string) $import->source,
                'row_count' => (int) $import->row_count,
                'accepted_count' => (int) $import->accepted_count,
                'rejected_count' => (int) $import->rejected_count,
                'rows' => $reports,
                'recorded_at' => $this->iso($import->recorded_at),
            ];
        });
    }

    public function evaluations(int $tenantId, int $actorUserId, int $candidateId, string $asOf): ProjectionResult
    {
        $this->candidateOrFail($tenantId, $candidateId);

        $items = CandidateEvaluation::query()
            ->where('tenant_id', $tenantId)
            ->where('candidate_id', $candidateId)
            ->orderByDesc('id')
            ->get()
            ->map(function (CandidateEvaluation $evaluation) use ($tenantId, $actorUserId): array {
                $this->auditSubresourceRead($tenantId, $actorUserId, 'candidate_evaluation', (int) $evaluation->id, 'recruitment.evaluation.manage', ['rating', 'strengths', 'concerns', 'comments']);

                return $this->evaluationData($evaluation);
            })
            ->values()
            ->all();

        return $this->projection($items, $asOf);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function createEvaluation(int $tenantId, int $actorUserId, int $candidateId, array $input): array
    {
        $this->candidateOrFail($tenantId, $candidateId);

        $evaluation = CandidateEvaluation::query()->create([
            'tenant_id' => $tenantId,
            'candidate_id' => $candidateId,
            'interview_id' => isset($input['interview_id']) ? (int) $input['interview_id'] : null,
            'evaluator_user_id' => $actorUserId,
            'rating' => (int) $input['rating'],
            'recommendation' => (string) $input['recommendation'],
            'strengths' => $this->nullableString($input['strengths'] ?? null),
            'concerns' => $this->nullableString($input['concerns'] ?? null),
            'comments' => $this->nullableString($input['comments'] ?? null),
            'status' => 'RECORDED',
            'recorded_at' => $this->clock->now(),
        ]);

        $this->audit->record($tenantId, $actorUserId, 'talent.candidate_evaluation.recorded', 'candidate_evaluation', (int) $evaluation->id, after: [
            'candidate_id' => $candidateId,
            'rating' => (int) $evaluation->rating,
            'recommendation' => (string) $evaluation->recommendation,
        ]);

        return $this->evaluationData($evaluation);
    }

    public function communications(int $tenantId, int $actorUserId, int $candidateId, string $asOf): ProjectionResult
    {
        $this->candidateOrFail($tenantId, $candidateId);

        $items = CandidateCommunication::query()
            ->where('tenant_id', $tenantId)
            ->where('candidate_id', $candidateId)
            ->orderByDesc('id')
            ->get()
            ->map(function (CandidateCommunication $communication) use ($tenantId, $actorUserId): array {
                $this->auditSubresourceRead($tenantId, $actorUserId, 'candidate_communication', (int) $communication->id, 'recruitment.communication.manage', ['subject', 'body', 'consent_basis']);

                return $this->communicationData($communication);
            })
            ->values()
            ->all();

        return $this->projection($items, $asOf);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function createCommunication(int $tenantId, int $actorUserId, int $candidateId, array $input): array
    {
        $this->candidateOrFail($tenantId, $candidateId);

        $communication = CandidateCommunication::query()->create([
            'tenant_id' => $tenantId,
            'candidate_id' => $candidateId,
            'channel' => (string) $input['channel'],
            'direction' => (string) $input['direction'],
            'subject' => $this->nullableString($input['subject'] ?? null),
            'body' => (string) $input['body'],
            'consent_basis' => (string) $input['consent_basis'],
            'sent_at' => $input['sent_at'] ?? null,
            'recorded_at' => $this->clock->now(),
        ]);

        $this->audit->record($tenantId, $actorUserId, 'talent.candidate_communication.logged', 'candidate_communication', (int) $communication->id, after: [
            'candidate_id' => $candidateId,
            'channel' => (string) $communication->channel,
            'direction' => (string) $communication->direction,
        ]);

        return $this->communicationData($communication);
    }

    public function talentPool(int $tenantId, int $actorUserId, int $candidateId, string $asOf): ProjectionResult
    {
        $this->candidateOrFail($tenantId, $candidateId);

        $items = TalentPoolTag::query()
            ->where('tenant_id', $tenantId)
            ->where('candidate_id', $candidateId)
            ->orderBy('tag')
            ->get()
            ->map(function (TalentPoolTag $tag) use ($tenantId, $actorUserId): array {
                $this->auditSubresourceRead($tenantId, $actorUserId, 'talent_pool_tag', (int) $tag->id, 'recruitment.talent-pool.manage', ['tag', 'reason', 'retention_until']);

                return $this->tagData($tag);
            })
            ->values()
            ->all();

        return $this->projection($items, $asOf);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function createTalentPoolTag(int $tenantId, int $actorUserId, int $candidateId, array $input): array
    {
        $this->candidateOrFail($tenantId, $candidateId);

        $tagValue = trim((string) $input['tag']);
        $exists = TalentPoolTag::query()
            ->where('tenant_id', $tenantId)
            ->where('candidate_id', $candidateId)
            ->where('tag', $tagValue)
            ->exists();

        if ($exists) {
            throw new ConflictException('The candidate already has this talent-pool tag.', [
                'reason' => 'TALENT_POOL_TAG_DUPLICATE',
            ]);
        }

        $tag = TalentPoolTag::query()->create([
            'tenant_id' => $tenantId,
            'candidate_id' => $candidateId,
            'tag' => $tagValue,
            'reason' => $this->nullableString($input['reason'] ?? null),
            'retention_until' => $input['retention_until'] ?? null,
            'status' => 'ACTIVE',
            'recorded_at' => $this->clock->now(),
        ]);

        $this->audit->record($tenantId, $actorUserId, 'talent.talent_pool_tag.created', 'talent_pool_tag', (int) $tag->id, after: [
            'candidate_id' => $candidateId,
            'tag' => (string) $tag->tag,
        ]);

        return $this->tagData($tag);
    }

    /**
     * Convert an accepted candidate into exactly one Person, Employment,
     * primary Assignment and conversion record. The conversion row is the
     * business idempotency key: a new Idempotency-Key still returns the same
     * employment and never creates a second row.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function convertCandidate(int $tenantId, int $actorUserId, int $candidateId, array $input): array
    {
        return DB::transaction(function () use ($tenantId, $actorUserId, $candidateId, $input): array {
            $candidate = Candidate::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($candidateId)
                ->lockForUpdate()
                ->first();

            if ($candidate === null) {
                throw NotFoundException::forResource();
            }

            $existing = CandidateConversion::query()
                ->where('tenant_id', $tenantId)
                ->where('candidate_id', $candidateId)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $this->conversionData($existing, true);
            }

            $offer = Offer::query()
                ->where('tenant_id', $tenantId)
                ->where('candidate_id', $candidateId)
                ->where('status', 'ACCEPTED')
                ->orderByDesc('version')
                ->first();

            if ($offer === null) {
                throw new ConflictException('Candidate conversion requires an accepted offer.', [
                    'reason' => 'CANDIDATE_NOT_ACCEPTED',
                ]);
            }

            $legalEntityId = (int) $input['legal_entity_id'];
            $orgUnitId = (int) $input['org_unit_id'];

            if ($this->organization->legalEntity($tenantId, $legalEntityId) === null) {
                throw new ValidationException(['legal_entity_id' => ['The legal entity does not exist in this tenant.']]);
            }

            $orgUnit = $this->organization->orgUnit($tenantId, $orgUnitId);

            if ($orgUnit === null || $orgUnit->legalEntityId !== $legalEntityId) {
                throw new ValidationException(['org_unit_id' => ['The org unit must belong to the selected legal entity.']]);
            }

            $hireDate = isset($input['hire_date']) && trim((string) $input['hire_date']) !== ''
                ? (string) $input['hire_date']
                : (string) $offer->start_date;

            $personId = $candidate->linked_person_id !== null
                ? (int) $candidate->linked_person_id
                : $this->people->createPerson($tenantId, array_filter([
                    'legal_name' => (string) $candidate->display_name,
                    'email' => $candidate->email,
                    'mobile' => $candidate->mobile_ciphertext,
                ], static fn (mixed $value): bool => $value !== null && $value !== ''), $actorUserId);

            $employmentId = $this->people->createEmployment($tenantId, [
                'person_id' => $personId,
                'legal_entity_id' => $legalEntityId,
                'employee_no' => (string) $input['employee_no'],
                'employment_type' => 'FULL_TIME',
                'status' => 'PROBATION',
                'valid_from' => $hireDate,
                'valid_to' => BusinessDate::OPEN_END,
                'hire_date' => $hireDate,
                'source_type' => 'CANDIDATE_CONVERSION',
                'source_id' => $candidateId,
            ], $actorUserId);

            $this->people->createAssignment($tenantId, $employmentId, [
                'org_unit_id' => $orgUnitId,
                'assignment_type' => 'PRIMARY',
                'is_primary' => true,
                'valid_from' => $hireDate,
                'valid_to' => BusinessDate::OPEN_END,
                'source_type' => 'CANDIDATE_CONVERSION',
                'source_id' => $candidateId,
            ], $actorUserId);

            $candidate->forceFill([
                'status' => 'CONVERTED',
                'linked_person_id' => $personId,
            ])->save();

            $conversion = CandidateConversion::query()->create([
                'tenant_id' => $tenantId,
                'candidate_id' => $candidateId,
                'employment_id' => $employmentId,
                'person_id' => $personId,
                'offer_id' => (int) $offer->id,
                'legal_entity_id' => $legalEntityId,
                'org_unit_id' => $orgUnitId,
                'employee_no' => (string) $input['employee_no'],
                'status' => 'COMPLETED',
                'recorded_at' => $this->clock->now(),
            ]);

            $this->audit->record($tenantId, $actorUserId, 'talent.candidate.converted', 'candidate_conversion', (int) $conversion->id, after: [
                'candidate_id' => $candidateId,
                'employment_id' => $employmentId,
                'person_id' => $personId,
                'offer_id' => (int) $offer->id,
                'legal_entity_id' => $legalEntityId,
            ]);

            return $this->conversionData($conversion, false);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function requisitionMetrics(int $tenantId, int $requisitionId): array
    {
        $openingsCount = RequisitionOpening::query()
            ->where('tenant_id', $tenantId)
            ->where('requisition_id', $requisitionId)
            ->count();

        $interviews = Interview::query()
            ->where('tenant_id', $tenantId)
            ->where('requisition_id', $requisitionId)
            ->get();

        $offers = Offer::query()
            ->where('tenant_id', $tenantId)
            ->where('requisition_id', $requisitionId)
            ->get();

        $offerIds = $offers->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();

        $conversions = $offerIds === []
            ? collect()
            : CandidateConversion::query()
                ->where('tenant_id', $tenantId)
                ->whereIn('offer_id', $offerIds)
                ->get();

        $candidateIds = $interviews->pluck('candidate_id')
            ->merge($offers->pluck('candidate_id'))
            ->unique()
            ->count();

        $acceptedOffers = $offers->filter(static fn (Offer $offer): bool => (string) $offer->status === 'ACCEPTED');
        $acceptedOfferMinor = (int) $acceptedOffers
            ->sum(static fn (Offer $offer): int => (int) $offer->base_salary_minor);

        $hiredCount = $conversions->count();

        $timeToHireDays = null;

        if ($conversions->isNotEmpty()) {
            $opening = RequisitionOpening::query()
                ->where('tenant_id', $tenantId)
                ->where('requisition_id', $requisitionId)
                ->orderBy('published_at')
                ->first();

            $publishedAt = $opening?->published_at;

            if ($publishedAt !== null) {
                $timeToHireDays = (int) $conversions->map(static fn (CandidateConversion $conversion): int => max(
                    0,
                    (int) CarbonImmutable::parse((string) $publishedAt)->diffInDays(CarbonImmutable::parse((string) $conversion->recorded_at)),
                ))->avg();
            }
        }

        return [
            'openings_count' => $openingsCount,
            'candidate_count' => $candidateIds,
            'interview_count' => $interviews->count(),
            'offer_count' => $offers->count(),
            'accepted_offers' => $acceptedOffers->count(),
            'accepted_offer_minor' => $acceptedOfferMinor,
            'hired_count' => $hiredCount,
            'time_to_hire_days' => $timeToHireDays,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function importRow(int $tenantId, int $actorUserId, array $row, int $rowNumber): array
    {
        $errors = [];

        foreach (['display_name', 'email', 'mobile', 'source'] as $field) {
            $value = $row[$field] ?? null;

            if ($value !== null && ! is_string($value)) {
                $errors[$field][] = "The {$field} must be a string.";
            }
        }

        $displayName = is_string($row['display_name'] ?? null) ? trim($row['display_name']) : '';
        $email = is_string($row['email'] ?? null) ? mb_strtolower(trim($row['email'])) : '';
        $mobile = is_string($row['mobile'] ?? null) ? trim($row['mobile']) : '';
        $source = is_string($row['source'] ?? null) ? trim($row['source']) : '';

        if ($displayName === '') {
            $errors['display_name'][] = 'The display name is required.';
        } elseif (mb_strlen($displayName) > 120) {
            $errors['display_name'][] = 'The display name may not be greater than 120 characters.';
        }

        if ($email !== '' && mb_strlen($email) > 254) {
            $errors['email'][] = 'The email may not be greater than 254 characters.';
        } elseif ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'][] = 'The email must be a valid email address.';
        }

        if ($mobile !== '' && mb_strlen($mobile) > 40) {
            $errors['mobile'][] = 'The mobile number may not be greater than 40 characters.';
        }

        if ($source !== '' && mb_strlen($source) > 64) {
            $errors['source'][] = 'The source may not be greater than 64 characters.';
        }

        if ($errors !== []) {
            return $this->importReport($rowNumber, 'REJECTED', $errors, null, 'ROW_VALIDATION_FAILED');
        }

        $emailHash = $email !== '' ? hash_hmac('sha256', $email, (string) config('hrms.pii_hmac_key')) : null;
        $mobileHash = $mobile !== '' ? hash_hmac('sha256', $mobile, (string) config('hrms.pii_hmac_key')) : null;
        $existing = Candidate::query()
            ->where('tenant_id', $tenantId)
            ->where(function ($query) use ($emailHash, $mobileHash): void {
                if ($emailHash !== null) {
                    $query->where('email_hash', $emailHash);
                }

                if ($mobileHash !== null) {
                    if ($emailHash !== null) {
                        $query->orWhere('mobile_hash', $mobileHash);
                    } else {
                        $query->where('mobile_hash', $mobileHash);
                    }
                }
            })
            ->first();

        if ($existing !== null) {
            return $this->importReport($rowNumber, 'REJECTED', [], (int) $existing->id, 'CANDIDATE_DUPLICATE');
        }

        $candidate = Candidate::query()->create([
            'tenant_id' => $tenantId,
            'display_name' => $displayName,
            'email' => $email !== '' ? $email : null,
            'mobile_ciphertext' => $mobile !== '' ? $mobile : null,
            'email_hash' => $emailHash,
            'mobile_hash' => $mobileHash,
            'source' => $source !== '' ? $source : null,
            'status' => 'NEW',
            'recorded_at' => $this->clock->now(),
        ]);

        $this->audit->record($tenantId, $actorUserId, 'talent.candidate.imported', 'candidate', (int) $candidate->id, after: [
            'has_email' => $emailHash !== null,
            'has_mobile' => $mobileHash !== null,
            'import_row' => $rowNumber,
        ]);

        return $this->importReport($rowNumber, 'ACCEPTED', [], (int) $candidate->id, null);
    }

    /**
     * @param  array<string, list<string>>  $errors
     * @return array<string, mixed>
     */
    private function importReport(int $rowNumber, string $status, array $errors, ?int $candidateId, ?string $reason): array
    {
        return [
            'row_number' => $rowNumber,
            'status' => $status,
            'reason' => $reason,
            'errors' => $errors,
            'candidate_id' => $candidateId,
        ];
    }

    private function candidateOrFail(int $tenantId, int $candidateId): Candidate
    {
        $candidate = Candidate::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($candidateId)
            ->first();

        if ($candidate === null) {
            throw NotFoundException::forResource();
        }

        return $candidate;
    }

    private function requisitionOrFail(int $tenantId, int $requisitionId): Requisition
    {
        $requisition = Requisition::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($requisitionId)
            ->first();

        if ($requisition === null) {
            throw new ValidationException(['requisition_id' => ['The requisition does not exist in this tenant.']]);
        }

        return $requisition;
    }

    /**
     * @param  list<string>  $sensitiveFields
     */
    private function auditCandidateRead(int $tenantId, int $actorUserId, Candidate $candidate, string $capability, array $sensitiveFields): void
    {
        $present = [];

        if ($candidate->email_hash !== null) {
            $present[] = 'email';
        }

        if ($candidate->mobile_hash !== null) {
            $present[] = 'mobile';
        }

        $fields = array_values(array_intersect($sensitiveFields, $present));

        if ($fields !== []) {
            $this->sensitiveAuditor->record($tenantId, $actorUserId, 'candidate', (string) $candidate->id, $capability, $fields, ['access' => 'LIST', 'minimized' => true]);
        }
    }

    /**
     * @param  list<string>  $sensitiveFields
     */
    private function auditSubresourceRead(int $tenantId, int $actorUserId, string $resourceType, int $resourceId, string $capability, array $sensitiveFields): void
    {
        $this->sensitiveAuditor->record($tenantId, $actorUserId, $resourceType, (string) $resourceId, $capability, $sensitiveFields, ['access' => 'LIST']);
    }

    /**
     * @return array<string, mixed>
     */
    private function evaluationData(CandidateEvaluation $evaluation): array
    {
        return [
            'evaluation_id' => (int) $evaluation->id,
            'candidate_id' => (int) $evaluation->candidate_id,
            'interview_id' => $evaluation->interview_id !== null ? (int) $evaluation->interview_id : null,
            'evaluator_user_id' => (int) $evaluation->evaluator_user_id,
            'rating' => (int) $evaluation->rating,
            'recommendation' => (string) $evaluation->recommendation,
            'strengths' => $evaluation->strengths,
            'concerns' => $evaluation->concerns,
            'comments' => $evaluation->comments,
            'status' => (string) $evaluation->status,
            'recorded_at' => $this->iso($evaluation->recorded_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function communicationData(CandidateCommunication $communication): array
    {
        return [
            'communication_id' => (int) $communication->id,
            'candidate_id' => (int) $communication->candidate_id,
            'channel' => (string) $communication->channel,
            'direction' => (string) $communication->direction,
            'subject' => $communication->subject,
            'body' => (string) $communication->body,
            'consent_basis' => (string) $communication->consent_basis,
            'sent_at' => $this->iso($communication->sent_at),
            'recorded_at' => $this->iso($communication->recorded_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function tagData(TalentPoolTag $tag): array
    {
        return [
            'tag_id' => (int) $tag->id,
            'candidate_id' => (int) $tag->candidate_id,
            'tag' => (string) $tag->tag,
            'reason' => $tag->reason,
            'retention_until' => $tag->retention_until,
            'status' => (string) $tag->status,
            'recorded_at' => $this->iso($tag->recorded_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function conversionData(CandidateConversion $conversion, bool $businessReplayed): array
    {
        return [
            'conversion_id' => (int) $conversion->id,
            'candidate_id' => (int) $conversion->candidate_id,
            'employment_id' => (int) $conversion->employment_id,
            'person_id' => (int) $conversion->person_id,
            'offer_id' => (int) $conversion->offer_id,
            'legal_entity_id' => (int) $conversion->legal_entity_id,
            'org_unit_id' => (int) $conversion->org_unit_id,
            'employee_no' => (string) $conversion->employee_no,
            'status' => (string) $conversion->status,
            'converted_at' => $this->iso($conversion->recorded_at),
            'business_replayed' => $businessReplayed,
        ];
    }

    private function rateBps(int $numerator, int $denominator): int
    {
        if ($denominator <= 0) {
            return 0;
        }

        return (int) min(10000, intdiv($numerator * 10000, $denominator));
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

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }
}
