<?php

declare(strict_types=1);

namespace Tests\Feature\Reporting;

use App\Modules\People\Models\Employment;
use App\Modules\Reporting\Models\ReportDefinition;

/**
 * D4 / K12: custom reports are stored definitions over the code-owned
 * whitelist. No caller-supplied SQL, predicates or unknown columns may enter
 * the Reporting module, and a definition read/write never mutates a business
 * fact table.
 */
final class ReportDefinitionTest extends ReportingTestCase
{
    public function test_custom_definition_rejects_sql_and_unknown_fields(): void
    {
        $ids = $this->seedSyntheticWorkforce();
        $this->authenticate(
            (int) $ids['broad_user']->id,
            (int) $ids['employment_a1_id'],
            (int) $ids['broad_user']->auth_version,
        );

        $this->postJson('/v1/reporting/definitions', [
            'code' => 'CUSTOM_1',
            'name' => 'Custom',
            'source_projection' => 'workforce',
            'fields' => ['employee', 'not_a_field'],
            'filters' => [],
        ], ['Idempotency-Key' => 'rd-unknown-field'])
            ->assertStatus(422)
            ->assertJsonPath('data.reason', 'UNKNOWN_REPORT_FIELD');

        $this->postJson('/v1/reporting/definitions', [
            'code' => 'CUSTOM_2',
            'name' => 'Custom',
            'source_projection' => 'workforce',
            'fields' => ['employee; DROP TABLE employments'],
            'filters' => [],
        ], ['Idempotency-Key' => 'rd-sql-field'])
            ->assertStatus(422)
            ->assertJsonPath('data.reason', 'REPORT_SQL_NOT_ALLOWED');

        $this->postJson('/v1/reporting/definitions', [
            'code' => 'CUSTOM_3',
            'name' => 'Custom',
            'source_projection' => 'workforce',
            'fields' => ['employee'],
            'filters' => ['where' => '1 = 1'],
        ], ['Idempotency-Key' => 'rd-sql-filter'])
            ->assertStatus(422)
            ->assertJsonPath('data.reason', 'UNKNOWN_REPORT_FILTER');

        $this->assertSame(0, ReportDefinition::query()->where('is_system', false)->count());
    }

    public function test_custom_definition_is_stored_from_the_approved_whitelist_and_runs_as_a_projection(): void
    {
        $ids = $this->seedSyntheticWorkforce();
        $this->authenticate(
            (int) $ids['broad_user']->id,
            (int) $ids['employment_a1_id'],
            (int) $ids['broad_user']->auth_version,
        );

        $employmentsBefore = Employment::query()->count();
        $personsBefore = \App\Modules\People\Models\Person::query()->count();

        $created = $this->postJson('/v1/reporting/definitions', [
            'code' => 'CUSTOM_HEADCOUNT_A',
            'name' => 'Synthetic custom headcount',
            'description' => 'Approved headcount columns only.',
            'source_projection' => 'workforce',
            'fields' => ['employee_no', 'org_path', 'status'],
            'filters' => ['org_unit_id'],
        ], ['Idempotency-Key' => 'rd-create-1'])
            ->assertCreated()
            ->assertJsonPath('data.code', 'CUSTOM_HEADCOUNT_A')
            ->assertJsonPath('data.isSystem', false);

        $definitionId = (int) $created->json('data.id');
        $this->assertGreaterThan(0, $definitionId);
        $this->assertSame(
            ['employee_no', 'org_path', 'status'],
            array_values(array_map(
                static fn (array $field): string => (string) $field['key'],
                (array) $created->json('data.fields'),
            )),
        );

        $list = $this->getJson('/v1/reporting/definitions?page=1&page_size=50')->assertOk();
        $list->assertJsonStructure(['data' => [
            'items', 'page', 'page_size', 'total', 'as_of', 'freshness', 'source_hash',
            'partial', 'partial_reason', 'drilldown',
        ]]);
        $this->assertGreaterThanOrEqual(1, (int) $list->json('data.total'));
        $this->assertContains('CUSTOM_HEADCOUNT_A', array_column((array) $list->json('data.items'), 'code'));

        foreach ((array) $list->json('data.items') as $definition) {
            $this->assertArrayNotHasKey('sourceProjection', (array) $definition);
            $this->assertArrayNotHasKey('source_projection', (array) $definition);
            $this->assertNotSame('', (string) ($definition['domain'] ?? ''));
        }

        $labels = [];
        foreach ((array) $list->json('data.items') as $definition) {
            foreach ((array) ($definition['fields'] ?? []) as $field) {
                $labels[] = (string) ($field['label'] ?? '');
            }
        }
        $this->assertContains('应发工资（元）', $labels);
        $this->assertContains('实发工资（元）', $labels);
        $this->assertNotContains('Gross (minor units)', $labels);

        $run = $this->postJson('/v1/reporting/run', [
            'definition_code' => 'CUSTOM_HEADCOUNT_A',
            'filters' => ['org_unit_id' => $ids['org_a_id']],
            'as_of' => self::BUSINESS_DATE,
        ])->assertOk();

        $run->assertJsonStructure(['data' => [
            'items', 'page', 'page_size', 'total', 'as_of', 'freshness', 'source_hash',
            'partial', 'partial_reason', 'drilldown', 'columns', 'chart',
        ]]);
        $run->assertJsonPath('data.as_of', self::BUSINESS_DATE)
            ->assertJsonPath('data.freshness', 'MATERIALIZED')
            ->assertJsonPath('data.total', 2);
        $this->assertCount(2, (array) $run->json('data.items'));
        $this->assertArrayNotHasKey('salary', (array) $run->json('data.items.0'));

        $this->assertSame($employmentsBefore, Employment::query()->count());
        $this->assertSame($personsBefore, \App\Modules\People\Models\Person::query()->count());
        $this->assertDatabaseHas('report_definitions', [
            'id' => $definitionId,
            'code' => 'CUSTOM_HEADCOUNT_A',
            'is_system' => false,
        ]);
    }

    public function test_definition_writes_require_a_capability_and_idempotency_key(): void
    {
        $ids = $this->seedSyntheticWorkforce();
        $this->authenticate(
            (int) $ids['broad_user']->id,
            (int) $ids['employment_a1_id'],
            (int) $ids['broad_user']->auth_version,
        );

        $payload = [
            'code' => 'CUSTOM_IDEMPOTENT',
            'name' => 'Synthetic idempotent custom report',
            'source_projection' => 'workforce',
            'fields' => ['employee_no'],
            'filters' => [],
        ];

        $this->postJson('/v1/reporting/definitions', $payload)->assertStatus(422)
            ->assertJsonPath('data.reason', 'IDEMPOTENCY_KEY_REQUIRED');

        $this->postJson('/v1/reporting/definitions', $payload, ['Idempotency-Key' => 'rd-idem-1'])->assertCreated();
        $this->postJson('/v1/reporting/definitions', $payload, ['Idempotency-Key' => 'rd-idem-1'])
            ->assertOk()
            ->assertHeader('Idempotency-Replayed', 'true');

        $this->assertSame(1, ReportDefinition::query()->where('code', 'CUSTOM_IDEMPOTENT')->count());

        $this->postJson('/v1/reporting/definitions', [
            ...$payload,
            'name' => 'Different payload',
        ], ['Idempotency-Key' => 'rd-idem-1'])
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'IDEMPOTENCY_PAYLOAD_MISMATCH');
    }
}
