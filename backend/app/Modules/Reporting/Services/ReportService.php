<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Services;

use App\Modules\IAM\Contracts\Principal;
use App\Modules\Reporting\Models\ReportRun;
use App\Shared\Authorization\Contracts\SensitiveAccessAuditor;
use App\Shared\Authorization\FieldProfile;
use App\Shared\Authorization\FieldSet;
use App\Shared\Exceptions\ValidationException;
use App\Shared\Projections\ProjectionResult;
use App\Shared\Time\Clock;

/**
 * Synchronous report query for the report screen. Export uses the same Report
 * data seam; only its field whitelist and async storage differ.
 */
final class ReportService
{
    public function __construct(
        private readonly ReportDefinitionCatalog $catalog,
        private readonly MetricQueryBuilder $metrics,
        private readonly ProjectionBuilder $projections,
        private readonly Clock $clock,
        private readonly SensitiveAccessAuditor $sensitiveAudit,
    ) {}

    /**
     * Run a report definition. Rows use the ADR-0029 projection envelope;
     * `columns` and `chart` are additive presentation fields only.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function run(Principal $principal, string $code, string $asOf, array $filters = []): array
    {
        $this->projections->ensure($principal->tenantId(), $asOf);
        $definition = $this->definition($principal->tenantId(), $code);
        $fields = (array) $definition['fields'];
        $visible = $this->fieldSet($fields);
        $result = $this->rows($principal, $code, $asOf, $filters, $fields);
        $projected = [];

        foreach ($result['items'] as $row) {
            $projected[] = $visible->mask($row);
        }

        $run = ReportRun::query()->create([
            'tenant_id' => $principal->tenantId(),
            'definition_code' => (string) $definition['code'],
            'as_of' => $asOf,
            'filters_json' => $filters,
            'status' => 'COMPLETED',
            'row_count' => count($projected),
            'requested_by' => $principal->userId(),
            'started_at' => $this->clock->now(),
            'completed_at' => $this->clock->now(),
        ]);

        $this->auditSensitive($principal, $run, $fields, $projected);

        return [
            'runId' => (int) $run->id,
            'definitionCode' => (string) $definition['code'],
            'title' => (string) $definition['name'],
            'columns' => array_map(static fn (array $field): array => [
                'key' => (string) $field['key'],
                'label' => (string) $field['label'],
                'profile' => (string) $field['profile'],
            ], $fields),
            'chart' => $this->chart($code, $projected, $fields),
            'items' => $projected,
            'page' => 1,
            'page_size' => max(1, count($projected)),
            'total' => count($projected),
            'as_of' => $asOf,
            'freshness' => ProjectionResult::FRESHNESS_MATERIALIZED,
            'source_hash' => $result['source_hash'],
            'partial' => false,
            'partial_reason' => null,
            'drilldown' => null,
            'totalRows' => count($projected),
            'generatedAt' => $this->clock->now()->toIso8601ZuluString(),
            'watermark' => null,
        ];
    }

    /**
     * Export path. Returns raw visible values after field whitelist rules; the
     * caller must not add any field back into the CSV.
     *
     * @param  array<string, mixed>  $filters
     * @param  list<string>  $requestedFields
     * @return array{definition: array<string, mixed>, fields: list<array<string, mixed>>, rows: list<array<string, mixed>>, source_hash: string}
     */
    public function exportRows(Principal $principal, string $code, string $asOf, array $filters, array $requestedFields): array
    {
        if ($requestedFields === []) {
            throw new ValidationException(['fields' => ['At least one export field is required.']], 'The given data was invalid.', ['reason' => 'EXPORT_FIELDS_REQUIRED']);
        }

        $definition = $this->definition($principal->tenantId(), $code);
        $catalogFields = (array) $definition['fields'];
        $byKey = [];

        foreach ($catalogFields as $field) {
            $byKey[(string) $field['key']] = $field;
        }

        $fields = [];

        foreach ($requestedFields as $key) {
            $field = $byKey[$key] ?? null;

            if ($field === null) {
                throw new ValidationException(['fields' => ["Field {$key} is not exported by this report."]], 'The given data was invalid.', ['reason' => 'EXPORT_FIELD_NOT_ALLOWED']);
            }

            $profile = FieldProfile::from((string) $field['profile']);

            if ($profile->isHidden()) {
                throw new ValidationException(['fields' => ["Field {$key} is restricted and can never be exported."]], 'The given data was invalid.', ['reason' => 'EXPORT_FIELD_HIDDEN']);
            }

            $fields[] = $field;
        }

        $result = $this->rows($principal, $code, $asOf, $filters, $fields);
        $visible = $this->fieldSet($fields, exportUnlocked: true);
        $projected = [];

        foreach ($result['items'] as $row) {
            $projected[] = $visible->mask($row);
        }

        $this->auditSensitive($principal, null, $fields, $projected, $code);

        return [
            'definition' => $definition,
            'fields' => $fields,
            'rows' => $projected,
            'source_hash' => $result['source_hash'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function definition(int $tenantId, string $code): array
    {
        $this->catalog->syncForTenant($tenantId);
        $definitions = $this->catalog->payloadForTenant($tenantId);

        foreach ($definitions as $definition) {
            if ($definition['code'] === $code) {
                return $definition;
            }
        }

        throw new ValidationException(['definition_code' => ['Unknown report definition.']], 'The given data was invalid.', ['reason' => 'UNKNOWN_REPORT_DEFINITION']);
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     */
    private function fieldSet(array $fields, bool $exportUnlocked = false): FieldSet
    {
        $profiles = [];

        foreach ($fields as $field) {
            $profiles[(string) $field['key']] = FieldProfile::from((string) $field['profile']);
        }

        return new FieldSet($profiles, exportUnlocked: $exportUnlocked);
    }

    /**
     * All report rows go through the same metric builder predicates. This is
     * deliberate: a report list and a dashboard drill-down can never diverge
     * because both start from the same authorized projection query.
     *
     * @param  array<string, mixed>  $filters
     * @param  list<array<string, mixed>>  $fields
     * @return array{items: list<array<string, mixed>>, source_hash: string}
     */
    private function rows(Principal $principal, string $code, string $asOf, array $filters, array $fields): array
    {
        $metric = match ($code) {
            'WORKFORCE_HEADCOUNT' => 'headcount',
            'CONTRACT_EXPIRY' => 'contract_expiring',
            'PENDING_APPROVALS' => 'pending_approvals',
            'LEAVE_LIABILITY' => 'leave_liability',
            'PAYROLL_COST' => 'payroll_cost_by_entity',
            'RECRUITMENT_FUNNEL' => 'recruitment_funnel',
            'TURNOVER' => 'turnover',
            default => null,
        };

        if ($metric === null) {
            // A stored custom definition is only a subset over an approved
            // metric/projection; it must not need report-specific PHP.
            $metric = $this->metricForDefinition($principal->tenantId(), $code);
        }

        $result = $this->metrics->query($principal, $metric, $asOf, $filters);

        if ($result['status'] !== 'AVAILABLE') {
            throw new \App\Shared\Exceptions\ServiceUnavailableException((string) ($result['reason'] ?? 'This report is unavailable.'));
        }

        $rows = [];

        foreach ($result['items'] as $item) {
            /** @var array<string, mixed> $item */
            $row = [];

            foreach ($fields as $field) {
                $key = (string) $field['key'];
                $row[$key] = $this->cell($item, $key);
            }

            $rows[] = $row;
        }

        return [
            'items' => $rows,
            'source_hash' => hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
        ];
    }

    /**
     * Map a stored definition's source projection to the same metric query.
     * Custom definitions cannot select a different data source at run time.
     */
    private function metricForDefinition(int $tenantId, string $code): string
    {
        $definition = $this->definition($tenantId, $code);
        $source = (string) ($definition['sourceProjection'] ?? '');

        return match ($source) {
            'workforce' => 'headcount',
            'leave_liability' => 'leave_liability',
            'pending_approvals' => 'pending_approvals',
            'payroll_cost' => 'payroll_cost_by_entity',
            'recruitment_funnel' => 'recruitment_funnel',
            'turnover' => 'turnover',
            default => throw new ValidationException(['definition_code' => ['This report source is not implemented.']], 'The given data was invalid.', ['reason' => 'REPORT_NOT_IMPLEMENTED']),
        };
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function cell(array $item, string $key): string|int|null
    {
        $value = $item[$key] ?? $item['primary'] ?? null;

        if ($value === null) {
            return null;
        }

        if (is_int($value) || is_string($value)) {
            return $value;
        }

        return json_encode($value, JSON_THROW_ON_ERROR);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<string, mixed>>  $fields
     * @return list<array{label: string, value: int}>
     */
    private function chart(string $code, array $rows, array $fields): array
    {
        if ($rows === []) {
            return [];
        }

        $charts = [];

        foreach (array_slice($rows, 0, 20) as $row) {
            $label = (string) ($row['primary'] ?? $row[array_key_first($row)] ?? '');

            if ($label === '') {
                continue;
            }

            $charts[] = ['label' => $label, 'value' => 1];
        }

        return $charts;
    }

    /**
     * P2/P3 report reads and exports must leave evidence without values.
     *
     * @param  list<array<string, mixed>>  $fields
     * @param  list<array<string, mixed>>  $rows
     */
    private function auditSensitive(Principal $principal, ?ReportRun $run, array $fields, array $rows, ?string $definitionCode = null): void
    {
        if ($rows === []) {
            return;
        }

        $sensitive = [];

        foreach ($fields as $field) {
            $profile = FieldProfile::from((string) $field['profile']);

            if ($profile === FieldProfile::P2_SENSITIVE) {
                $sensitive[] = (string) $field['key'];
            }
        }

        if ($sensitive === []) {
            return;
        }

        $this->sensitiveAudit->record(
            tenantId: $principal->tenantId(),
            userId: $principal->userId(),
            resourceType: $definitionCode === null ? 'report_run' : 'report_export',
            resourceId: $run !== null ? (string) $run->id : $definitionCode,
            capability: $definitionCode === null ? 'reporting.report.read' : 'reporting.export',
            fields: $sensitive,
            context: [
                'definition_code' => $definitionCode ?? ($run !== null ? (string) $run->definition_code : null),
                'row_count' => count($rows),
            ],
        );
    }
}
