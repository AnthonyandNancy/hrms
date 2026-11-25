<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Modules\Platform\Contracts\DictionaryReader;
use App\Modules\Platform\Contracts\ParameterReader;
use App\Modules\Platform\Models\DictionaryEntry;
use App\Modules\Platform\Models\SystemParameter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PlatformKernelTest extends TestCase
{
    use RefreshDatabase;

    public function test_dictionary_reader_returns_only_the_requested_type_in_sort_order(): void
    {
        DictionaryEntry::query()->create(['tenant_id' => 1, 'type' => 'EMPLOYEE_STATUS', 'code' => 'ACTIVE', 'label' => '在职', 'sort_order' => 2]);
        DictionaryEntry::query()->create(['tenant_id' => 1, 'type' => 'EMPLOYEE_STATUS', 'code' => 'PROBATION', 'label' => '试用', 'sort_order' => 1]);
        DictionaryEntry::query()->create(['tenant_id' => 1, 'type' => 'CONTRACT_TYPE', 'code' => 'FIXED', 'label' => '固定期限', 'sort_order' => 1]);
        DictionaryEntry::query()->create(['tenant_id' => 2, 'type' => 'EMPLOYEE_STATUS', 'code' => 'FOREIGN', 'label' => 'Other tenant', 'sort_order' => 1]);

        $entries = app(DictionaryReader::class)->entries(1, 'EMPLOYEE_STATUS');

        $this->assertCount(2, $entries);
        $this->assertSame(['PROBATION', 'ACTIVE'], array_column($entries, 'code'));
    }

    public function test_inactive_dictionary_entries_are_not_exposed(): void
    {
        DictionaryEntry::query()->create(['tenant_id' => 1, 'type' => 'EMPLOYEE_STATUS', 'code' => 'RETIRED', 'label' => '停用', 'sort_order' => 1, 'active' => false]);

        $this->assertSame([], app(DictionaryReader::class)->entries(1, 'EMPLOYEE_STATUS'));
    }

    public function test_parameter_reader_returns_the_typed_tenant_value_and_default(): void
    {
        SystemParameter::query()->create([
            'tenant_id' => 1,
            'key' => 'pagination.max_page_size',
            'value_json' => ['value' => 200],
            'scope' => 'TENANT',
        ]);

        $reader = app(ParameterReader::class);

        $this->assertSame(200, $reader->get(1, 'pagination.max_page_size', 50));
        $this->assertSame(50, $reader->get(1, 'pagination.unknown', 50));
        $this->assertSame(50, $reader->get(2, 'pagination.max_page_size', 50));
    }

    public function test_dictionary_codes_are_unique_per_tenant_and_type(): void
    {
        DictionaryEntry::query()->create(['tenant_id' => 1, 'type' => 'EMPLOYEE_STATUS', 'code' => 'ACTIVE', 'label' => '在职', 'sort_order' => 1]);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        DictionaryEntry::query()->create(['tenant_id' => 1, 'type' => 'EMPLOYEE_STATUS', 'code' => 'ACTIVE', 'label' => 'Duplicate', 'sort_order' => 2]);
    }
}
