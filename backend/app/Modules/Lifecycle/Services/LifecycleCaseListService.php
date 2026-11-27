<?php

declare(strict_types=1);

namespace App\Modules\Lifecycle\Services;

use App\Modules\Lifecycle\Domain\LifecycleEffectiveDate;
use App\Modules\Lifecycle\Domain\LifecycleMovementKind;
use App\Modules\Lifecycle\Models\LifecycleIntent;
use App\Modules\People\Contracts\AssignmentDirectory;
use App\Modules\Workflow\Contracts\WorkflowCaseGateway;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Time\BusinessDate;
use App\Shared\Time\CompanyBusinessDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read model for the unified lifecycle ledger. One endpoint returns all
 * workflow-backed movement kinds plus the People-owned secondment projection.
 *
 * Every filter is applied server-side before paging. Lifecycle owns intents,
 * Workflow owns application/effect state (read through its gateway), and People
 * owns employee and assignment facts (read through its contracts). No
 * cross-module table is queried and no missing projection is invented.
 */
final class LifecycleCaseListService
{
    private const OPEN_STATUSES = ['DRAFT', 'SUBMITTED', 'IN_REVIEW'];

    public function __construct(
        private readonly WorkflowCaseGateway $workflow,
        private readonly LifecycleLedgerDirectory $directory,
        private readonly AssignmentDirectory $assignments,
        private readonly CompanyBusinessDate $businessDates,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array{items: list<array<string, mixed>>, page: int, page_size: int, total: int, partial: bool, partial_reason: string|null, as_of: string}
     */
    public function paginate(
        array $filters,
        int $page,
        int $pageSize,
        ?string $asOf = null,
        ?int $viewerUserId = null,
    ): array {
        $page = max(1, $page);
        $pageSize = min(100, max(1, $pageSize));
        $asOf = $this->asOf($asOf);
        $effectiveFrom = $this->optionalDate($filters['effective_from'] ?? null, 'effective_from');
        $effectiveTo = $this->optionalDate($filters['effective_to'] ?? null, 'effective_to');

        if ($effectiveFrom !== null && $effectiveTo !== null && $effectiveFrom > $effectiveTo) {
            throw new ValidationException(
                ['effective_to' => ['effective_to must be on or after effective_from.']],
                'The effective date range is invalid.',
                ['reason' => 'EFFECTIVE_DATE_RANGE_INVALID'],
            );
        }

        $status = isset($filters['status']) && $filters['status'] !== ''
            ? strtoupper(trim((string) $filters['status']))
            : null;
        $typeFilter = $filters['case_type'] ?? $filters['type'] ?? null;
        $typeFilter = is_string($typeFilter) && $typeFilter !== '' && strtolower($typeFilter) !== 'all'
            ? $typeFilter
            : null;
        $kind = isset($filters['kind']) && $filters['kind'] !== ''
            ? strtolower(trim((string) $filters['kind']))
            : null;
        $kind = $kind === 'all' ? null : $kind;
        $employmentId = isset($filters['employment_id']) && (int) $filters['employment_id'] > 0
            ? (int) $filters['employment_id']
            : null;
        $q = isset($filters['q']) && trim((string) $filters['q']) !== '' ? trim((string) $filters['q']) : null;

        $caseType = null;

        if ($typeFilter !== null) {
            $caseType = LifecycleMovementKind::caseTypeForFilter($typeFilter);

            if ($caseType === null && strtoupper($typeFilter) !== 'SECONDMENT') {
                throw new ValidationException(['case_type' => ['The case_type filter is not a lifecycle movement type.']]);
            }
        }

        if ($kind !== null && ! in_array($kind, ['onboarding', 'probation', 'transfer', 'salary', 'secondment', 'offboarding'], true)) {
            throw new ValidationException(['kind' => ['The kind filter is not a lifecycle movement kind.']]);
        }

        $secondmentOnly = $kind === 'secondment'
            || ($typeFilter !== null && strtoupper($typeFilter) === 'SECONDMENT');
        $includeSecondments = ! $secondmentOnly
            && $caseType === null
            && ($kind === null || $kind === 'secondment');

        if ($kind !== null && $kind !== 'secondment' && $caseType === null) {
            $caseType = LifecycleMovementKind::caseTypeForFilter($kind);
        }

        if ($secondmentOnly) {
            return $this->secondmentPage($status, $employmentId, $q, $effectiveFrom, $effectiveTo, $page, $pageSize, $asOf);
        }

        // Workflow owns status. Resolve the matching case ids through the
        // gateway, then push that set into the intent query before count/page.
        $statusCaseIds = $status !== null ? $this->caseIdsWithStatus($status) : null;

        if ($statusCaseIds === [] && ! $includeSecondments) {
            return $this->emptyPage($page, $pageSize, $asOf);
        }

        $matchingEmployments = $q !== null ? $this->matchingEmploymentIds($q, $asOf) : [];

        // A single movement type is a pure SQL-paged intent query. The "all"
        // view merges two separately filtered server projections (Lifecycle
        // intents + People secondments), then sorts and slices once so the
        // returned total/page describe the complete unified set.
        if (! $includeSecondments) {
            $query = $this->intentQuery($caseType, $employmentId, $effectiveFrom, $effectiveTo, $q, $matchingEmployments, $statusCaseIds);
            $total = (int) (clone $query)->count();
            $intents = $query->forPage($page, $pageSize)->get();
            $rows = [];
            $partial = false;
            $reasons = [];

            foreach ($intents as $intent) {
                [$row, $rowPartial, $reason] = $this->intentRow($intent, $asOf, $viewerUserId);
                $rows[] = $row;
                $partial = $partial || $rowPartial;

                if ($reason !== null) {
                    $reasons[] = $reason;
                }
            }

            return [
                'items' => $rows,
                'page' => $page,
                'page_size' => $pageSize,
                'total' => $total,
                'partial' => $partial,
                'partial_reason' => $partial ? implode('；', array_values(array_unique($reasons))) : null,
                'as_of' => $asOf,
            ];
        }

        $query = $this->intentQuery($caseType, $employmentId, $effectiveFrom, $effectiveTo, $q, $matchingEmployments, $statusCaseIds);
        $offset = ($page - 1) * $pageSize;
        $intentTotal = (int) (clone $query)->count();
        $secondments = $this->secondmentRows($status, $employmentId, $q, $effectiveFrom, $effectiveTo, $asOf);
        $rows = [];
        $partial = false;
        $reasons = [];

        // Two server projections share one ordering. Read only the prefix that
        // can contribute to this page, then sort/slice once on the server; no
        // frontend slicing and no full intent-table materialisation.
        foreach ($query->orderByDesc('planned_effective_on')->orderByDesc('id')->limit($offset + $pageSize)->get() as $intent) {
            [$row, $rowPartial, $reason] = $this->intentRow($intent, $asOf, $viewerUserId);
            $rows[] = $row;
            $partial = $partial || $rowPartial;

            if ($reason !== null) {
                $reasons[] = $reason;
            }
        }

        $rows = [...$rows, ...$secondments];
        usort($rows, $this->compareRows(...));

        return [
            'items' => array_slice($rows, $offset, $pageSize),
            'page' => $page,
            'page_size' => $pageSize,
            'total' => $intentTotal + count($secondments),
            'partial' => $partial,
            'partial_reason' => $partial ? implode('；', array_values(array_unique($reasons))) : null,
            'as_of' => $asOf,
        ];
    }

    /**
     * @param  list<int>  $matchingEmployments
     * @param  list<int>|null  $statusCaseIds
     * @return Builder<LifecycleIntent>
     */
    private function intentQuery(
        ?string $caseType,
        ?int $employmentId,
        ?string $effectiveFrom,
        ?string $effectiveTo,
        ?string $q,
        array $matchingEmployments,
        ?array $statusCaseIds,
    ): Builder {
        return LifecycleIntent::query()
            ->whereIn('case_type', LifecycleMovementKind::LEDGER_CASE_TYPES)
            ->when($caseType !== null, fn (Builder $builder): Builder => $builder->where('case_type', $caseType))
            ->when($employmentId !== null, function (Builder $builder) use ($employmentId): void {
                $builder->where(function (Builder $inner) use ($employmentId): void {
                    $inner->where('employment_id', $employmentId)
                        ->orWhere('result_employment_id', $employmentId);
                });
            })
            ->when($effectiveFrom !== null, fn (Builder $builder): Builder => $builder->where('planned_effective_on', '>=', $effectiveFrom))
            ->when($effectiveTo !== null, fn (Builder $builder): Builder => $builder->where('planned_effective_on', '<=', $effectiveTo))
            ->when($statusCaseIds !== null, fn (Builder $builder): Builder => $builder->whereIn('case_id', $statusCaseIds))
            ->when($q !== null, function (Builder $builder) use ($q, $matchingEmployments): void {
                $term = '%'.$q.'%';
                $builder->where(function (Builder $inner) use ($term, $matchingEmployments): void {
                    $inner->where('intent_json->employee_no', 'like', $term)
                        ->orWhere('intent_json->person->legal_name', 'like', $term);

                    if ($matchingEmployments !== []) {
                        $inner->orWhereIn('employment_id', $matchingEmployments);
                    }
                });
            });
    }

    /**
     * @return array{0: array<string, mixed>, 1: bool, 2: string|null}
     */
    private function intentRow(LifecycleIntent $intent, string $asOf, ?int $viewerUserId): array
    {
        $case = $this->workflow->projection((int) $intent->case_id);
        $partial = false;
        $reason = null;

        if ($case === null) {
            $fallback = $this->workflow->caseFor((int) $intent->case_id);
            $case = $fallback !== null ? [
                'id' => $fallback->id,
                'case_no' => $fallback->caseNo,
                'title' => $fallback->title,
                'application_status' => $fallback->applicationStatus,
                'effect_status' => $fallback->effectStatus,
                'business_date' => $fallback->businessDate,
                'submitted_at' => null,
                'requester_user_id' => null,
                'requester_display_name' => null,
                'current_assignee_user_id' => null,
                'current_assignee_display_name' => null,
                'is_awaiting_me' => false,
            ] : null;
            $partial = true;
            $reason = '部分案例的工作流投影暂不可用';
        }

        $employmentId = $intent->employment_id !== null
            ? (int) $intent->employment_id
            : ($intent->result_employment_id !== null ? (int) $intent->result_employment_id : null);
        $employee = $employmentId !== null
            ? $this->directory->employee($employmentId, $asOf)
            : ['employee_no' => '', 'employee_name' => '', 'status' => '', 'valid_from' => null];
        $payload = (array) ($intent->intent_json ?? []);
        $effectiveDate = $intent->planned_effective_on !== null
            ? (string) $intent->planned_effective_on
            : LifecycleEffectiveDate::forPayload((string) $intent->case_type, $payload);
        $applicationStatus = is_array($case) ? (string) ($case['application_status'] ?? $intent->status) : (string) $intent->status;
        $effectStatus = is_array($case) ? (string) ($case['effect_status'] ?? 'NONE') : 'NONE';
        $employeeName = $employee['employee_name'];

        if ($employeeName === '') {
            $person = isset($payload['person']) && is_array($payload['person']) ? (array) $payload['person'] : [];
            $employeeName = is_string($person['legal_name'] ?? null) ? (string) $person['legal_name'] : '';
        }

        $requesterUserId = is_array($case) && is_numeric($case['requester_user_id'] ?? null)
            ? (int) $case['requester_user_id']
            : null;
        $currentAssignee = is_array($case) && is_numeric($case['current_assignee_user_id'] ?? null)
            ? (int) $case['current_assignee_user_id']
            : null;
        // Workflow's own projection is authoritative for the current viewer;
        // the id comparison is only a fallback for an older projection that
        // omits the boolean. It must never turn an unrelated case into a todo.
        $isAwaitingMe = in_array($applicationStatus, self::OPEN_STATUSES, true)
            && (($case['is_awaiting_me'] ?? false) === true
                || ($viewerUserId !== null && $currentAssignee === $viewerUserId));

        return [[
            'row_id' => 'case-'.(int) $intent->case_id,
            'case_id' => (int) $intent->case_id,
            'case_no' => is_array($case) ? ($case['case_no'] ?? null) : null,
            'title' => is_array($case) ? ($case['title'] ?? null) : null,
            'case_type' => (string) $intent->case_type,
            'kind' => LifecycleMovementKind::forCaseType((string) $intent->case_type),
            'domain_status' => (string) $intent->status,
            'application_status' => $applicationStatus,
            'effect_status' => $effectStatus,
            'lane' => $this->lane($applicationStatus, $effectStatus, $effectiveDate, $asOf),
            'subject_type' => (string) $intent->subject_type,
            'subject_id' => $intent->subject_id !== null ? (int) $intent->subject_id : null,
            'employment_id' => $intent->employment_id !== null ? (int) $intent->employment_id : null,
            'employee_id' => $employmentId,
            'result_employment_id' => $intent->result_employment_id !== null ? (int) $intent->result_employment_id : null,
            'employee_name' => $employeeName,
            'employee_no' => $employee['employee_no'] !== ''
                ? $employee['employee_no']
                : (is_string($payload['employee_no'] ?? null) ? (string) $payload['employee_no'] : ''),
            'employee_status' => $employee['status'],
            'effective_date' => $effectiveDate,
            'effective_at' => $this->iso($intent->effective_at),
            'planned_effective_on' => $intent->planned_effective_on !== null ? (string) $intent->planned_effective_on : null,
            'submitted_at' => is_array($case) ? ($case['submitted_at'] ?? null) : null,
            'recorded_at' => $this->iso($intent->recorded_at),
            'requester_user_id' => $requesterUserId,
            'requester_display_name' => is_array($case) ? ($case['requester_display_name'] ?? null) : null,
            'current_assignee_user_id' => $currentAssignee,
            'current_assignee_display_name' => is_array($case) ? ($case['current_assignee_display_name'] ?? null) : null,
            'is_awaiting_me' => $isAwaitingMe,
            'assignment_id' => null,
            'partial' => $partial,
        ], $partial, $reason];
    }

    /**
     * @return array{items: list<array<string, mixed>>, page: int, page_size: int, total: int, partial: bool, partial_reason: string|null, as_of: string}
     */
    private function secondmentPage(
        ?string $status,
        ?int $employmentId,
        ?string $q,
        ?string $effectiveFrom,
        ?string $effectiveTo,
        int $page,
        int $pageSize,
        string $asOf,
    ): array {
        $rows = $this->secondmentRows($status, $employmentId, $q, $effectiveFrom, $effectiveTo, $asOf);
        usort($rows, $this->compareRows(...));
        $total = count($rows);

        return [
            'items' => array_slice($rows, ($page - 1) * $pageSize, $pageSize),
            'page' => $page,
            'page_size' => $pageSize,
            'total' => $total,
            'partial' => false,
            'partial_reason' => null,
            'as_of' => $asOf,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function secondmentRows(
        ?string $status,
        ?int $employmentId,
        ?string $q,
        ?string $effectiveFrom,
        ?string $effectiveTo,
        string $asOf,
    ): array {
        $rows = [];

        foreach ($this->assignments->secondments($employmentId) as $occupant) {
            $employee = $this->directory->employee($occupant->employmentId, $asOf);
            $date = $occupant->validFrom;

            if (($effectiveFrom !== null && $date < $effectiveFrom) || ($effectiveTo !== null && $date > $effectiveTo)) {
                continue;
            }

            $applicationStatus = $date > $asOf
                ? 'SCHEDULED'
                : ($occupant->validTo <= $asOf ? 'COMPLETED' : 'ACTIVE');
            $effectStatus = $date > $asOf ? 'SCHEDULED' : 'APPLIED';

            if ($status !== null && $applicationStatus !== $status) {
                continue;
            }

            if ($q !== null) {
                $haystack = strtolower(implode(' ', [$occupant->assignmentId, $occupant->employeeNo, $occupant->personName, $occupant->assignmentType]));

                if (! str_contains($haystack, strtolower($q))) {
                    continue;
                }
            }

            $rows[] = [
                'row_id' => 'secondment-'.$occupant->assignmentId,
                'case_id' => null,
                'case_no' => null,
                'title' => '借调至组织 #'.$occupant->orgUnitId,
                'case_type' => 'SECONDMENT',
                'kind' => 'secondment',
                'domain_status' => $applicationStatus,
                'application_status' => $applicationStatus,
                'effect_status' => $effectStatus,
                'lane' => $date > $asOf ? 'SCHEDULED' : 'EFFECTIVE',
                'subject_type' => 'employment',
                'subject_id' => $occupant->employmentId,
                'employment_id' => $occupant->employmentId,
                'employee_id' => $occupant->employmentId,
                'result_employment_id' => null,
                'employee_name' => $occupant->personName,
                'employee_no' => $occupant->employeeNo,
                'employee_status' => $employee['status'],
                'effective_date' => $date,
                'effective_at' => null,
                'planned_effective_on' => $date,
                'submitted_at' => null,
                'recorded_at' => null,
                'requester_user_id' => null,
                'requester_display_name' => null,
                'current_assignee_user_id' => null,
                'current_assignee_display_name' => null,
                'is_awaiting_me' => false,
                'assignment_id' => $occupant->assignmentId,
                'valid_to' => $occupant->validTo,
                'partial' => false,
            ];
        }

        return $rows;
    }

    private function lane(string $applicationStatus, string $effectStatus, ?string $effectiveDate, string $asOf): string
    {
        if (in_array($applicationStatus, self::OPEN_STATUSES, true)) {
            return 'PENDING_APPROVAL';
        }

        if ($effectStatus === 'APPLIED' || $applicationStatus === 'ACTIVE') {
            return $effectiveDate !== null && $effectiveDate > $asOf ? 'SCHEDULED' : 'EFFECTIVE';
        }

        if (in_array($applicationStatus, ['APPROVED', 'SCHEDULED'], true) || $effectStatus === 'SCHEDULED') {
            return 'SCHEDULED';
        }

        return 'PENDING_APPROVAL';
    }

    /**
     * @return list<int>
     */
    private function caseIdsWithStatus(string $status): array
    {
        $ids = [];

        foreach (LifecycleIntent::query()
            ->whereIn('case_type', LifecycleMovementKind::LEDGER_CASE_TYPES)
            ->orderByDesc('id')
            ->pluck('case_id') as $caseId) {
            $case = $this->workflow->caseFor((int) $caseId);

            if ($case !== null && $case->applicationStatus === $status) {
                $ids[] = (int) $caseId;
            }
        }

        return $ids;
    }

    /**
     * @return list<int>
     */
    private function matchingEmploymentIds(string $q, string $asOf): array
    {
        $candidateIds = LifecycleIntent::query()
            ->whereIn('case_type', LifecycleMovementKind::LEDGER_CASE_TYPES)
            ->whereNotNull('employment_id')
            ->orderByDesc('id')
            ->limit(500)
            ->pluck('employment_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
        $matches = [];

        foreach ($candidateIds as $employmentId) {
            $employee = $this->directory->employee($employmentId, $asOf);
            $haystack = strtolower(trim($employee['employee_no'].' '.$employee['employee_name']));

            if ($haystack !== '' && str_contains($haystack, strtolower($q))) {
                $matches[] = $employmentId;
            }
        }

        return $matches;
    }

    /**
     * Sort newest effective date first, then a deterministic row id.
     *
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     */
    private function compareRows(array $left, array $right): int
    {
        return strcmp((string) ($right['effective_date'] ?? ''), (string) ($left['effective_date'] ?? ''))
            ?: strcmp((string) $right['row_id'], (string) $left['row_id']);
    }

    private function optionalDate(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) || BusinessDate::tryFrom($value) === null) {
            throw new ValidationException([$field => ['The '.$field.' field must be a real calendar date.']]);
        }

        return BusinessDate::from($value)->value();
    }

    private function asOf(?string $value): string
    {
        if ($value !== null && $value !== '' && BusinessDate::tryFrom($value) !== null) {
            return BusinessDate::from($value)->value();
        }

        return $this->businessDates->today()->value();
    }

    /**
     * @return array{items: list<array<string, mixed>>, page: int, page_size: int, total: int, partial: bool, partial_reason: string|null, as_of: string}
     */
    private function emptyPage(int $page, int $pageSize, string $asOf): array
    {
        return [
            'items' => [],
            'page' => $page,
            'page_size' => $pageSize,
            'total' => 0,
            'partial' => false,
            'partial_reason' => null,
            'as_of' => $asOf,
        ];
    }

    private function iso(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return CarbonImmutable::parse((string) $value)->toIso8601ZuluString();
    }
}
