<?php

declare(strict_types=1);

namespace App\Modules\Platform\Migration\Services;

use App\Modules\Platform\Migration\Contracts\MigrationTargetPort;
use App\Modules\Platform\Migration\Dto\MigrationRow;
use App\Modules\Platform\Migration\Dto\TargetMetrics;
use App\Modules\Platform\Migration\Dto\TargetWriteResult;
use App\Modules\Platform\Migration\Enums\MigrationDomain;
use App\Modules\Platform\Migration\Models\MigrationShadowRow;

/**
 * Database-backed rehearsal target. Rows are explicitly marked SHADOW in the
 * domain value so they can never be mistaken for imported business records.
 */
final class ShadowMigrationTarget implements MigrationTargetPort
{
    public function __construct(private readonly MigrationDomain $domain) {}

    public function domain(): MigrationDomain
    {
        return $this->domain;
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function unavailableReason(): ?string
    {
        return null;
    }

    public function apply(MigrationRow $row, int $runId): TargetWriteResult
    {
        $domainValue = 'SHADOW:'.$this->domain->value;
        $naturalKey = $this->naturalKey($row);
        $hashData = $row->data;
        unset($hashData['id_number_ciphertext']);
        ksort($hashData);
        $hash = hash('sha256', json_encode($hashData, JSON_THROW_ON_ERROR));
        $existing = MigrationShadowRow::query()
            ->where('domain', $domainValue)
            ->where('natural_key', $naturalKey)
            ->first();

        if ($existing !== null && $existing->data_hash === $hash) {
            return new TargetWriteResult((int) $existing->id, TargetWriteResult::SKIPPED, true);
        }

        $status = $existing === null ? TargetWriteResult::CREATED : TargetWriteResult::UPDATED;
        $record = $existing ?? new MigrationShadowRow;
        $record->fill([
            'domain' => $domainValue,
            'natural_key' => $naturalKey,
            'migration_run_id' => $runId,
            'source_id' => $row->stgId,
            'data_hash' => $hash,
            'payload_json' => $row->data,
        ]);
        $record->save();

        return new TargetWriteResult((int) $record->id, $status);
    }

    public function finalize(int $runId): void {}

    public function metrics(): TargetMetrics
    {
        $rows = MigrationShadowRow::query()
            ->where('domain', 'SHADOW:'.$this->domain->value)
            ->get();

        $sums = [];

        foreach ($this->domain->reconciliationSums() as $field) {
            $sums[$field] = (int) $rows->sum(static fn (MigrationShadowRow $row): int => (int) ($row->payload_json[$field] ?? 0));
        }

        return new TargetMetrics(count: $rows->count(), sums: $sums);
    }

    /**
     * @return list<string>
     */
    public function rollbackStatements(int $runId): array
    {
        return [
            '-- SHADOW rows only (not business tables).',
            "DELETE FROM migration_shadow_rows WHERE domain = 'SHADOW:{$this->domain->value}' AND migration_run_id = {$runId};",
        ];
    }

    private function naturalKey(MigrationRow $row): string
    {
        $data = $row->data;

        return match ($this->domain) {
            MigrationDomain::ORGANIZATIONS => (string) $data['code'],
            MigrationDomain::PEOPLE => (string) $data['employee_no'],
            MigrationDomain::ASSIGNMENTS => sprintf('%s|%s|%s', $data['employment_no'], $data['valid_from'], $data['is_primary'] ? '1' : '0'),
            MigrationDomain::TERMS => sprintf('%s|%s', $data['employment_no'], $data['version']),
            MigrationDomain::LEAVE_OPENINGS => sprintf('%s|%s|%s', $data['employment_no'], $data['leave_type'], $data['expiry_date']),
            MigrationDomain::PAYROLL_YTD => sprintf('%s|%s', $data['employment_no'], $data['tax_year']),
        };
    }
}
