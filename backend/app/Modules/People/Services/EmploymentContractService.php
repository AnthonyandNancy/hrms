<?php

declare(strict_types=1);

namespace App\Modules\People\Services;

use App\Modules\People\Contracts\EmploymentContractView;
use App\Modules\People\Models\Employment;
use App\Modules\People\Models\EmploymentContract;
use App\Modules\People\Models\Person;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Observability\AuditRecorder;
use App\Shared\Time\BusinessDate;
use App\Shared\Time\Clock;
use App\Shared\Time\CompanyBusinessDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Versioned labour-contract ledger. A renewal creates a new row and marks the
 * current one RENEWED; it never overwrites history.
 */
final class EmploymentContractService
{
    public function __construct(
        private readonly Clock $clock,
        private readonly AuditRecorder $audit,
        private readonly CompanyBusinessDate $businessDates,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function list(int $employmentId): array
    {
        $asOf = $this->businessDates->today()->value();
        $employment = Employment::query()->whereKey($employmentId)->first();
        $person = $employment instanceof Employment
            ? Person::query()->whereKey($employment->person_id)->first()
            : null;

        return EmploymentContract::query()
            ->where('employment_id', $employmentId)
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (EmploymentContract $contract): array => EmploymentContractView::toArray(
                $contract,
                $asOf,
                $employment,
                $person,
            ))
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    /**
     * @param  array<string, mixed>  $data
     * @param  (callable(array<string, mixed>): void)|null  $afterOpen
     *                                                                  Invoked inside the same transaction so the contract row and its
     *                                                                  creation version commit or roll back together.
     * @return array<string, mixed>
     */
    public function open(int $employmentId, array $data, int $actorUserId, ?callable $afterOpen = null): array
    {
        return DB::transaction(function () use ($employmentId, $data, $actorUserId, $afterOpen): array {
            $renewalOf = isset($data['renewal_of_contract_id']) ? (int) $data['renewal_of_contract_id'] : null;

            // An explicit renewal target is authoritative. Falling back to the
            // latest operational row is only for legacy callers that omit the
            // target; it must never silently pick a different chain than the
            // one the client named.
            $current = EmploymentContract::query()
                ->where('employment_id', $employmentId)
                ->when($renewalOf !== null, fn ($builder) => $builder->whereKey($renewalOf))
                ->when($renewalOf === null, fn ($builder) => $builder->whereIn('status', EmploymentContract::OPERATIONAL_STATUSES))
                ->orderByDesc('start_date')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($current !== null && (
                ! in_array((string) $current->status, EmploymentContract::OPERATIONAL_STATUSES, true)
                || ($current->end_date !== null
                    && BusinessDate::from((string) $current->end_date)->isBefore(BusinessDate::from($this->businessDates->today()->value())))
            )) {
                throw new ConflictException(
                    'The selected contract can no longer be renewed.',
                    ['reason' => 'CONTRACT_NOT_RENEWABLE'],
                );
            }

            if (EmploymentContract::query()->where('contract_no', (string) $data['contract_no'])->exists()) {
                throw new ConflictException('Contract number must be unique.', ['reason' => 'CONTRACT_NO_DUPLICATE']);
            }

            /** @var EmploymentContract $contract */
            $contract = EmploymentContract::query()->create([
                'employment_id' => $employmentId,
                'contract_no' => (string) $data['contract_no'],
                'contract_type' => (string) $data['contract_type'],
                'start_date' => (string) $data['start_date'],
                'end_date' => isset($data['end_date']) ? (string) $data['end_date'] : null,
                'signed_on' => isset($data['signed_on']) ? (string) $data['signed_on'] : null,
                'status' => (string) ($data['status'] ?? 'ACTIVE'),
                'version' => $current !== null ? (int) $current->version + 1 : 1,
                'previous_contract_id' => $current?->id,
                'terms_summary' => isset($data['terms_summary']) ? (string) $data['terms_summary'] : null,
                'work_location' => isset($data['work_location']) ? (string) $data['work_location'] : null,
                'probation_end_date' => isset($data['probation_end_date']) ? (string) $data['probation_end_date'] : null,
                'recorded_at' => $this->clock->now(),
                'source_type' => 'API',
            ]);

            if ($current !== null) {
                $current->forceFill(['status' => 'RENEWED', 'renewed_by_id' => $contract->id])->save();
            }

            $this->audit->record(
                actorUserId: $actorUserId,
                action: $current !== null ? 'people.contract.renewed' : 'people.contract.created',
                aggregateType: 'employment_contract',
                aggregateId: $contract->id,
                after: ['contract_no' => (string) $contract->contract_no, 'employment_id' => $employmentId],
            );

            $payload = $this->payload($contract, $this->businessDates->today()->value());

            if ($afterOpen !== null) {
                $afterOpen($payload);
            }

            return $payload;
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function show(int $employmentId, int $id): array
    {
        $contract = EmploymentContract::query()
            ->where('employment_id', $employmentId)
            ->whereKey($id)
            ->first();

        if ($contract === null) {
            throw new NotFoundOrForbiddenException;
        }

        return $this->payload($contract, $this->businessDates->today()->value());
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function expiring(string $asOf, int $withinDays): array
    {
        $from = CarbonImmutable::parse($asOf, 'UTC');
        $to = $from->addDays($withinDays);

        return EmploymentContract::query()
            ->whereIn('status', EmploymentContract::OPERATIONAL_STATUSES)
            ->whereNotNull('end_date')
            ->where('end_date', '>=', $from->toDateString())
            ->where('end_date', '<=', $to->toDateString())
            ->orderBy('end_date')
            ->orderBy('id')
            ->get()
            ->map(fn (EmploymentContract $contract): array => $this->payload($contract, $asOf))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(EmploymentContract $contract, ?string $asOf = null): array
    {
        $employment = Employment::query()->whereKey($contract->employment_id)->first();
        $person = $employment instanceof Employment
            ? Person::query()->whereKey($employment->person_id)->first()
            : null;

        return EmploymentContractView::toArray(
            $contract,
            $asOf ?? $this->businessDates->today()->value(),
            $employment,
            $person,
        );
    }
}
