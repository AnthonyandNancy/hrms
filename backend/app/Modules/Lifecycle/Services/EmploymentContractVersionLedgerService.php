<?php

declare(strict_types=1);

namespace App\Modules\Lifecycle\Services;

use App\Modules\Lifecycle\Contracts\EmploymentContractVersionLedger;
use App\Modules\Lifecycle\Models\EmploymentContractVersion;
use App\Shared\Observability\AuditRecorder;
use App\Shared\Time\Clock;
use Illuminate\Support\Facades\DB;

/**
 * Sole writer of employment_contract_versions. People receives this port via
 * its provider binding and appends lifecycle rows inside the command
 * transaction; nothing here updates or deletes a historical row.
 */
final class EmploymentContractVersionLedgerService implements EmploymentContractVersionLedger
{
    public function __construct(
        private readonly Clock $clock,
        private readonly AuditRecorder $audit,
    ) {}

    public function append(
        int $contractId,
        int $employmentId,
        string $changeType,
        string $effectiveFrom,
        string $effectiveTo,
        ?string $reason,
        array $snapshot,
        ?int $actorUserId,
    ): array {
        return DB::transaction(function () use ($contractId, $employmentId, $changeType, $effectiveFrom, $effectiveTo, $reason, $snapshot, $actorUserId): array {
            $termsVersion = (int) (EmploymentContractVersion::query()
                ->where('employment_id', $employmentId)
                ->max('terms_version') ?? 0) + 1;
            $documentVersion = (int) (EmploymentContractVersion::query()
                ->where('contract_id', $contractId)
                ->max('document_version') ?? 0) + 1;

            /** @var EmploymentContractVersion $row */
            $row = EmploymentContractVersion::query()->create([
                'employment_id' => $employmentId,
                'contract_id' => $contractId,
                'change_type' => $changeType,
                'terms_version' => $termsVersion,
                'document_version' => $documentVersion,
                'file_object_id' => null,
                'effective_from' => $effectiveFrom,
                'effective_to' => $effectiveTo,
                'expires_at' => null,
                'reason' => $reason,
                'snapshot_json' => $snapshot,
                'recorded_at' => $this->clock->now(),
                'source_type' => 'API',
                'source_id' => null,
            ]);

            $this->audit->record(
                actorUserId: $actorUserId,
                action: 'people.contract.version.appended',
                aggregateType: 'employment_contract',
                aggregateId: $contractId,
                after: [
                    'contract_id' => $contractId,
                    'version_id' => (int) $row->id,
                    'change_type' => $changeType,
                    'effective_from' => $effectiveFrom,
                ],
            );

            return $this->payload($row);
        });
    }

    public function history(int $contractId): array
    {
        return EmploymentContractVersion::query()
            ->where('contract_id', $contractId)
            ->orderBy('effective_from')
            ->orderBy('document_version')
            ->orderBy('id')
            ->get()
            ->map(fn (EmploymentContractVersion $row): array => $this->payload($row))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(EmploymentContractVersion $row): array
    {
        return [
            'id' => (int) $row->id,
            'contract_id' => (int) $row->contract_id,
            'employment_id' => (int) $row->employment_id,
            'change_type' => (string) $row->change_type,
            'terms_version' => (int) $row->terms_version,
            'document_version' => (int) $row->document_version,
            'effective_from' => (string) $row->effective_from,
            'effective_to' => (string) $row->effective_to,
            'reason' => $row->reason,
            'snapshot' => $row->snapshot_json ?? [],
            'recorded_at' => $row->recorded_at?->toIso8601ZuluString(),
        ];
    }
}
