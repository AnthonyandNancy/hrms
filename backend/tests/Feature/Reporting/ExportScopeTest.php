<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Modules\Reporting\Models\ExportJob;
use App\Modules\Reporting\Models\ProjectionRow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

/**
 * Export must be the same authorized projection as the report page. The tests
 * drive the real routes, the real CapabilityAuthorizer and the inline export
 * runner used in testing; only the storage backend is faked.
 */
final class ExportScopeTest extends ReportingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_export_rows_match_report_run_rows_for_the_same_principal_filters_and_scope(): void
    {
        $ids = $this->seedSyntheticWorkforce();
        $this->authenticate(
            (int) $ids['broad_user']->id,
            (int) $ids['employment_a1_id'],
            (int) $ids['broad_user']->auth_version,
        );

        $filters = ['org_unit_id' => $ids['org_a_id']];

        $report = $this->postJson('/v1/reporting/run', [
            'definition_code' => 'WORKFORCE_HEADCOUNT',
            'filters' => $filters,
            'as_of' => self::BUSINESS_DATE,
        ])->assertOk()->json('data');

        $this->assertSame(2, $report['totalRows']);
        $this->assertCount(2, $report['items']);
        $reportEmployees = array_values(array_map(
            static fn (array $row): string => (string) $row['employee_no'],
            $report['items'],
        ));

        $created = $this->postExport('WORKFORCE_HEADCOUNT', [
            'fields' => ['employee_no', 'org_path'],
            'filters' => $filters,
        ])->assertAccepted()->json('data');

        $this->assertSame('COMPLETED', $created['status']);
        $this->assertSame(2, $created['rowCount']);

        $csv = $this->downloadCsv((int) $created['id'], (string) $created['downloadToken']);
        $this->assertSame(['Employee no.', 'Organization', 'watermark'], $csv['header']);
        $this->assertCount(2, $csv['rows']);
        $this->assertSame($reportEmployees, array_values(array_map(
            static fn (array $row): string => (string) $row[0],
            $csv['rows'],
        )));

        $allValues = implode("\n", array_map(static fn (array $row): string => implode('|', $row), $csv['rows']));
        $this->assertStringContainsString('SYNTH-A-001', $allValues);
        $this->assertStringContainsString('SYNTH-A-002', $allValues);
        $this->assertStringNotContainsString('SYNTH-B-001', $allValues);
        $this->assertStringNotContainsString('SYNTH-B-002', $allValues);
        $this->assertStringNotContainsString('attacker_field', $allValues);
        $this->assertStringNotContainsString('salary', $allValues);
    }

    public function test_narrower_authorized_scope_yields_fewer_export_rows_and_matches_the_report_page(): void
    {
        $ids = $this->seedSyntheticWorkforce();

        $this->authenticate(
            (int) $ids['broad_user']->id,
            (int) $ids['employment_a1_id'],
            (int) $ids['broad_user']->auth_version,
        );
        $broad = $this->postExport('WORKFORCE_HEADCOUNT', [
            'fields' => ['employee_no'],
        ])->assertAccepted()->json('data');

        $this->authenticate(
            (int) $ids['narrow_user']->id,
            (int) $ids['employment_a2_id'],
            (int) $ids['narrow_user']->auth_version,
        );
        $narrowReport = $this->postJson('/v1/reporting/run', [
            'definition_code' => 'WORKFORCE_HEADCOUNT',
            'as_of' => self::BUSINESS_DATE,
        ])->assertOk()->json('data');

        $narrow = $this->postExport('WORKFORCE_HEADCOUNT', [
            'fields' => ['employee_no'],
        ])->assertAccepted()->json('data');

        $this->assertSame(4, $broad['rowCount']);
        $this->assertSame(2, $narrowReport['totalRows']);
        $this->assertSame(2, $narrow['rowCount']);
        $this->assertLessThan((int) $broad['rowCount'], (int) $narrow['rowCount']);

        $narrowCsv = $this->downloadCsv((int) $narrow['id'], (string) $narrow['downloadToken']);
        $this->assertCount(2, $narrowCsv['rows']);
        $this->assertSame(
            array_values(array_map(static fn (array $row): string => (string) $row['employee_no'], $narrowReport['items'])),
            array_values(array_map(static fn (array $row): string => (string) $row[0], $narrowCsv['rows'])),
        );

        $narrowValues = implode("\n", array_map(static fn (array $row): string => implode('|', $row), $narrowCsv['rows']));
        $this->assertStringNotContainsString('SYNTH-B-001', $narrowValues);
        $this->assertStringNotContainsString('SYNTH-B-002', $narrowValues);
    }

    public function test_export_rejects_disallowed_purpose_and_fields_outside_the_definition_whitelist(): void
    {
        $ids = $this->seedSyntheticWorkforce();
        $this->authenticate(
            (int) $ids['broad_user']->id,
            (int) $ids['employment_a1_id'],
            (int) $ids['broad_user']->auth_version,
        );

        $invalidPurpose = $this->postExport('WORKFORCE_HEADCOUNT', [
            'purpose' => 'MARKETING',
            'fields' => ['employee_no'],
        ])->assertStatus(422);

        $invalidPurpose->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonPath('data.reason', 'INVALID_EXPORT_PURPOSE');
        $this->assertDatabaseCount('export_jobs', 0);

        $unknownField = $this->postExport('WORKFORCE_HEADCOUNT', [
            'fields' => ['employee_no', 'attacker_field'],
        ])->assertStatus(422);

        $unknownField->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonPath('data.reason', 'EXPORT_FIELD_NOT_ALLOWED');
        $this->assertDatabaseCount('export_jobs', 0);

        // P3 salary is part of the Payroll report's schema, but the export
        // contract classifies it as restricted; it must be rejected rather
        // than silently emitted or silently dropped.
        $hiddenField = $this->postExport('PAYROLL_COST', [
            'fields' => ['salary'],
        ])->assertStatus(422);

        $hiddenField->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonPath('data.reason', 'EXPORT_FIELD_HIDDEN');
        $this->assertDatabaseCount('export_jobs', 0);

        $valid = $this->postExport('WORKFORCE_HEADCOUNT', [
            'fields' => ['employee_no'],
        ])->assertAccepted()->json('data');

        $csv = $this->downloadCsv((int) $valid['id'], (string) $valid['downloadToken']);
        $this->assertSame(['Employee no.', 'watermark'], $csv['header']);
        $this->assertStringNotContainsString('attacker_field', implode('', $csv['header']));
        $this->assertStringNotContainsString('salary', implode('', $csv['header']));
    }

    public function test_download_is_bound_to_the_requesting_principal_job_and_token(): void
    {
        $ids = $this->seedSyntheticWorkforce();
        $this->authenticate(
            (int) $ids['broad_user']->id,
            (int) $ids['employment_a1_id'],
            (int) $ids['broad_user']->auth_version,
        );

        $created = $this->postExport('WORKFORCE_HEADCOUNT', [
            'fields' => ['employee_no'],
        ])->assertAccepted()->json('data');
        $otherJob = $this->postExport('WORKFORCE_HEADCOUNT', [
            'fields' => ['org_path'],
        ])->assertAccepted()->json('data');

        $jobId = (int) $created['id'];
        $token = (string) $created['downloadToken'];

        $this->getJson("/v1/reporting/exports/{$jobId}/download?token=wrong-token")
            ->assertNotFound()
            ->assertJsonPath('code', 'NOT_FOUND');

        // A valid token is still bound to exactly one job.
        $this->getJson(
            '/v1/reporting/exports/'.$otherJob['id'].'/download?token='.$token,
        )->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');

        $this->authenticate(
            (int) $ids['narrow_user']->id,
            (int) $ids['employment_a2_id'],
            (int) $ids['narrow_user']->auth_version,
        );
        $this->getJson("/v1/reporting/exports/{$jobId}/download?token={$token}")
            ->assertNotFound()
            ->assertJsonPath('code', 'NOT_FOUND');

        $this->assertSame(0, ExportJob::query()->where('id', $jobId)->value('download_count'));
        $this->assertSame(0, ExportJob::query()->where('id', (int) $otherJob['id'])->value('download_count'));
    }

    public function test_export_row_cap_is_enforced_before_a_job_is_created(): void
    {
        // The cap is 50,000 rows. Materialising this boundary is intentionally
        // isolated here: the test writes Reporting-owned synthetic projection
        // rows, then drives the real ExportService through HTTP.
        if ((int) ini_get('memory_limit') > 0 && (int) ini_get('memory_limit') < 512) {
            ini_set('memory_limit', '512M');
        }

        $ids = $this->seedSyntheticWorkforce();
        $this->authenticate(
            (int) $ids['narrow_user']->id,
            (int) $ids['employment_a2_id'],
            (int) $ids['narrow_user']->auth_version,
        );

        $this->seedProjectionRows(50_001, (int) $ids['org_a_id']);

        $response = $this->postExport('WORKFORCE_HEADCOUNT', [
            'fields' => ['employee_no'],
        ])->assertStatus(422);

        $response->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonPath('data.reason', 'EXPORT_ROW_CAP_EXCEEDED');
        $this->assertDatabaseCount('export_jobs', 0);
    }

    public function test_download_count_and_expiry_are_enforced(): void
    {
        $ids = $this->seedSyntheticWorkforce();
        $this->authenticate(
            (int) $ids['broad_user']->id,
            (int) $ids['employment_a1_id'],
            (int) $ids['broad_user']->auth_version,
        );

        $countCapped = $this->postExport('WORKFORCE_HEADCOUNT', [
            'fields' => ['employee_no'],
        ])->assertAccepted()->json('data');

        for ($download = 1; $download <= 5; $download++) {
            $this->getJson(
                '/v1/reporting/exports/'.$countCapped['id'].'/download?token='.$countCapped['downloadToken'],
            )->assertOk();
        }

        $this->assertSame(5, (int) ExportJob::query()->whereKey((int) $countCapped['id'])->value('download_count'));

        $this->getJson(
            '/v1/reporting/exports/'.$countCapped['id'].'/download?token='.$countCapped['downloadToken'],
        )->assertStatus(409)->assertJsonPath('data.reason', 'EXPORT_DOWNLOAD_LIMIT_REACHED');

        $expiring = $this->postExport('WORKFORCE_HEADCOUNT', [
            'fields' => ['employee_no'],
        ])->assertAccepted()->json('data');

        $this->clock->setNow(CarbonImmutable::parse('2026-11-24T03:00:00Z', 'UTC'));

        $this->getJson(
            '/v1/reporting/exports/'.$expiring['id'].'/download?token='.$expiring['downloadToken'],
        )->assertStatus(409)->assertJsonPath('data.reason', 'EXPORT_EXPIRED');
    }

    /**
     * @return array{header: list<string>, rows: list<list<string>>}
     */
    private function downloadCsv(int $jobId, string $token): array
    {
        $response = $this->getJson("/v1/reporting/exports/{$jobId}/download?token={$token}")->assertOk();
        $contents = (string) $response->getContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $contents);
        $contents = substr($contents, 3);

        $lines = preg_split("/\r\n|\n|\r/", $contents) ?: [];
        $parsed = [];

        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }

            $parsed[] = str_getcsv($line, ',', '"', '');
        }

        $header = array_shift($parsed);

        $this->assertNotNull($header);

        return [
            'header' => array_values(array_map('strval', $header)),
            'rows' => array_values(array_map(
                static fn (array $row): array => array_values(array_map('strval', $row)),
                $parsed,
            )),
        ];
    }

    private function seedProjectionRows(int $count, int $orgUnitId): void
    {
        $this->assertGreaterThan(0, $count);

        for ($offset = 0; $offset < $count; $offset += 500) {
            $limit = min(500, $count - $offset);

            for ($index = 0; $index < $limit; $index++) {
                $ordinal = $offset + $index + 1;
                ProjectionRow::query()->create([
                    'source_projection' => 'workforce',
                    'business_date' => self::BUSINESS_DATE,
                    'metric_code' => 'headcount',
                    'entity_type' => 'EMPLOYMENT',
                    'entity_id' => 'CAP-'.$ordinal,
                    'employment_id' => 900000 + $ordinal,
                    'org_unit_id' => $orgUnitId,
                    'label' => 'Synthetic Cap Employee '.$ordinal,
                    'secondary_label' => 'Synthetic Org A',
                    'status' => 'ACTIVE',
                    'numeric_value' => 1,
                    'attributes_json' => [],
                    'source_hash' => str_repeat('c', 64),
                    'computed_at' => '2026-11-23 01:00:00',
                ]);
            }
        }
    }
}
