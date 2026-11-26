<?php

declare(strict_types=1);

namespace Tests\Feature\IAM;

use Tests\Support\PeopleTestCase;

final class GovernanceAdminValidationTest extends PeopleTestCase
{
    public function test_unknown_page_code_is_rejected_with_a_field_error_before_any_write(): void
    {
        $this->postJson('/v1/system/menus', [
            'page_code' => 'invented-page',
            'visible' => false,
            'sort_order' => 1,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('data.reason', 'UNKNOWN_PAGE_CODE')
            ->assertJsonStructure(['data' => ['fields' => ['page_code']]]);

        $this->assertDatabaseCount('menu_overrides', 0);
    }

    public function test_unknown_scope_template_is_rejected_with_a_field_error_before_any_write(): void
    {
        $this->putJson('/v1/system/data-scopes/CUSTOM_SQL', [
            'label' => 'Synthetic arbitrary scope',
            'anchor_type' => 'ORG_UNIT',
            'active' => true,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('data.reason', 'UNKNOWN_SCOPE_TEMPLATE')
            ->assertJsonStructure(['data' => ['fields' => ['template']]]);

        $this->assertDatabaseCount('iam_data_scope_policies', 0);
    }

    public function test_unknown_field_policy_key_is_rejected_with_a_field_error_before_any_write(): void
    {
        $this->putJson('/v1/system/field-policies/employee.not_registered', [
            'field_profile' => 'P3_RESTRICTED',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('data.reason', 'UNKNOWN_FIELD_POLICY')
            ->assertJsonStructure(['data' => ['fields' => ['field_key']]]);

        $this->assertDatabaseCount('iam_field_policies', 0);
    }

    public function test_unknown_field_profile_is_rejected_with_a_field_error_before_any_write(): void
    {
        $this->putJson('/v1/system/field-policies/employee.email', [
            'field_profile' => 'P9_IMAGINARY',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('data.reason', 'UNKNOWN_FIELD_PROFILE')
            ->assertJsonStructure(['data' => ['fields' => ['field_profile']]]);

        $this->assertDatabaseCount('iam_field_policies', 0);
    }

    public function test_governance_writes_reject_tenant_and_legal_entity_parameters(): void
    {
        $this->postJson('/v1/system/menus', [
            'page_code' => 'system-roles',
            'visible' => false,
            'tenant_id' => 1,
        ], ['Idempotency-Key' => 'menu-tenant-parameter'])
            ->assertUnprocessable()
            ->assertJsonStructure(['data' => ['fields' => ['tenant_id']]]);

        $this->putJson('/v1/system/data-scopes/ORG_UNIT', [
            'label' => '本部门',
            'anchor_type' => 'ORG_UNIT',
            'legal_entity_id' => 1,
        ], ['Idempotency-Key' => 'scope-legal-entity-parameter'])
            ->assertUnprocessable()
            ->assertJsonStructure(['data' => ['fields' => ['legal_entity_id']]]);

        $this->putJson('/v1/system/field-policies/employee.email', [
            'field_profile' => 'P1_INTERNAL',
            'tenant_id' => 1,
        ], ['Idempotency-Key' => 'field-tenant-parameter'])
            ->assertUnprocessable()
            ->assertJsonStructure(['data' => ['fields' => ['tenant_id']]]);

        $this->assertDatabaseCount('menu_overrides', 0);
        $this->assertDatabaseCount('iam_data_scope_policies', 0);
        $this->assertDatabaseCount('iam_field_policies', 0);
    }
}
