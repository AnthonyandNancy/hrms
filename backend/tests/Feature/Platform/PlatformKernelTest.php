<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Modules\Platform\Contracts\DictionaryReader;
use App\Modules\Platform\Contracts\ParameterReader;
use App\Modules\Platform\Models\DictionaryEntry;
use App\Modules\Platform\Models\SystemParameter;
use App\Shared\Architecture\ModuleBoundaryScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
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
        $this->assertSame(['试用', '在职'], array_column($entries, 'label'));
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

    public function test_a_legal_entity_override_wins_over_the_tenant_default(): void
    {
        SystemParameter::query()->create(['tenant_id' => 1, 'key' => 'pagination.max_page_size', 'value_json' => ['value' => 200], 'scope' => 'TENANT']);
        SystemParameter::query()->create(['tenant_id' => 1, 'key' => 'pagination.max_page_size', 'value_json' => ['value' => 50], 'scope' => 'COMPANY:7']);

        $reader = app(ParameterReader::class);

        $this->assertSame(50, $reader->get(1, 'pagination.max_page_size', 10, ['legal_entity_id' => 7]));
        $this->assertSame(200, $reader->get(1, 'pagination.max_page_size', 10, ['legal_entity_id' => 8]));
        $this->assertSame(200, $reader->get(1, 'pagination.max_page_size', 10));
    }

    public function test_a_sensitive_parameter_is_encrypted_at_rest_and_decrypted_on_read(): void
    {
        $plaintext = json_encode(['value' => 'smtp-secret'], JSON_THROW_ON_ERROR);

        SystemParameter::query()->create([
            'tenant_id' => 1,
            'key' => 'mail.password',
            'value_json' => Crypt::encryptString($plaintext),
            'scope' => 'TENANT',
            'sensitive' => true,
        ]);

        $raw = (string) SystemParameter::query()->where('key', 'mail.password')->value('value_json');

        $this->assertStringNotContainsString('smtp-secret', $raw);
        $this->assertSame('smtp-secret', app(ParameterReader::class)->get(1, 'mail.password'));
    }

    public function test_a_malformed_parameter_is_treated_as_absent(): void
    {
        SystemParameter::query()->create(['tenant_id' => 1, 'key' => 'bad.shape', 'value_json' => ['x' => 1], 'scope' => 'TENANT']);

        $this->assertSame('default', app(ParameterReader::class)->get(1, 'bad.shape', 'default'));
    }

    public function test_a_stored_null_is_a_real_value_not_absent(): void
    {
        SystemParameter::query()->create(['tenant_id' => 1, 'key' => 'nullable', 'value_json' => ['value' => null], 'scope' => 'TENANT']);

        $this->assertNull(app(ParameterReader::class)->get(1, 'nullable', 'default'));
    }

    public function test_both_kernel_tables_have_their_declared_unique_indexes(): void
    {
        $dictionaryColumns = collect(Schema::getIndexes('sys_dictionaries'))
            ->filter(fn (array $index): bool => $index['unique'])
            ->map(fn (array $index): string => implode(',', $index['columns']))
            ->all();
        $parameterColumns = collect(Schema::getIndexes('sys_parameters'))
            ->filter(fn (array $index): bool => $index['unique'])
            ->map(fn (array $index): string => implode(',', $index['columns']))
            ->all();

        $this->assertContains('tenant_id,type,code', $dictionaryColumns);
        $this->assertContains('tenant_id,key,scope', $parameterColumns);
    }

    public function test_dictionary_codes_are_unique_per_tenant_and_type(): void
    {
        DictionaryEntry::query()->create(['tenant_id' => 1, 'type' => 'EMPLOYEE_STATUS', 'code' => 'ACTIVE', 'label' => '在职', 'sort_order' => 1]);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        DictionaryEntry::query()->create(['tenant_id' => 1, 'type' => 'EMPLOYEE_STATUS', 'code' => 'ACTIVE', 'label' => 'Duplicate', 'sort_order' => 2]);
    }

    public function test_parameter_keys_are_unique_per_tenant_and_scope(): void
    {
        SystemParameter::query()->create(['tenant_id' => 1, 'key' => 'k', 'value_json' => ['value' => 1], 'scope' => 'TENANT']);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        SystemParameter::query()->create(['tenant_id' => 1, 'key' => 'k', 'value_json' => ['value' => 2], 'scope' => 'TENANT']);
    }

    public function test_the_new_tables_are_owned_by_platform_in_the_architecture_scanner(): void
    {
        $owners = ModuleBoundaryScanner::TABLE_OWNERS['Platform'];

        $this->assertContains('sys_dictionaries', $owners);
        $this->assertContains('sys_parameters', $owners);
    }
}
