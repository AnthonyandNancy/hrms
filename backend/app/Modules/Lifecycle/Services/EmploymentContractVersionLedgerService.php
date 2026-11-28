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
            $agreedSalary = $snapshot['agreed_salary_cents']
                ?? $snapshot['agreed_salary_minor']
                ?? null;
            $agreedCurrency = isset($snapshot['agreed_currency'])
                ? strtoupper((string) $snapshot['agreed_currency'])
                : null;
            $serializedSnapshot = json_encode(
                $snapshot,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );

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
                'agreed_salary_minor' => $agreedSalary !== null ? (int) $agreedSalary : null,
                'agreed_currency' => $agreedCurrency !== null ? $agreedCurrency : null,
                'terms_hash' => hash('sha256', $serializedSnapshot),
                'status' => 'RECORDED',
                'activated_at' => $changeType === 'ACTIVATION' ? $this->clock->now() : null,
                'activated_by' => $changeType === 'ACTIVATION' ? $actorUserId : null,
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

    public function findVersion(int $versionId): ?array
    {
        $row = EmploymentContractVersion::query()->whereKey($versionId)->first();

        return $row instanceof EmploymentContractVersion ? $this->payload($row) : null;
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
            'agreed_salary_minor' => $row->agreed_salary_minor !== null ? (int) $row->agreed_salary_minor : null,
            'agreed_currency' => $row->agreed_currency !== null ? (string) $row->agreed_currency : null,
            'terms_hash' => $row->terms_hash !== null ? (string) $row->terms_hash : null,
            'status' => $row->status !== null ? (string) $row->status : 'RECORDED',
            'activated_at' => $row->activated_at?->toIso8601ZuluString(),
            'activated_by' => $row->activated_by !== null ? (int) $row->activated_by : null,
            'reason' => $row->reason,
            'snapshot' => $row->snapshot_json ?? [],
            'recorded_at' => $row->recorded_at?->toIso8601ZuluString(),
        ];
    }
}
