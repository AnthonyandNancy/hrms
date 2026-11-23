<?php

declare(strict_types=1);

namespace Tests\Feature\Workflow;

use App\Modules\Platform\Models\OutboxEvent;
use App\Modules\Workflow\Models\Effect;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\WorkflowTestHelpers;
use Tests\TestCase;

class OutboxOnDecisionTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    public function test_external_effect_records_outbox_event_with_ids_and_versions_only(): void
    {
        $this->bootWorkflow();
        $template = $this->createTemplate([], [
            'nodes' => $this->singleApprovalDefinition()['nodes'],
            'effects' => [
                [
                    'type' => 'workflow.notify.external',
                    'execution' => 'EXTERNAL',
                    'aggregate_type' => 'case',
                    'aggregate_id_field' => 'case_id',
                    'outbox_event_type' => 'workflow.case.notification_requested.v1',
                ],
            ],
        ]);
        $case = $this->submittedCase($template, [
            'payload' => [
                'days' => 2,
                'amount_minor' => 1000,
                'employee_name' => 'Synthetic Person',
                'email' => 'synthetic.person@example.invalid',
                'phone' => '+10000000000',
                'id_number' => 'FAKE-ID-0001',
            ],
        ]);

        $this->decideThroughApi($case->id, 'outbox-key-1')->assertSuccessful();

        $event = OutboxEvent::query()->where('event_type', 'workflow.case.notification_requested.v1')->firstOrFail();
        $payload = $event->payload_json;

        $this->assertSame($case->id, $payload['case_id']);
        $this->assertArrayHasKey('decision_id', $payload);
        $this->assertArrayHasKey('effect_id', $payload);
        $this->assertArrayHasKey('template_version', $payload);
        $this->assertSame(1, $payload['template_version']);

        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        foreach (['Synthetic Person', 'synthetic.person@example.invalid', '+10000000000', 'FAKE-ID-0001', 'days', 'amount_minor', 'email', 'phone', 'id_number'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded, "Outbox payload leaked {$forbidden}");
        }

        $this->assertSame(['case_id', 'decision_id', 'effect_id', 'effect_type', 'effect_seq', 'template_version'], array_keys($payload));
    }

    public function test_external_effect_is_not_silently_marked_applied(): void
    {
        $this->bootWorkflow();
        $template = $this->createTemplate([], [
            'nodes' => $this->singleApprovalDefinition()['nodes'],
            'effects' => [
                ['type' => 'workflow.notify.external', 'execution' => 'EXTERNAL', 'aggregate_type' => 'case', 'aggregate_id_field' => 'case_id'],
            ],
        ]);
        $case = $this->submittedCase($template);

        $this->decideThroughApi($case->id, 'external-key-1')->assertSuccessful();

        $freshCase = $case->refresh();
        $this->assertSame('APPROVED', $freshCase->status->value);
        $this->assertSame('SCHEDULED', $freshCase->effect_status->value);
        $effect = Effect::query()->where('case_id', $case->id)->firstOrFail();
        $this->assertSame('SCHEDULED', $effect->status->value);
        $this->assertSame(0, $effect->attempts);
    }
}
