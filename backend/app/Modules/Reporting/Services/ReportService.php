<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Services;

use App\Modules\IAM\Contracts\Principal;
use App\Modules\Reporting\Models\ReportRun;
use App\Shared\Authorization\FieldProfile;
use App\Shared\Authorization\FieldSet;
use App\Shared\Exceptions\ValidationException;
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
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function run(Principal $principal, string $code, string $asOf, array $filters = []): array
    {
        $this->projections->ensure($principal->tenantId(), $asOf);
        $definition = $this->definition($principal->tenantId(), $code);
        $fields = (array) $definition['fields'];
        $visible = $this->fieldSet($fields);
        $rows = $this->rows($principal, $code, $asOf, $filters, $fields);
        $projected = [];

        foreach ($rows as $row) {
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

        return [
            'runId' => (int) $run->id,
            'definitionCode' => (string) $definition['code'],
            'title' => (string) $definition['name'],
            'subtitle' => $asOf,
            'columns' => array_map(static fn (array $field): array => [
                'key' => (string) $field['key'],
                'label' => (string) $field['label'],
                'profile' => (string) $field['profile'],
            ], $fields),
            'rows' => $projected,
            'chart' => $this->chart($code, $projected, $fields),
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
     * @return array{definition: array<string, mixed>, fields: list<array<string, mixed>>, rows: list<array<string, mixed>>}
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

        $rows = $this->rows($principal, $code, $asOf, $filters, $fields);
        $visible = $this->fieldSet($fields, exportUnlocked: true);
        $projected = [];

        foreach ($rows as $row) {
            $projected[] = $visible->mask($row);
        }

        return ['definition' => $definition, 'fields' => $fields, 'rows' => $projected];
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
     * @return list<array<string, mixed>>
     */
    private function rows(Principal $principal, string $code, string $asOf, array $filters, array $fields): array
    {
        $result = match ($code) {
            'WORKFORCE_HEADCOUNT' => $this->metrics->query($principal, 'headcount', $asOf, $filters),
            'CONTRACT_EXPIRY' => $this->metrics->query($principal, 'contract_expiring', $asOf, $filters),
            'PENDING_APPROVALS' => $this->metrics->query($principal, 'pending_approvals', $asOf, $filters),
            'LEAVE_LIABILITY' => $this->metrics->query($principal, 'leave_liability', $asOf, $filters),
            'PAYROLL_COST' => $this->metrics->query($principal, 'payroll_cost_by_entity', $asOf, $filters),
            'RECRUITMENT_FUNNEL' => $this->metrics->query($principal, 'recruitment_funnel', $asOf, $filters),
            'TURNOVER' => $this->metrics->query($principal, 'turnover', $asOf, $filters),
            default => throw new ValidationException(['definition_code' => ['This report is not implemented.']], 'The given data was invalid.', ['reason' => 'REPORT_NOT_IMPLEMENTED']),
        };

        if ($result['status'] !== 'AVAILABLE') {
            throw new \App\Shared\Exceptions\ServiceUnavailableException((string) ($result['reason'] ?? 'This report is unavailable.'));
        }

        $rows = [];

        foreach ($result['items'] as $item) {
            $row = [];

            foreach ($fields as $field) {
                $key = (string) $field['key'];
                $row[$key] = $this->cell($item, $key);
            }

            $rows[] = $row;
        }

        return $rows;
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
}
