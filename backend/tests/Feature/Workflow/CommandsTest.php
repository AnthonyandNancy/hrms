<?php

declare(strict_types=1);

namespace Tests\Feature\Workflow;

use App\Modules\Workflow\Models\CaseModel;
use App\Modules\Workflow\Models\Effect;
use App\Modules\Workflow\Models\WorkflowTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\WorkflowTestHelpers;
use Tests\TestCase;

class CommandsTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    public function test_seed_templates_is_idempotent_and_creates_the_document_flows(): void
    {
        $this->artisan('workflow:seed-templates')->assertSuccessful();
        $this->artisan('workflow:seed-templates')->assertSuccessful();

        // Ten codes include the Time-owned attendance.correction, travel.request
        // and overtime.request workflows.
        $this->assertSame(10, WorkflowTemplate::query()->count());

        foreach ([
            'leave.request',
            'attendance.correction',
            'travel.request',
            'overtime.request',
            'transfer.request',
            'contract.renewal',
            'onboarding.request',
            'offboarding.request',
            'payroll.approval',
            'generic.approval',
        ] as $code) {
            $this->assertDatabaseHas('workflow_templates', [
                'code' => $code,
                'version' => $code === 'leave.request' ? 2 : 1,
                'status' => 'PUBLISHED',
            ]);
        }
    }

    public function test_seed_adds_leave_template_v2_over_an_existing_v1_noop(): void
    {
        $templates = app(\App\Modules\Workflow\Services\TemplateService::class);
        $templates->create(0, [
            'code' => 'leave.request',
            'name' => 'Leave request',
            'version' => 1,
            'status' => 'PUBLISHED',
            'definition' => [
                'nodes' => [
                    ['key' => 'start', 'name' => 'Start', 'type' => 'START', 'sequence' => 1],
                    ['key' => 'approval', 'name' => 'Approval', 'type' => 'APPROVAL', 'sequence' => 2, 'assignee_rule' => ['type' => 'MANAGER_OF_SUBJECT'], 'required_count' => 1],
                    ['key' => 'end', 'name' => 'End', 'type' => 'END', 'sequence' => 3],
                ],
                'effects' => [[
                    'type' => 'workflow.noop',
                    'execution' => 'LOCAL',
                    'aggregate_type' => 'case',
                    'aggregate_id_field' => 'case_id',
                ]],
            ],
        ]);

        $this->artisan('workflow:seed-templates')->assertSuccessful();

        $v1 = WorkflowTemplate::query()->where('code', 'leave.request')->where('version', 1)->firstOrFail();
        $v2 = WorkflowTemplate::query()->where('code', 'leave.request')->where('version', 2)->firstOrFail();
        $this->assertSame('workflow.noop', (string) ($v1->definition_json['effects'][0]['type'] ?? ''));
        $this->assertSame('time.leave.apply', (string) ($v2->definition_json['effects'][0]['type'] ?? ''));
        $this->assertSame('time.leave.pending.release', (string) ($v2->definition_json['effects'][0]['outcome_effect_type'] ?? ''));
    }

    public function test_workflow_check_fails_when_an_approved_case_has_no_effect_rows(): void
    {
        $this->bootWorkflow();

        CaseModel::query()->create([
            'case_no' => 'CASE-BAD-0001',
            'type' => 'leave.request',
            'subject_type' => 'employment',
            'subject_id' => 100,
            'title' => 'Broken approval without effect',
            'payload_json' => [],
            'status' => 'APPROVED',
            'effect_status' => 'NONE',
            'requester_user_id' => 10,
            'template_id' => null,
            'template_version' => 1,
            'template_snapshot_json' => [],
            'business_date' => '2026-11-23',
            'submitted_at' => now('UTC'),
            'closed_at' => now('UTC'),
        ]);

        $this->artisan('workflow:check')->assertFailed();
    }

    public function test_workflow_check_passes_for_completed_approval_with_effect_rows(): void
    {
        $this->bootWorkflow();
        $template = $this->createTemplate([], [
            'nodes' => $this->singleApprovalDefinition()['nodes'],
            'effects' => [
                ['type' => 'workflow.noop', 'execution' => 'LOCAL', 'aggregate_type' => 'case', 'aggregate_id_field' => 'case_id'],
            ],
        ]);
        $case = $this->submittedCase($template);
        $this->decideThroughApi($case->id, 'check-key-1')->assertSuccessful();

        $this->artisan('workflow:check')->assertSuccessful();
    }

    public function test_workflow_check_does_not_fail_for_approved_but_pending_scheduled_effects(): void
    {
        $this->bootWorkflow();
        $template = $this->createTemplate([], [
            'nodes' => $this->singleApprovalDefinition()['nodes'],
            'effects' => [
                [
                    'type' => 'workflow.noop',
                    'execution' => 'SCHEDULED',
                    'scheduled_for' => '2026-12-01',
                    'aggregate_type' => 'case',
                    'aggregate_id_field' => 'case_id',
                ],
            ],
        ]);
        $case = $this->submittedCase($template);
        $this->decideThroughApi($case->id, 'check-scheduled-key-1')->assertSuccessful();

        $this->assertSame(1, Effect::query()->where('case_id', $case->id)->count());
        $this->artisan('workflow:check')->assertSuccessful();
    }
}
