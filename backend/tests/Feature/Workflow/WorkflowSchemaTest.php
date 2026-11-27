<?php

declare(strict_types=1);

namespace Tests\Feature\Workflow;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WorkflowSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_workflow_tables_exist_with_separate_application_and_effect_columns_on_cases(): void
    {
        foreach ([
            'workflow_templates',
            'workflow_template_nodes',
            'cases',
            'workflow_instances',
            'workflow_tasks',
            'decisions',
            'case_events',
            'effects',
            'case_members',
            'workflow_delegations',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table {$table}");
        }

        $this->assertTrue(Schema::hasColumn('cases', 'status'));
        $this->assertTrue(Schema::hasColumn('cases', 'effect_status'));
        $this->assertTrue(Schema::hasColumn('cases', 'template_snapshot_json'));
        $this->assertFalse(Schema::hasColumn('cases', 'completed'), 'No combined completed flag is allowed.');
    }

    public function test_decision_uniqueness_constraint_is_present(): void
    {
        $indexes = collect(Schema::getIndexes('decisions'))->pluck('columns')->map(fn (array $columns): string => implode(',', $columns));

        $this->assertTrue(
            $indexes->contains('case_id,node_key,actor_user_id,decision_seq'),
            'Missing decision concurrency unique constraint.',
        );
    }

    public function test_effect_uniqueness_constraint_is_present(): void
    {
        $indexes = collect(Schema::getIndexes('effects'))->pluck('columns')->map(fn (array $columns): string => implode(',', $columns));

        $this->assertTrue(
            $indexes->contains('effect_type,aggregate_id,effect_seq'),
            'Missing effect idempotency unique constraint.',
        );
    }
}
