<?php

declare(strict_types=1);

namespace Tests\Feature\People;

use App\Modules\People\Models\EmployeeImportBatch;
use Tests\Support\PeopleTestCase;

/**
 * Import batches are company-level evidence. The list action replaces the old
 * employment-scoped read and only returns values persisted by the importer.
 */
final class EmployeeImportBatchTest extends PeopleTestCase
{
    public function test_import_batches_are_company_level_and_never_employment_scoped(): void
    {
        $scenario = $this->scenario();
        $org = $scenario->orgUnit('IMPORT-ORG');
        $employment = $scenario->employment($scenario->person(), 'IMPORT-0001');
        $scenario->assignment($employment, $org);

        EmployeeImportBatch::query()->create([
            'uploaded_by' => 1,
            'status' => 'VALIDATING',
            'original_name' => 'synthetic-import.xlsx',
            'total_rows' => 3,
            'valid_rows' => 2,
            'failed_rows' => 1,
            'errors_json' => [],
        ]);

        $response = $this->getJson('/v1/employee-imports?page=1&page_size=20')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.original_name', 'synthetic-import.xlsx');

        $this->assertArrayNotHasKey('employment_id', $response->json('data.items.0'));
    }

    public function test_import_batches_are_company_level_across_employments(): void
    {
        $scenario = $this->scenario();
        $org = $scenario->orgUnit('IMPORT-COMPANY-ORG');
        $first = $scenario->employment($scenario->person(), 'IMPORT-0002');
        $second = $scenario->employment($scenario->person(), 'IMPORT-0003');
        $scenario->assignment($first, $org);
        $scenario->assignment($second, $org);

        EmployeeImportBatch::query()->create([
            'uploaded_by' => 1,
            'status' => 'VALIDATING',
            'original_name' => 'company-wide.xlsx',
            'total_rows' => 1,
            'valid_rows' => 1,
            'failed_rows' => 0,
            'errors_json' => [],
        ]);

        $this->getJson('/v1/employee-imports?page=1&page_size=20')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.original_name', 'company-wide.xlsx');
    }

    public function test_legacy_employment_scoped_import_route_is_gone(): void
    {
        $scenario = $this->scenario();
        $employment = $scenario->employment($scenario->person(), 'IMPORT-0004');
        $scenario->assignment($employment, $scenario->orgUnit('IMPORT-LEGACY-ORG'));

        $this->getJson("/v1/employees/{$employment->id}/imports")->assertNotFound();
    }
}
