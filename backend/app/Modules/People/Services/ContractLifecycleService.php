<?php

declare(strict_types=1);

namespace App\Modules\People\Services;

use App\Modules\IAM\Contracts\Principal;
use App\Modules\Lifecycle\Contracts\EmploymentContractVersionLedger;
use App\Modules\People\Contracts\EmploymentContractView;
use App\Modules\People\Models\Assignment;
use App\Modules\People\Models\ContractTemplate;
use App\Modules\People\Models\Employment;
use App\Modules\People\Models\EmploymentContract;
use App\Modules\People\Models\Person;
use App\Shared\Authorization\Authorizer;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Observability\AuditRecorder;
use App\Shared\Projections\ProjectionResult;
use App\Shared\Time\BusinessDate;
use App\Shared\Time\Clock;
use App\Shared\Time\CompanyBusinessDate;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Contract lifecycle commands and projections.
 *
 * Contract rows may change their effective end/status in place, but history is
 * append-only: every amendment and termination appends one row through the
 * Lifecycle-owned version ledger port. Templates are versioned by code; a
 * publish archives the previous published version rather than rewriting it.
 */
final class ContractLifecycleService
{
    private const AMENDABLE_FIELDS = ['contract_type', 'end_date', 'signed_on', 'terms_summary', 'template_id', 'work_location', 'probation_end_date'];

    public function __construct(
        private readonly Clock $clock,
        private readonly AuditRecorder $audit,
        private readonly EmploymentContractVersionLedger $versions,
        private readonly CompanyBusinessDate $businessDates,
    ) {}

    /**
     * @param  array{status?: string|null, q?: string|null, employment_id?: int|null, employee_id?: int|null, expiring_within_days?: int|null, expired?: bool|null}  $filters
     */
    public function contracts(
        Principal $principal,
        Authorizer $authorizer,
        string $asOf,
        array $filters,
        int $page,
        int $pageSize,
    ): ProjectionResult {
        $status = $filters['status'] ?? null;
        $employmentId = $filters['employment_id'] ?? null;
        $expiringWithinDays = $filters['expiring_within_days'] ?? null;
        $expiredOnly = $filters['expired'] ?? null;
        $term = trim((string) ($filters['q'] ?? ''));

        $query = EmploymentContract::query()
            ->when($status !== null && $status !== 'EXPIRED', fn ($builder) => $builder->where('status', (string) $status))
            ->when($status === 'EXPIRED', fn ($builder) => $builder
                ->whereIn('status', EmploymentContract::OPERATIONAL_STATUSES)
                ->whereNotNull('end_date')
                ->where('end_date', '<', $asOf))
            ->when($employmentId !== null && $employmentId > 0, fn ($builder) => $builder->where('employment_id', (int) $employmentId))
            ->when($expiredOnly === true, fn ($builder) => $builder
                ->whereIn('status', EmploymentContract::OPERATIONAL_STATUSES)
                ->whereNotNull('end_date')
                ->where('end_date', '<', $asOf))
            ->when($expiringWithinDays !== null, fn ($builder) => $builder
                ->whereIn('status', EmploymentContract::OPERATIONAL_STATUSES)
                ->whereNotNull('end_date')
                ->where('end_date', '>=', $asOf)
                ->where('end_date', '<=', BusinessDate::from($asOf)->addDays((int) $expiringWithinDays)->value()))
            ->when($term !== '', function ($builder) use ($term): void {
                $like = '%'.$term.'%';
                $builder->where(function ($nested) use ($like): void {
                    $nested->where('contract_no', 'like', $like)
                        ->orWhereIn(
                            'employment_id',
                            Employment::query()->where('employee_no', 'like', $like)->select('id'),
                        )
                        ->orWhereIn(
                            'employment_id',
                            Employment::query()
                                ->whereIn(
                                    'person_id',
                                    Person::query()->where('legal_name', 'like', $like)->select('id'),
                                )
                                ->select('id'),
                        );
                });
            });

        // Resolve row scope to concrete employment ids BEFORE filtering and
        // pagination. SELF/MANAGED/COMPANY bindings can constrain Employment
        // directly; ORG_UNIT/ORG_SUBTREE need Assignment's org_unit_id. Try the
        // employment-keyed shape first and fall back only when that shape is
        // invalid for the grant (e.g. an org predicate on a table without an
        // org column). No grant is ever widened to a feature-level allow.
        $visibleEmploymentIds = $this->visibleEmploymentIds($principal, $authorizer, $asOf);

        $query->whereIn('employment_id', $visibleEmploymentIds);
        $total = (int) (clone $query)->count();
        $contracts = $query->orderByDesc('start_date')->orderByDesc('id')->forPage($page, $pageSize)->get();

        $employments = Employment::query()
            ->whereIn('id', $contracts->pluck('employment_id')->map(static fn (mixed $id): int => (int) $id)->all())
            ->get()
            ->keyBy('id');
        $persons = Person::query()
            ->whereIn('id', $employments->pluck('person_id')->map(static fn (mixed $id): int => (int) $id)->unique()->all())
            ->get()
            ->keyBy('id');

        $items = $contracts->map(function (EmploymentContract $contract) use ($asOf, $employments, $persons): array {
            $employment = $employments->get((int) $contract->employment_id);
            $person = $employment !== null ? $persons->get((int) $employment->person_id) : null;

            return [
                ...$this->contractPayload($contract, $asOf),
                'employee_no' => $employment !== null ? (string) $employment->employee_no : null,
                'employee_name' => $person !== null ? (string) $person->legal_name : null,
            ];
        })->values()->all();

        return $this->projection($items, $page, $pageSize, $total, $asOf, [
            'resource' => 'employment_contracts',
            'filters' => array_filter($filters, static fn (mixed $value): bool => $value !== null && $value !== ''),
        ], $contracts->map(static fn (EmploymentContract $row): array => [(int) $row->id, (string) $row->updated_at])->all());
    }

