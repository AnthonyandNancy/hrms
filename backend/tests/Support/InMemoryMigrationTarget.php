<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Platform\Migration\Contracts\MigrationTargetPort;
use App\Modules\Platform\Migration\Dto\MigrationRow;
use App\Modules\Platform\Migration\Dto\TargetMetrics;
use App\Modules\Platform\Migration\Dto\TargetWriteResult;
use App\Modules\Platform\Migration\Enums\MigrationDomain;

/**
 * Deterministic target used by migration tests. It keeps no business tables,
 * but implements the exact port contract a database adapter must honour.
 */
final class InMemoryMigrationTarget implements MigrationTargetPort
{
    /** @var array<string, array<string, mixed>> */
    public array $rows = [];

    /** @var list<array{status: string, stg_id: int, data: array<string, mixed>}> */
    public array $writes = [];

    public int $created = 0;

    public int $updated = 0;

    public int $skipped = 0;

    public ?int $forcedCount = null;

    /** @var array<string, int> */
    public array $forcedSums = [];

    private int $nextId = 1000;

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

    public function apply(MigrationRow $row, int $tenantId, int $runId): TargetWriteResult
    {
        $key = $this->naturalKey($row->data);
        $hash = $this->dataHash($row->data);
        $existing = $this->rows[$key] ?? null;

        if (is_array($existing) && $existing['data_hash'] === $hash) {
            $this->skipped++;
            $this->writes[] = ['status' => TargetWriteResult::SKIPPED, 'stg_id' => $row->stgId, 'data' => $row->data];

            return new TargetWriteResult((int) $existing['target_id'], TargetWriteResult::SKIPPED, true);
        }

        $status = is_array($existing) ? TargetWriteResult::UPDATED : TargetWriteResult::CREATED;
        $id = (int) ($existing['target_id'] ?? $this->nextId++);
        $this->rows[$key] = [
            'target_id' => $id,
            'tenant_id' => $tenantId,
            'migration_run_id' => $runId,
            'source_id' => $row->stgId,
            'data_hash' => $hash,
            'data' => $row->data,
        ];
        $this->writes[] = ['status' => $status, 'stg_id' => $row->stgId, 'data' => $row->data];

        if ($status === TargetWriteResult::CREATED) {
            $this->created++;
        } else {
            $this->updated++;
        }

        return new TargetWriteResult($id, $status);
    }

    public function finalize(int $tenantId, int $runId): void
    {
        // No closure or manager resolution is performed by the in-memory port.
    }

    public function metrics(int $tenantId): TargetMetrics
    {
        $rows = array_values(array_filter(
            $this->rows,
            static fn (array $row): bool => $row['tenant_id'] === $tenantId,
        ));

        $sums = [];

        if ($this->domain === MigrationDomain::LEAVE_OPENINGS) {
            $sums['quantity_minutes'] = array_sum(array_map(
                static fn (array $row): int => (int) ($row['data']['quantity_minutes'] ?? 0),
                $rows,
            ));
        }

        if ($this->domain === MigrationDomain::PAYROLL_YTD) {
            foreach (['gross_cents', 'net_cents', 'taxable_cents', 'tax_withheld_cents', 'si_cents', 'hf_cents'] as $field) {
                $sums[$field] = array_sum(array_map(
                    static fn (array $row): int => (int) ($row['data'][$field] ?? 0),
                    $rows,
                ));
            }
        }

        return new TargetMetrics(
            count: $this->forcedCount ?? count($rows),
            sums: [...$sums, ...$this->forcedSums],
        );
    }

    /**
     * @return list<string>
     */
    public function rollbackStatements(int $tenantId, int $runId): array
    {
        return [
            "-- preflight: rows created by migration run {$runId} for tenant {$tenantId}",
            "DELETE FROM target_shadow WHERE domain = '{$this->domain->value}' AND migration_run_id = {$runId};",
        ];
    }

    public function forceCount(?int $count): void
    {
        $this->forcedCount = $count;
    }

    /**
     * @param  array<string, int>  $sums
     */
    public function forceSums(array $sums): void
    {
        $this->forcedSums = $sums;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function naturalKey(array $data): string
    {
        return match ($this->domain) {
            MigrationDomain::ORGANIZATIONS => (string) $data['code'],
            MigrationDomain::PEOPLE => (string) $data['employee_no'],
            MigrationDomain::ASSIGNMENTS => sprintf('%s|%s|%s', $data['employment_no'], $data['valid_from'], $data['is_primary'] ? '1' : '0'),
            MigrationDomain::TERMS => sprintf('%s|%s', $data['employment_no'], $data['version']),
            MigrationDomain::LEAVE_OPENINGS => sprintf('%s|%s|%s', $data['employment_no'], $data['leave_type'], $data['expiry_date']),
            MigrationDomain::PAYROLL_YTD => sprintf('%s|%s', $data['employment_no'], $data['tax_year']),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function dataHash(array $data): string
    {
        // Ciphertext is freshly randomized by Laravel encryption on every
        // validation pass. Idempotency is based on the deterministic business
        // payload; a real adapter preserves the existing ciphertext when the
        // identity hash is unchanged.
        unset($data['id_number_ciphertext']);
        ksort($data);

        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }
}
