<?php

declare(strict_types=1);

namespace Tests\Feature\IAM;

use Tests\Support\PeopleTestCase;

final class ScopePolicyWriteTest extends PeopleTestCase
{
    public function test_admin_updates_a_whitelisted_scope_template_and_projection_returns_it(): void
    {
        $this->putJson('/v1/system/data-scopes/ORG_SUBTREE', [
            'label' => '本部门及子部门',
            'anchor_type' => 'ORG_SUBTREE',
            'active' => true,
        ], ['Idempotency-Key' => 'scope-1'])->assertOk()->assertJsonPath('data.template', 'ORG_SUBTREE');

        $this->getJson('/v1/system/data-scopes')
            ->assertOk()
            ->assertJsonStructure(['data' => ['items', 'page', 'page_size', 'total', 'as_of', 'freshness', 'source_hash']])
            ->assertJsonFragment(['template' => 'ORG_SUBTREE', 'anchor_type' => 'ORG_SUBTREE']);

        $this->assertDatabaseHas('iam_data_scope_policies', [
            'template_code' => 'ORG_SUBTREE',
            'anchor_type' => 'ORG_SUBTREE',
        ]);
    }

    public function test_unknown_scope_template_and_arbitrary_predicates_are_rejected(): void
    {
        $this->putJson('/v1/system/data-scopes/CUSTOM_SQL', [
            'label' => 'Arbitrary',
            'anchor_type' => 'ORG_UNIT',
        ], ['Idempotency-Key' => 'scope-unknown'])
            ->assertUnprocessable()
            ->assertJsonPath('data.reason', 'UNKNOWN_SCOPE_TEMPLATE');

        $this->putJson('/v1/system/data-scopes/ORG_SUBTREE', [
            'label' => 'Arbitrary predicate',
            'anchor_type' => 'ORG_SUBTREE',
            'predicate' => 'department_id = 42',
        ], ['Idempotency-Key' => 'scope-predicate'])
            ->assertUnprocessable()
            ->assertJsonPath('data.reason', 'ARBITRARY_PREDICATE_FORBIDDEN');

        $this->putJson('/v1/system/data-scopes/ORG_SUBTREE', [
            'label' => 'Mismatched anchor',
            'anchor_type' => 'SELF',
        ], ['Idempotency-Key' => 'scope-anchor'])
            ->assertUnprocessable()
            ->assertJsonPath('data.reason', 'SCOPE_TEMPLATE_ANCHOR_MISMATCH');
    }

    public function test_scope_write_requires_an_idempotency_key(): void
    {
        $this->putJson('/v1/system/data-scopes/ORG_SUBTREE', [
            'label' => '本部门及子部门',
            'anchor_type' => 'ORG_SUBTREE',
        ])->assertUnprocessable()->assertJsonPath('data.reason', 'IDEMPOTENCY_KEY_REQUIRED');
    }
}
