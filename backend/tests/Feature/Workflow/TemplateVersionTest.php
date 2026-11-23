<?php

declare(strict_types=1);

namespace Tests\Feature\Workflow;

use App\Modules\Workflow\Models\CaseModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\WorkflowTestHelpers;
use Tests\TestCase;

class TemplateVersionTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    public function test_version_two_does_not_retroactively_change_version_one_cases_and_new_cases_use_latest_explicit_version(): void
    {
        $this->bootWorkflow();
        $v1 = $this->createTemplate(['version' => 1], $this->singleApprovalDefinition([], 'USER', ['user_id' => 10]));
        $oldCase = $this->submittedCase($v1);

        $v2Definition = $this->singleApprovalDefinition([], 'USER', ['user_id' => 11]);
        $v2Definition['nodes'][1]['name'] = 'Second-level review';
        $v2 = $this->createTemplate(['version' => 2], $v2Definition);

        $freshOldCase = $oldCase->refresh();
        $this->assertSame(1, $freshOldCase->template_version);
        $this->assertSame('Manager approval', $freshOldCase->template_snapshot_json['nodes'][1]['name']);
        $this->assertSame(10, $freshOldCase->template_snapshot_json['nodes'][1]['assignee_rule']['user_id']);

        $newCase = $this->submittedCase($v2);
        $freshNewCase = $newCase->refresh();
        $this->assertSame(2, $freshNewCase->template_version);
        $this->assertSame('Second-level review', $freshNewCase->template_snapshot_json['nodes'][1]['name']);
        $this->assertSame(11, $freshNewCase->template_snapshot_json['nodes'][1]['assignee_rule']['user_id']);

        // Old case still routes to v1's approver (10), new to v2's (11).
        $this->decideThroughApi($oldCase->id, 'template-v1-key')->assertSuccessful();
        $this->actAs(11);
        $this->decideThroughApi($newCase->id, 'template-v2-key')->assertSuccessful();

        $this->assertDatabaseHas('decisions', ['case_id' => $oldCase->id, 'template_version' => 1]);
        $this->assertDatabaseHas('decisions', ['case_id' => $newCase->id, 'template_version' => 2]);
    }

    public function test_template_creation_is_idempotent_for_same_tenant_code_version(): void
    {
        $this->bootWorkflow();
        $first = $this->createTemplate(['version' => 1]);
        $second = $this->createTemplate(['version' => 1]);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, \App\Modules\Workflow\Models\WorkflowTemplate::query()->count());
    }

    public function test_template_definition_rejects_unsupported_node_types_and_assignee_rules(): void
    {
        $this->bootWorkflow();

        $invalidNode = $this->singleApprovalDefinition();
        $invalidNode['nodes'][1]['type'] = 'SCRIPT';

        $invalidRule = $this->singleApprovalDefinition([], 'USER', ['user_id' => 10]);
        $invalidRule['nodes'][1]['assignee_rule'] = ['type' => 'SQL'];

        foreach ([$invalidNode, $invalidRule] as $definition) {
            try {
                $this->createTemplate(['code' => 'invalid-'.bin2hex(random_bytes(3))], $definition);
                $this->fail('Invalid template definition must be rejected.');
            } catch (\App\Shared\Exceptions\ValidationException $exception) {
                $this->assertSame('VALIDATION_FAILED', $exception->errorCode()->value);
            }
        }

        $this->assertSame(0, \App\Modules\Workflow\Models\WorkflowTemplate::query()->count());
    }

    public function test_template_http_create_is_validated_and_capability_guarded(): void
    {
        $this->bootWorkflow();
        $definition = $this->singleApprovalDefinition();

        $this->postJson('/v1/workflow/templates', [
            'code' => 'http.template',
            'name' => 'HTTP template',
            'version' => 1,
            'status' => 'PUBLISHED',
            'definition' => $definition,
        ], ['Idempotency-Key' => 'template-http-key'])->assertCreated()
            ->assertJsonPath('data.version', 1);

        $this->actAs(10, capabilities: ['workflow.case.read']);
        $this->postJson('/v1/workflow/templates', [
            'code' => 'http.template.2',
            'name' => 'HTTP template 2',
            'version' => 1,
            'status' => 'PUBLISHED',
            'definition' => $definition,
        ], ['Idempotency-Key' => 'template-http-key-2'])->assertForbidden();
    }

    protected function submittedCase(\App\Modules\Workflow\Models\WorkflowTemplate $template): CaseModel
    {
        $this->createCaseThroughApi($template, ['submit' => true])->assertCreated();

        return CaseModel::query()->latest('id')->firstOrFail();
    }
}
