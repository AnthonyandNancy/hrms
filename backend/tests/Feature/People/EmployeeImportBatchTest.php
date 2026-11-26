<?php

declare(strict_types=1);

namespace Tests\Feature\People;

use App\Modules\People\Models\EmployeeImportBatch;
use Tests\Support\PeopleTestCase;

/**
 * Import batches are company-level evidence, not employment-scoped rows.
 * The Compose route audit found the list endpoint selecting a non-existent
 * employment_id column, which returned a real 500.
 */
final class EmployeeImportBatchTest extends PeopleTestCase
{
    public function test_import_batches_are_company_level_and_not_employment_scoped(): void
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

        $response = $this->getJson("/v1/employees/{$employment->id}/imports")
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.original_name', 'synthetic-import.xlsx');

        $this->assertArrayNotHasKey('employment_id', $response->json('data.items.0'));
    }

    public function test_import_batches_are_company_level_across_employments(): void
    {
        $scenario = $this->scenario();
        $org = $scenario->orgUnit('IMPORT-TENANT-ORG');
        $employment = $scenario->employment($scenario->person(), 'IMPORT-0002');
        $scenario->assignment($employment, $org);

        EmployeeImportBatch::query()->create([
            'uploaded_by' => 1,
            'status' => 'VALIDATING',
            'original_name' => 'other-company.xlsx',
            'total_rows' => 1,
            'valid_rows' => 1,
            'failed_rows' => 0,
            'errors_json' => [],
        ]);

        $this->getJson("/v1/employees/{$employment->id}/imports")
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.original_name', 'other-company.xlsx');
    }
}
