<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Modules\Platform\Contracts\ParameterReader;
use Illuminate\Support\Facades\DB;
use Tests\Support\PeopleTestCase;

final class DictionaryAdminTest extends PeopleTestCase
{
    public function test_dictionary_entries_are_created_and_listed_through_the_projection(): void
    {
        $created = $this->postJson('/v1/system/dictionaries', [
            'type' => 'EMPLOYEE_STATUS',
            'code' => 'PROBATION',
            'label' => '试用',
            'sort_order' => 2,
            'active' => true,
        ], ['Idempotency-Key' => 'dict-1'])->assertCreated()->assertJsonPath('data.code', 'PROBATION');

        $this->assertDatabaseHas('sys_dictionaries', [
            'type' => 'EMPLOYEE_STATUS',
            'code' => 'PROBATION',
        ]);

        $this->getJson('/v1/system/dictionaries', ['type' => 'EMPLOYEE_STATUS', 'page' => 1, 'page_size' => 50])
            ->assertOk()
            ->assertJsonStructure(['data' => ['items', 'page', 'page_size', 'total', 'as_of', 'freshness', 'source_hash']])
            ->assertJsonFragment(['code' => 'PROBATION', 'label' => '试用']);

        $this->patchJson('/v1/system/dictionaries/'.$created->json('data.id'), [
            'label' => '试用期',
            'sort_order' => 1,
            'active' => false,
        ], ['Idempotency-Key' => 'dict-2'])
            ->assertOk()
            ->assertJsonPath('data.label', '试用期')
            ->assertJsonPath('data.active', false);

        $this->assertSame([], app(\App\Modules\Platform\Contracts\DictionaryReader::class)->entries('EMPLOYEE_STATUS'));
    }

    public function test_duplicate_dictionary_code_is_a_conflict(): void
    {
        $body = [
            'type' => 'EMPLOYEE_STATUS',
            'code' => 'ACTIVE',
            'label' => '在职',
            'sort_order' => 1,
            'active' => true,
        ];

        $this->postJson('/v1/system/dictionaries', $body, ['Idempotency-Key' => 'dict-dup-1'])->assertCreated();
        $this->postJson('/v1/system/dictionaries', $body, ['Idempotency-Key' => 'dict-dup-2'])
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'DICTIONARY_ENTRY_EXISTS');
    }

    public function test_sensitive_parameter_is_encrypted_at_rest_masked_in_projection_and_readable_through_contract(): void
    {
        $this->putJson('/v1/system/parameters', [
            'key' => 'mail.password',
            'scope' => 'COMPANY',
            'value' => 'synthetic-smtp-secret',
            'sensitive' => true,
        ], ['Idempotency-Key' => 'param-1'])
            ->assertOk()
            ->assertJsonPath('data.key', 'mail.password')
            ->assertJsonPath('data.sensitive', true)
            ->assertJsonPath('data.masked', true);

        $raw = (string) DB::table('sys_parameters')->where('key', 'mail.password')->value('value_json');
        $this->assertStringNotContainsString('synthetic-smtp-secret', $raw);
        $this->assertSame('synthetic-smtp-secret', app(ParameterReader::class)->get('mail.password'));

        $this->getJson('/v1/system/parameters', ['key' => 'mail.password'])
            ->assertOk()
            ->assertJsonStructure(['data' => ['items', 'page', 'page_size', 'total', 'as_of', 'freshness', 'source_hash']])
            ->assertJsonFragment(['key' => 'mail.password', 'masked' => true, 'value' => null]);
    }

    public function test_parameter_storage_shape_is_validated_and_scope_is_whitelisted(): void
    {
        $this->putJson('/v1/system/parameters', [
            'key' => 'pagination.max_page_size',
            'scope' => 'COMPANY',
            'values' => 200,
        ], ['Idempotency-Key' => 'param-shape'])
            ->assertUnprocessable()
            ->assertJsonPath('data.reason', 'INVALID_PARAMETER_SHAPE');

        $this->putJson('/v1/system/parameters', [
            'key' => 'pagination.max_page_size',
            'scope' => 'CUSTOM_SQL',
            'value' => 200,
        ], ['Idempotency-Key' => 'param-scope'])
            ->assertUnprocessable()
            ->assertJsonPath('data.reason', 'INVALID_PARAMETER_SCOPE');

        $this->putJson('/v1/system/parameters', [
            'key' => 'pagination.max_page_size',
            'scope' => 'COMPANY',
            'value' => 200,
            'predicate' => '1=1',
        ], ['Idempotency-Key' => 'param-predicate'])
            ->assertUnprocessable()
            ->assertJsonPath('data.reason', 'INVALID_PARAMETER_SHAPE');
    }
}