    /**
     * @return list<int>
     */
    private function visibleEmploymentIds(Principal $principal, Authorizer $authorizer, string $asOf): array
    {
        try {
            $ids = $authorizer->constrain(
                Employment::query()->select('id'),
                $principal,
                'people.contract.read',
                $asOf,
            )->pluck('id');
        } catch (QueryException) {
            $ids = $authorizer->constrain(
                Assignment::query()
                    ->where('valid_from', '<=', $asOf)
                    ->where('valid_to', '>', $asOf)
                    ->select('employment_id'),
                $principal,
                'people.contract.read',
                $asOf,
            )->pluck('employment_id');
        }

        return $ids
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Activate a draft contract at its signed start date. A future activation
     * stays PENDING_EFFECTIVE until the company business date reaches it; no
     * scheduler is required for Payroll to resolve the date.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function activate(int $contractId, array $data, int $actorUserId): array
    {
        return DB::transaction(function () use ($contractId, $data, $actorUserId): array {
            /** @var EmploymentContract $contract */
            $contract = EmploymentContract::query()
                ->whereKey($contractId)
                ->lockForUpdate()
                ->first();

            if ($contract === null) {
                throw new NotFoundOrForbiddenException;
            }

            if (! in_array((string) $contract->status, ['DRAFT', 'PENDING_EFFECTIVE'], true)) {
                throw new ConflictException('Only a draft or pending contract can be activated.', [
                    'reason' => 'CONTRACT_NOT_ACTIVATABLE',
                    'status' => (string) $contract->status,
                ]);
            }

            $effective = BusinessDate::from((string) ($data['effective_from'] ?? $contract->start_date));

            if ($effective->value() !== (string) $contract->start_date) {
                throw new ValidationException([
                    'effective_from' => ['Activation must use the contract start date so signed terms are not rewritten.'],
                ]);
            }

            $this->assertNoPeriodOverlap($contract, $effective->value());

            $today = $this->businessDates->today()->value();
            $nextStatus = $effective->isAfter(BusinessDate::from($today))
                ? 'PENDING_EFFECTIVE'
                : 'ACTIVE';
            $before = $this->contractPayload($contract, $today);
            $contract->forceFill(['status' => $nextStatus])->save();
            $after = $this->contractPayload($contract, $today);
            $agreed = $this->agreedTerms($contractId, $data);

            $version = $this->versions->append(
                contractId: (int) $contract->id,
                employmentId: (int) $contract->employment_id,
                changeType: 'ACTIVATION',
                effectiveFrom: $effective->value(),
                effectiveTo: $contract->end_date !== null ? (string) $contract->end_date : BusinessDate::OPEN_END,
                reason: isset($data['reason']) ? (string) $data['reason'] : null,
                snapshot: ['before' => $before, 'after' => $after, ...$agreed],
                actorUserId: $actorUserId,
            );

            $this->audit->record(
                actorUserId: $actorUserId,
                action: 'people.contract.activated',
                aggregateType: 'employment_contract',
                aggregateId: (int) $contract->id,
                before: $before,
                after: $after,
                metadata: [
                    'version_id' => $version['id'],
                    'effective_from' => $effective->value(),
                    'activation_status' => $nextStatus,
                ],
            );

            return ['contract' => $after, 'version' => $version];
        });
    }

    private function assertNoPeriodOverlap(EmploymentContract $contract, string $effectiveFrom): void
    {
        $effectiveTo = $contract->end_date !== null ? (string) $contract->end_date : BusinessDate::OPEN_END;
        $overlap = EmploymentContract::query()
            ->where('employment_id', (int) $contract->employment_id)
            ->where('id', '!=', (int) $contract->id)
            ->whereIn('status', EmploymentContract::PERIOD_RESERVING_STATUSES)
            ->where('start_date', '<=', $effectiveTo)
            ->where(function ($query) use ($effectiveFrom): void {
                $query->whereNull('end_date')->orWhere('end_date', '>=', $effectiveFrom);
            })
            ->exists();

        if ($overlap) {
            throw new ConflictException('The contract period overlaps another effective contract.', [
                'reason' => 'CONTRACT_PERIOD_OVERLAP',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{agreed_salary_cents: int|null, agreed_currency: string|null}
     */
    private function agreedTerms(int $contractId, array $data = []): array
    {
        $history = $this->versions->history($contractId);
        $latest = $history !== [] ? $history[count($history) - 1] : [];
        $salary = $data['agreed_salary_cents'] ?? ($latest['agreed_salary_minor'] ?? null);
        $currency = $data['agreed_currency'] ?? ($latest['agreed_currency'] ?? null);

        return [
            'agreed_salary_cents' => $salary !== null ? (int) $salary : null,
            'agreed_currency' => $currency !== null ? strtoupper((string) $currency) : null,
        ];
    }

    public function amendmentHistory(int $contractId, string $asOf, int $page, int $pageSize): ProjectionResult
    {
        $contract = $this->findContract($contractId);
        $history = $this->versions->history((int) $contract->id);
        $total = count($history);
        $items = array_slice($history, max(0, ($page - 1) * $pageSize), $pageSize);

        return $this->projection($items, $page, $pageSize, $total, $asOf, [
            'resource' => 'employment_contract_versions',
            'filters' => ['contract_id' => $contractId],
        ], $history);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function amend(int $contractId, array $data, int $actorUserId): array
    {
        return DB::transaction(function () use ($contractId, $data, $actorUserId): array {
            /** @var EmploymentContract $contract */
            $contract = EmploymentContract::query()
                ->whereKey($contractId)
                ->lockForUpdate()
                ->first();

            if ($contract === null) {
                throw new NotFoundOrForbiddenException;
            }

            if (! in_array((string) $contract->status, EmploymentContract::OPERATIONAL_STATUSES, true)) {
                throw new ConflictException('Only an active contract can be amended.', ['reason' => 'CONTRACT_NOT_AMENDABLE']);
            }

            $effective = BusinessDate::from((string) $data['effective_date']);

            if ($effective->isBefore(BusinessDate::from((string) $contract->start_date))) {
                throw new ValidationException(['effective_date' => ['The effective date cannot be before the contract start date.']]);
            }

            // A contract whose end date already passed is effectively expired
            // even while its stored status is ACTIVE; it must not be amended or
            // terminated as if it were still operational.
            if ($contract->end_date !== null
                && BusinessDate::from((string) $contract->end_date)->isBefore(BusinessDate::from($this->clock->now()->toDateString()))) {
                throw new ConflictException('An expired contract cannot be changed.', ['reason' => 'CONTRACT_NOT_OPERATIONAL']);
            }

            if ($contract->end_date !== null
                && ! BusinessDate::from((string) $contract->end_date)->isOpenEnd()
                && $effective->isAfter(BusinessDate::from((string) $contract->end_date))) {
                throw new ValidationException(['effective_date' => ['The effective date cannot be after the current contract end date.']]);
            }

            /** @var array<string, mixed> $changes */
            $changes = $data['changes'];
            $unknown = array_values(array_diff(array_keys($changes), self::AMENDABLE_FIELDS));

            if ($unknown !== []) {
                throw new ValidationException(['changes' => ['Unsupported contract amendment field(s): '.implode(', ', $unknown).'.']]);
            }

            if ($changes === []) {
                throw new ValidationException(['changes' => ['At least one supported contract field must change.']]);
            }

            if (array_key_exists('contract_type', $changes) && ! in_array((string) $changes['contract_type'], EmploymentContract::TYPES, true)) {
                throw new ValidationException(['changes.contract_type' => ['The contract type is not supported.']]);
            }

            if (array_key_exists('end_date', $changes) && $changes['end_date'] !== null) {
                $end = BusinessDate::from((string) $changes['end_date']);

                if (! $end->isAfter($effective)) {
                    throw new ValidationException(['changes.end_date' => ['The amended end date must be after the effective date.']]);
                }
            }

            if (array_key_exists('probation_end_date', $changes) && $changes['probation_end_date'] !== null) {
                $probation = BusinessDate::from((string) $changes['probation_end_date']);
                if ($probation->isBefore(BusinessDate::from((string) $contract->start_date))) {
                    throw new ValidationException(['changes.probation_end_date' => ['The probation end date cannot be before the contract start date.']]);
                }
                $end = array_key_exists('end_date', $changes) && $changes['end_date'] !== null
                    ? BusinessDate::from((string) $changes['end_date'])
                    : ($contract->end_date !== null ? BusinessDate::from((string) $contract->end_date) : null);
                if ($end !== null && $probation->isAfter($end)) {
                    throw new ValidationException(['changes.probation_end_date' => ['The probation end date must fall inside the contract.']]);
                }
            }

            $before = $this->contractPayload($contract, $effective->value());
            $contract->forceFill($changes)->save();
            $after = $this->contractPayload($contract, $effective->value());
            $effectiveTo = $contract->end_date !== null ? (string) $contract->end_date : BusinessDate::OPEN_END;
            $version = $this->versions->append(
                contractId: (int) $contract->id,
                employmentId: (int) $contract->employment_id,
                changeType: 'AMENDMENT',
                effectiveFrom: $effective->value(),
                effectiveTo: $effectiveTo,
                reason: (string) $data['reason'],
                snapshot: ['before' => $before, 'after' => $after, 'changes' => $changes, ...$this->agreedTerms($contractId)],
                actorUserId: $actorUserId,
            );

            $this->audit->record(
                actorUserId: $actorUserId,
                action: 'people.contract.amended',
                aggregateType: 'employment_contract',
                aggregateId: (int) $contract->id,
                before: $before,
                after: $after,
                metadata: ['version_id' => $version['id'], 'effective_date' => $effective->value()],
            );

            return ['contract' => $after, 'version' => $version];
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function terminate(int $contractId, array $data, int $actorUserId): array
    {
        return DB::transaction(function () use ($contractId, $data, $actorUserId): array {
            /** @var EmploymentContract $contract */
            $contract = EmploymentContract::query()
                ->whereKey($contractId)
                ->lockForUpdate()
                ->first();

            if ($contract === null) {
                throw new NotFoundOrForbiddenException;
            }

            if (! in_array((string) $contract->status, EmploymentContract::OPERATIONAL_STATUSES, true)) {
                throw new ConflictException('Only an active contract can be terminated.', ['reason' => 'CONTRACT_NOT_TERMINABLE']);
            }

            $effective = BusinessDate::from((string) $data['effective_date']);

            if ($effective->isBefore(BusinessDate::from((string) $contract->start_date))) {
                throw new ValidationException(['effective_date' => ['The termination date cannot be before the contract start date.']]);
            }

            if ($contract->end_date !== null
                && BusinessDate::from((string) $contract->end_date)->isBefore(BusinessDate::from($this->clock->now()->toDateString()))) {
                throw new ConflictException('An expired contract cannot be terminated.', ['reason' => 'CONTRACT_NOT_OPERATIONAL']);
            }

            if ($contract->end_date !== null
                && ! BusinessDate::from((string) $contract->end_date)->isOpenEnd()
                && $effective->isAfter(BusinessDate::from((string) $contract->end_date))) {
                throw new ConflictException('The termination date is after the contract end date.', ['reason' => 'TERMINATION_AFTER_CONTRACT_END']);
            }

            $before = $this->contractPayload($contract, $effective->value());
            $contract->forceFill([
                'status' => 'TERMINATED',
                'terminated_on' => $effective->value(),
                'end_date' => $effective->value(),
            ])->save();
            $after = $this->contractPayload($contract, $effective->value());
            $version = $this->versions->append(
                contractId: (int) $contract->id,
                employmentId: (int) $contract->employment_id,
                changeType: 'TERMINATION',
                effectiveFrom: $effective->value(),
                effectiveTo: $effective->value(),
                reason: (string) $data['reason'],
                snapshot: ['before' => $before, 'after' => $after, ...$this->agreedTerms($contractId)],
                actorUserId: $actorUserId,
            );

            $this->audit->record(
                actorUserId: $actorUserId,
                action: 'people.contract.terminated',
                aggregateType: 'employment_contract',
                aggregateId: (int) $contract->id,
                before: $before,
                after: $after,
                metadata: ['version_id' => $version['id'], 'effective_date' => $effective->value()],
            );

            return ['contract' => $after, 'version' => $version];
        });
    }

    /**
     * Soft-delete a contract while preserving its version history. The unique
     * contract number remains reserved because the row is never removed.
     *
     * @return array<string, mixed>
     */
    public function softDelete(int $contractId, string $reason, int $actorUserId): array
    {
        return DB::transaction(function () use ($contractId, $reason, $actorUserId): array {
            /** @var EmploymentContract $contract */
            $contract = EmploymentContract::query()
                ->whereKey($contractId)
                ->lockForUpdate()
                ->first();

            if ($contract === null) {
                throw new NotFoundOrForbiddenException;
            }

            $before = $this->contractPayload($contract, $this->clock->now()->toDateString());
            $contract->forceFill([
                'deleted_by' => $actorUserId,
                'delete_reason' => $reason,
            ])->save();
            $contract->delete();

            $this->audit->record(
                actorUserId: $actorUserId,
                action: 'people.contract.soft_deleted',
                aggregateType: 'employment_contract',
                aggregateId: (int) $contract->id,
                before: $before,
                after: [...$before, 'deleted_at' => $contract->deleted_at?->toIso8601ZuluString()],
                metadata: ['reason' => $reason, 'version_history_preserved' => true],
            );

            return [...$before, 'deleted_at' => $contract->deleted_at?->toIso8601ZuluString(), 'delete_reason' => $reason];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function restore(int $contractId, int $actorUserId): array
    {
        return DB::transaction(function () use ($contractId, $actorUserId): array {
            /** @var EmploymentContract|null $contract */
            $contract = EmploymentContract::withTrashed()
                ->whereKey($contractId)
                ->lockForUpdate()
                ->first();

            if ($contract === null) {
                throw new NotFoundOrForbiddenException;
            }

            if ($contract->deleted_at === null) {
                throw new ConflictException('The contract is not deleted.', ['reason' => 'CONTRACT_NOT_DELETED']);
            }

            $contract->restore();
            $contract->forceFill(['deleted_by' => null, 'delete_reason' => null])->save();
            $after = $this->contractPayload($contract, $this->clock->now()->toDateString());

            $this->audit->record(
                actorUserId: $actorUserId,
                action: 'people.contract.restored',
                aggregateType: 'employment_contract',
                aggregateId: (int) $contract->id,
                after: $after,
            );

            return $after;
        });
    }

    /**
     * @param  array{code?: string|null, status?: string|null}  $filters
     */
    public function templates(
        Principal $principal,
        Authorizer $authorizer,
        string $asOf,
        array $filters,
        int $page,
        int $pageSize,
    ): ProjectionResult {
        if (! $authorizer->permits($principal, 'people.contract.template.manage', $asOf)
            && ! $authorizer->permits($principal, 'people.contract.read', $asOf)) {
            throw new NotFoundOrForbiddenException;
        }

        $term = trim((string) ($filters['q'] ?? ''));
        $query = ContractTemplate::query()
            ->when(($filters['code'] ?? null) !== null, fn ($builder) => $builder->where('code', strtoupper(trim((string) $filters['code']))))
            ->when(($filters['status'] ?? null) !== null, fn ($builder) => $builder->where('status', strtoupper((string) $filters['status'])))
            ->when($term !== '', fn ($builder) => $builder->where(function ($nested) use ($term): void {
                $like = '%'.$term.'%';
                $nested->where('code', 'like', $like)
                    ->orWhere('name', 'like', $like);
            }));

        $total = (int) (clone $query)->count();
        $templates = $query->orderBy('code')->orderByDesc('version')->orderByDesc('id')->forPage($page, $pageSize)->get();
        $items = $templates->map(fn (ContractTemplate $template): array => $this->templatePayload($template))->values()->all();

        return $this->projection($items, $page, $pageSize, $total, $asOf, [
            'resource' => 'contract_templates',
            'filters' => array_filter($filters, static fn (mixed $value): bool => $value !== null && $value !== ''),
        ], $templates->map(static fn (ContractTemplate $row): array => [(int) $row->id, (string) $row->updated_at])->all());
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function createTemplate(array $data, int $actorUserId): array
    {
        return DB::transaction(function () use ($data, $actorUserId): array {
            $code = strtoupper(trim((string) $data['code']));
            $latest = ContractTemplate::query()
                ->where('code', $code)
                ->orderByDesc('version')
                ->lockForUpdate()
                ->first();

            /** @var ContractTemplate $template */
            $template = ContractTemplate::query()->create([
                'code' => $code,
                'name' => (string) $data['name'],
                'version' => $latest !== null ? (int) $latest->version + 1 : 1,
                'status' => 'DRAFT',
                'body' => (string) $data['body'],
                'published_at' => null,
            ]);

            $this->audit->record(
                actorUserId: $actorUserId,
                action: 'people.contract.template.created',
                aggregateType: 'contract_template',
                aggregateId: (int) $template->id,
                after: ['code' => $code, 'version' => (int) $template->version, 'status' => 'DRAFT'],
            );

            return $this->templatePayload($template);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function publishTemplate(int $templateId, int $actorUserId): array
    {
        return DB::transaction(function () use ($templateId, $actorUserId): array {
            /** @var ContractTemplate $template */
            $template = ContractTemplate::query()
                ->whereKey($templateId)
                ->lockForUpdate()
                ->first();

            if ($template === null) {
                throw new NotFoundOrForbiddenException;
            }

            if ((string) $template->status !== 'DRAFT') {
                throw new ConflictException('Only a draft template can be published.', ['reason' => 'TEMPLATE_NOT_DRAFT']);
            }

            ContractTemplate::query()
                ->where('code', (string) $template->code)
                ->where('status', 'PUBLISHED')
                ->update(['status' => 'ARCHIVED', 'updated_at' => $this->clock->now()]);

            $template->forceFill([
                'status' => 'PUBLISHED',
                'published_at' => $this->clock->now(),
            ])->save();

            $this->audit->record(
                actorUserId: $actorUserId,
                action: 'people.contract.template.published',
                aggregateType: 'contract_template',
                aggregateId: (int) $template->id,
                after: [
                    'code' => (string) $template->code,
                    'version' => (int) $template->version,
                    'status' => 'PUBLISHED',
                ],
            );

            return $this->templatePayload($template);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>  $drilldown
     * @param  list<mixed>  $sourceRows
     */
    private function projection(array $items, int $page, int $pageSize, int $total, string $asOf, array $drilldown, array $sourceRows): ProjectionResult
    {
        return new ProjectionResult(
            items: $items,
            page: $page,
            pageSize: $pageSize,
            total: $total,
            asOf: $asOf,
            freshness: ProjectionResult::FRESHNESS_LIVE,
            sourceHash: hash('sha256', json_encode([$items, $sourceRows], JSON_THROW_ON_ERROR)),
            partial: false,
            partialReason: null,
            drilldown: $drilldown,
        );
    }

    private function findContract(int $contractId): EmploymentContract
    {
        $contract = EmploymentContract::query()
            ->whereKey($contractId)
            ->first();

        if ($contract === null) {
            throw new NotFoundOrForbiddenException;
        }

        return $contract;
    }

    /**
     * @return array<string, mixed>
     */
    public function contractPayload(EmploymentContract $contract, ?string $asOf = null): array
    {
        return EmploymentContractView::toArray($contract, $asOf ?? $this->clock->now()->toDateString());
    }

    /**
     * @return array<string, mixed>
     */
    public function templatePayload(ContractTemplate $template): array
    {
        return [
            'id' => (int) $template->id,
            'code' => (string) $template->code,
            'name' => (string) $template->name,
            'version' => (int) $template->version,
            'status' => (string) $template->status,
            'body' => (string) $template->body,
            'published_at' => $template->published_at?->toIso8601ZuluString(),
        ];
    }
}
