<?php

declare(strict_types=1);

namespace Tests\Feature\Workflow;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\WorkflowTestHelpers;
use Tests\TestCase;

class StatusSeparationTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    public function test_case_detail_timeline_and_list_never_expose_a_combined_completed_status(): void
    {
        $this->bootWorkflow();
        $template = $this->createTemplate([], [
            'nodes' => $this->singleApprovalDefinition()['nodes'],
            'effects' => [
                ['type' => 'workflow.noop', 'execution' => 'LOCAL', 'aggregate_type' => 'case', 'aggregate_id_field' => 'case_id'],
            ],
        ]);
        $case = $this->submittedCase($template);

        $this->decideThroughApi($case->id, 'status-separation-key-1')->assertSuccessful();

        $detail = $this->getJson("/v1/cases/{$case->id}")->assertOk();
        $detail->assertJsonPath('data.application_status', 'APPROVED')
            ->assertJsonPath('data.effect_status', 'APPLIED')
            ->assertJsonMissingPath('data.status')
            ->assertJsonMissingPath('data.completed')
            ->assertJsonMissingPath('data.is_completed');

        $timeline = $this->getJson("/v1/cases/{$case->id}/timeline")->assertOk();
        $timeline->assertJsonPath('data.application.status', 'APPROVED')
            ->assertJsonPath('data.effect.status', 'APPLIED')
            ->assertJsonMissingPath('data.status')
            ->assertJsonMissingPath('data.completed');

        $list = $this->getJson('/v1/cases')->assertOk();
        $list->assertJsonPath('data.items.0.application_status', 'APPROVED')
            ->assertJsonPath('data.items.0.effect_status', 'APPLIED')
            ->assertJsonMissingPath('data.items.0.status')
            ->assertJsonMissingPath('data.items.0.completed');

        $eventTypes = collect((array) $timeline->json('data.items'))->pluck('event_type')->all();
        $this->assertContains('case.approved', $eventTypes);
    }

    public function test_decision_response_reports_both_statuses_independently(): void
    {
        $this->bootWorkflow();
        $template = $this->createTemplate([], [
            'nodes' => $this->singleApprovalDefinition()['nodes'],
            'effects' => [
                ['type' => 'workflow.noop', 'execution' => 'LOCAL', 'aggregate_type' => 'case', 'aggregate_id_field' => 'case_id'],
            ],
        ]);
        $case = $this->submittedCase($template);

        $this->decideThroughApi($case->id, 'status-separation-key-2')
            ->assertSuccessful()
            ->assertJsonStructure(['data' => ['case_id', 'application_status', 'effect_status', 'effects']])
            ->assertJsonPath('data.application_status', 'APPROVED')
            ->assertJsonPath('data.effect_status', 'APPLIED')
            ->assertJsonMissingPath('data.status')
            ->assertJsonMissingPath('data.completed');
    }
}
