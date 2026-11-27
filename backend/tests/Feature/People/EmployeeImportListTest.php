<?php

declare(strict_types=1);

namespace Tests\Feature\People;

use App\Modules\People\Models\EmployeeImportBatch;
use Tests\Support\PeopleTestCase;

/**
 * Import results are a company-level list action, not an employment
 * subresource. Rows come only from persisted batches.
 */
final class EmployeeImportListTest extends PeopleTestCase
{
    public function test_import_batches_are_listed_company_wide_with_real_persisted_values(): void
    {
        $first = EmployeeImportBatch::query()->create([
            'uploaded_by' => 10,
            'status' => 'VALIDATING',
            'original_name' => 'synthetic-one.xlsx',
            'total_rows' => 3,
            'valid_rows' => 2,
            'failed_rows' => 1,
            'errors_json' => [['row' => 2, 'field' => 'employee_no']],
        ]);
        EmployeeImportBatch::query()->create([
            'uploaded_by' => 11,
            'status' => 'COMMITTED',
            'original_name' => 'synthetic-two.xlsx',
            'total_rows' => 5,
            'valid_rows' => 5,
            'failed_rows' => 0,
            'errors_json' => [],
        ]);

        $this->getJson('/v1/employee-imports?page=1&page_size=20')
            ->assertOk()
            ->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.page', 1)
            ->assertJsonPath('data.page_size', 20)
            ->assertJsonPath('data.items.1.id', (int) $first->id)
            ->assertJsonPath('data.items.1.original_name', 'synthetic-one.xlsx')
            ->assertJsonPath('data.items.1.valid_rows', 2)
            ->assertJsonPath('data.items.1.failed_rows', 1);
    }

    public function test_import_list_does_not_require_an_employment_id_and_never_invents_a_batch(): void
    {
        $this->getJson('/v1/employee-imports?page=1&page_size=20')
            ->assertOk()
            ->assertJsonPath('data.total', 0)
            ->assertJsonPath('data.items', []);
    }

    public function test_import_list_requires_manage_capability(): void
    {
        $this->actingAsUserWithCapabilities(['people.employee.read']);

        $this->getJson('/v1/employee-imports')->assertForbidden();
    }
}
