<?php

declare(strict_types=1);

namespace App\Modules\People\Services;

use App\Modules\People\Models\EmploymentContract;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundOrForbiddenException;
use App\Shared\Observability\AuditRecorder;
use App\Shared\Time\Clock;
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
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function list(int $tenantId, int $employmentId): array
    {
        return EmploymentContract::query()
            ->where('tenant_id', $tenantId)
            ->where('employment_id', $employmentId)
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (EmploymentContract $contract): array => $this->payload($contract))
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function open(int $tenantId, int $employmentId, array $data, int $actorUserId): array
    {
        return DB::transaction(function () use ($tenantId, $employmentId, $data, $actorUserId): array {
            $current = EmploymentContract::query()
                ->where('tenant_id', $tenantId)
                ->where('employment_id', $employmentId)
                ->whereIn('status', ['ACTIVE', 'EXPIRING'])
                ->orderByDesc('start_date')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if (EmploymentContract::query()->where('tenant_id', $tenantId)->where('contract_no', (string) $data['contract_no'])->exists()) {
                throw new ConflictException('Contract number must be unique within the tenant.', ['reason' => 'CONTRACT_NO_DUPLICATE']);
            }

            /** @var EmploymentContract $contract */
            $contract = EmploymentContract::query()->create([
                'tenant_id' => $tenantId,
                'employment_id' => $employmentId,
                'contract_no' => (string) $data['contract_no'],
                'contract_type' => (string) $data['contract_type'],
                'start_date' => (string) $data['start_date'],
                'end_date' => isset($data['end_date']) ? (string) $data['end_date'] : null,
                'signed_on' => isset($data['signed_on']) ? (string) $data['signed_on'] : null,
                'status' => (string) ($data['status'] ?? 'ACTIVE'),
                'version' => $current !== null ? (int) $current->version + 1 : 1,
                'previous_contract_id' => $current?->id,
                'recorded_at' => $this->clock->now(),
                'source_type' => 'API',
            ]);

            if ($current !== null) {
                $current->forceFill(['status' => 'RENEWED', 'renewed_by_id' => $contract->id])->save();
            }

            $this->audit->record(
                tenantId: $tenantId,
                actorUserId: $actorUserId,
                action: $current !== null ? 'people.contract.renewed' : 'people.contract.created',
                aggregateType: 'employment_contract',
                aggregateId: $contract->id,
                after: ['contract_no' => (string) $contract->contract_no, 'employment_id' => $employmentId],
            );

            return $this->payload($contract);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function show(int $tenantId, int $employmentId, int $id): array
    {
        $contract = EmploymentContract::query()
            ->where('tenant_id', $tenantId)
            ->where('employment_id', $employmentId)
            ->whereKey($id)
            ->first();

        if ($contract === null) {
            throw new NotFoundOrForbiddenException;
        }

        return $this->payload($contract);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function expiring(int $tenantId, string $asOf, int $withinDays, ?int $legalEntityId = null): array
    {
        $from = CarbonImmutable::parse($asOf, 'UTC');
        $to = $from->addDays($withinDays);

        return EmploymentContract::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', ['ACTIVE', 'EXPIRING'])
            ->whereNotNull('end_date')
            ->where('end_date', '>=', $from->toDateString())
            ->where('end_date', '<=', $to->toDateString())
            ->when($legalEntityId !== null, fn ($query) => $query->where('legal_entity_id', (string) $legalEntityId))
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
        $days = null;

        if ($contract->end_date !== null && $asOf !== null) {
            $days = (int) CarbonImmutable::parse($asOf, 'UTC')
                ->diffInDays(CarbonImmutable::parse((string) $contract->end_date, 'UTC'), false);
        }

        return [
            'id' => (int) $contract->id,
            'employment_id' => (int) $contract->employment_id,
            'contract_no' => (string) $contract->contract_no,
            'contract_type' => (string) $contract->contract_type,
            'start_date' => (string) $contract->start_date,
            'end_date' => $contract->end_date,
            'signed_on' => $contract->signed_on,
            'status' => (string) $contract->status,
            'version' => (int) $contract->version,
            'previous_contract_id' => $contract->previous_contract_id,
            'renewed_by_id' => $contract->renewed_by_id,
            'days_to_expiry' => $days,
        ];
    }
}
