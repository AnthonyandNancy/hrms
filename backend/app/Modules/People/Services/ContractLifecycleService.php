<?php

declare(strict_types=1);

namespace App\Modules\People\Services;

use App\Modules\IAM\Contracts\Principal;
use App\Modules\Lifecycle\Contracts\EmploymentContractVersionLedger;
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
use Carbon\CarbonImmutable;
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
    private const AMENDABLE_FIELDS = ['contract_type', 'end_date', 'signed_on', 'terms_summary', 'template_id'];

    public function __construct(
        private readonly Clock $clock,
        private readonly AuditRecorder $audit,
        private readonly EmploymentContractVersionLedger $versions,
    ) {}

    /**
     * @param  array{legal_entity_id?: int|null, status?: string|null}  $filters
     */
    public function contracts(
        Principal $principal,
        Authorizer $authorizer,
        string $asOf,
        array $filters,
        int $page,
        int $pageSize,
    ): ProjectionResult {
        $query = EmploymentContract::query()
            ->where('tenant_id', $principal->tenantId())
            ->when(($filters['legal_entity_id'] ?? null) !== null, fn ($builder) => $builder->where('legal_entity_id', (string) $filters['legal_entity_id']))
            ->when(($filters['status'] ?? null) !== null, fn ($builder) => $builder->where('status', (string) $filters['status']));

        // Contracts have no org column; scope the assignment rows instead so
        // SELF/MANAGED/ORG_UNIT/ORG_SUBTREE/LEGAL_ENTITY grants all reduce to a
        // set of visible employment ids before pagination.
        $visibleEmployments = Assignment::query()
            ->where('tenant_id', $principal->tenantId())
            ->where('valid_from', '<=', $asOf)
            ->where('valid_to', '>', $asOf)
            ->select('employment_id');

        $query->whereIn(
            'employment_id',
            $authorizer->constrain($visibleEmployments, $principal, 'people.contract.read', $asOf),
        );
        $total = (int) (clone $query)->count();
        $contracts = $query->orderByDesc('start_date')->orderByDesc('id')->forPage($page, $pageSize)->get();

        $employments = Employment::query()
            ->where('tenant_id', $principal->tenantId())
            ->whereIn('id', $contracts->pluck('employment_id')->map(static fn (mixed $id): int => (int) $id)->all())
            ->get()
            ->keyBy('id');
        $persons = Person::query()
            ->where('tenant_id', $principal->tenantId())
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

    public function amendmentHistory(int $tenantId, int $contractId, string $asOf, int $page, int $pageSize): ProjectionResult
    {
        $contract = $this->findContract($tenantId, $contractId);
        $history = $this->versions->history($tenantId, (int) $contract->id);
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
    public function amend(int $tenantId, int $contractId, array $data, int $actorUserId): array
    {
        return DB::transaction(function () use ($tenantId, $contractId, $data, $actorUserId): array {
            /** @var EmploymentContract $contract */
            $contract = EmploymentContract::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($contractId)
                ->lockForUpdate()
                ->first();

            if ($contract === null) {
                throw new NotFoundOrForbiddenException;
            }

            if (! in_array((string) $contract->status, ['ACTIVE', 'EXPIRING'], true)) {
                throw new ConflictException('Only an active contract can be amended.', ['reason' => 'CONTRACT_NOT_AMENDABLE']);
            }

            $effective = BusinessDate::from((string) $data['effective_date']);

            if ($effective->isBefore(BusinessDate::from((string) $contract->start_date))) {
                throw new ValidationException(['effective_date' => ['The effective date cannot be before the contract start date.']]);
            }

            if ($contract->end_date !== null && $effective->isAfter(BusinessDate::from((string) $contract->end_date))) {
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

            $before = $this->contractPayload($contract);
            $contract->forceFill($changes)->save();
            $after = $this->contractPayload($contract);
            $effectiveTo = $contract->end_date !== null ? (string) $contract->end_date : BusinessDate::OPEN_END;
            $version = $this->versions->append(
                tenantId: $tenantId,
                contractId: (int) $contract->id,
                employmentId: (int) $contract->employment_id,
                changeType: 'AMENDMENT',
                effectiveFrom: $effective->value(),
                effectiveTo: $effectiveTo,
                reason: (string) $data['reason'],
                snapshot: ['before' => $before, 'after' => $after, 'changes' => $changes],
                actorUserId: $actorUserId,
            );

            $this->audit->record(
                tenantId: $tenantId,
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
    public function terminate(int $tenantId, int $contractId, array $data, int $actorUserId): array
    {
        return DB::transaction(function () use ($tenantId, $contractId, $data, $actorUserId): array {
            /** @var EmploymentContract $contract */
            $contract = EmploymentContract::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($contractId)
                ->lockForUpdate()
                ->first();

            if ($contract === null) {
                throw new NotFoundOrForbiddenException;
            }

            if (! in_array((string) $contract->status, ['ACTIVE', 'EXPIRING'], true)) {
                throw new ConflictException('Only an active contract can be terminated.', ['reason' => 'CONTRACT_NOT_TERMINABLE']);
            }

            $effective = BusinessDate::from((string) $data['effective_date']);

            if ($effective->isBefore(BusinessDate::from((string) $contract->start_date))) {
                throw new ValidationException(['effective_date' => ['The termination date cannot be before the contract start date.']]);
            }

            if ($contract->end_date !== null && $effective->isAfter(BusinessDate::from((string) $contract->end_date))) {
                throw new ConflictException('The termination date is after the contract end date.', ['reason' => 'TERMINATION_AFTER_CONTRACT_END']);
            }

            $before = $this->contractPayload($contract);
            $contract->forceFill([
                'status' => 'TERMINATED',
                'terminated_on' => $effective->value(),
                'end_date' => $effective->value(),
            ])->save();
            $after = $this->contractPayload($contract);
            $version = $this->versions->append(
                tenantId: $tenantId,
                contractId: (int) $contract->id,
                employmentId: (int) $contract->employment_id,
                changeType: 'TERMINATION',
                effectiveFrom: $effective->value(),
                effectiveTo: $effective->value(),
                reason: (string) $data['reason'],
                snapshot: ['before' => $before, 'after' => $after],
                actorUserId: $actorUserId,
            );

            $this->audit->record(
                tenantId: $tenantId,
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

        $query = ContractTemplate::query()
            ->where('tenant_id', $principal->tenantId())
            ->when(($filters['code'] ?? null) !== null, fn ($builder) => $builder->where('code', strtoupper(trim((string) $filters['code']))))
            ->when(($filters['status'] ?? null) !== null, fn ($builder) => $builder->where('status', strtoupper((string) $filters['status'])));

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
    public function createTemplate(int $tenantId, array $data, int $actorUserId): array
    {
        return DB::transaction(function () use ($tenantId, $data, $actorUserId): array {
            $code = strtoupper(trim((string) $data['code']));
            $latest = ContractTemplate::query()
                ->where('tenant_id', $tenantId)
                ->where('code', $code)
                ->orderByDesc('version')
                ->lockForUpdate()
                ->first();

            /** @var ContractTemplate $template */
            $template = ContractTemplate::query()->create([
                'tenant_id' => $tenantId,
                'code' => $code,
                'name' => (string) $data['name'],
                'version' => $latest !== null ? (int) $latest->version + 1 : 1,
                'status' => 'DRAFT',
                'body' => (string) $data['body'],
                'published_at' => null,
            ]);

            $this->audit->record(
                tenantId: $tenantId,
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
    public function publishTemplate(int $tenantId, int $templateId, int $actorUserId): array
    {
        return DB::transaction(function () use ($tenantId, $templateId, $actorUserId): array {
            /** @var ContractTemplate $template */
            $template = ContractTemplate::query()
                ->where('tenant_id', $tenantId)
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
                ->where('tenant_id', $tenantId)
                ->where('code', (string) $template->code)
                ->where('status', 'PUBLISHED')
                ->update(['status' => 'ARCHIVED', 'updated_at' => $this->clock->now()]);

            $template->forceFill([
                'status' => 'PUBLISHED',
                'published_at' => $this->clock->now(),
            ])->save();

            $this->audit->record(
                tenantId: $tenantId,
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

    private function findContract(int $tenantId, int $contractId): EmploymentContract
    {
        $contract = EmploymentContract::query()
            ->where('tenant_id', $tenantId)
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
        $days = null;

        if ($contract->end_date !== null && $asOf !== null) {
            $days = (int) CarbonImmutable::parse($asOf, 'UTC')
                ->diffInDays(CarbonImmutable::parse((string) $contract->end_date, 'UTC'), false);
        }

        return [
            'id' => (int) $contract->id,
            'employment_id' => (int) $contract->employment_id,
            'legal_entity_id' => $contract->legal_entity_id !== null ? (int) $contract->legal_entity_id : null,
            'contract_no' => (string) $contract->contract_no,
            'contract_type' => (string) $contract->contract_type,
            'start_date' => (string) $contract->start_date,
            'end_date' => $contract->end_date,
            'signed_on' => $contract->signed_on,
            'terminated_on' => $contract->terminated_on,
            'status' => (string) $contract->status,
            'version' => (int) $contract->version,
            'previous_contract_id' => $contract->previous_contract_id !== null ? (int) $contract->previous_contract_id : null,
            'renewed_by_id' => $contract->renewed_by_id !== null ? (int) $contract->renewed_by_id : null,
            'template_id' => $contract->template_id !== null ? (int) $contract->template_id : null,
            'terms_summary' => $contract->terms_summary,
            'days_to_expiry' => $days,
        ];
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
