<?php

declare(strict_types=1);

namespace Tests\Feature\Workflow;

use App\Modules\Workflow\Models\CaseModel;
use App\Modules\Workflow\Models\Decision;
use App\Modules\Workflow\Models\Effect;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ProbeEffectHandler;
use Tests\Support\WorkflowTestHelpers;
use Tests\TestCase;

class ApprovedNotEffectiveTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootWorkflow();

        Schema::create('workflow_effect_probe', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('effect_id');
            $table->string('marker');
            $table->timestamp('created_at');
        });

        $handler = new ProbeEffectHandler;
        $handler->failuresRemaining = 1;
        $this->bindEffectHandler($handler);
    }

    public function test_handler_exception_keeps_decision_and_approved_application_status_but_marks_effect_failed(): void
    {
        $template = $this->createTemplate([], [
            'nodes' => $this->singleApprovalDefinition()['nodes'],
            'effects' => [
                ['type' => 'probe.local', 'execution' => 'LOCAL', 'aggregate_type' => 'case', 'aggregate_id_field' => 'case_id'],
            ],
        ]);
        $case = $this->submittedCase($template);

        $this->decideThroughApi($case->id, 'effect-fail-key-1')->assertSuccessful();

        $fresh = $case->fresh();
        $this->assertSame('APPROVED', $fresh->status->value);
        $this->assertSame('FAILED', $fresh->effect_status->value);
        $this->assertSame(1, Decision::query()->where('case_id', $case->id)->count());

        $effect = Effect::query()->where('case_id', $case->id)->firstOrFail();
        $this->assertSame('FAILED', $effect->status->value);
        $this->assertSame(1, $effect->attempts);
        $this->assertSame(0, DB::table('workflow_effect_probe')->count(), 'Handler writes must be rolled back by the savepoint.');

        $timeline = $this->getJson("/v1/cases/{$case->id}/timeline")->assertOk();
        $timeline->assertJsonPath('data.application.status', 'APPROVED');
        $timeline->assertJsonPath('data.effect.status', 'FAILED');
        $timeline->assertJsonPath('data.effect.effects.0.status', 'FAILED');

        $eventTypes = collect((array) $timeline->json('data.items'))->pluck('event_type')->filter()->all();
        $this->assertContains('case.approved', $eventTypes);
        $this->assertContains('effect.failed', $eventTypes);
    }

    public function test_retry_of_failed_effect_applies_once_and_does_not_duplicate_decision(): void
    {
        $template = $this->createTemplate([], [
            'nodes' => $this->singleApprovalDefinition()['nodes'],
            'effects' => [
                ['type' => 'probe.local', 'execution' => 'LOCAL', 'aggregate_type' => 'case', 'aggregate_id_field' => 'case_id'],
            ],
        ]);
        $case = $this->submittedCase($template);
        $this->decideThroughApi($case->id, 'effect-retry-key-1', 'APPROVE', ['reason' => 'override'])->assertSuccessful();

        $effect = Effect::query()->where('case_id', $case->id)->firstOrFail();

        $this->postJson("/v1/effects/{$effect->id}/retry", ['reason' => 'Retry after transient handler failure.'], ['Idempotency-Key' => 'retry-key'])
            ->assertOk()
            ->assertJsonPath('data.effect_status', 'APPLIED');

        $freshEffect = $effect->refresh();
        $this->assertSame('APPLIED', $freshEffect->status->value);
        $this->assertSame(2, $freshEffect->attempts);
        $this->assertSame(1, DB::table('workflow_effect_probe')->count());
        $this->assertSame(1, Decision::query()->where('case_id', $case->id)->count());
        $freshCase = $case->refresh();
        $this->assertSame('APPLIED', $freshCase->effect_status->value);

        // Retry after success is a no-op — GET not needed, POST must be safe.
        $this->postJson("/v1/effects/{$effect->id}/retry", ['reason' => 'No-op retry after applied effect.'], ['Idempotency-Key' => 'retry-key-2'])
            ->assertOk()
            ->assertJsonPath('data.effect_status', 'APPLIED');

        $this->assertSame(1, DB::table('workflow_effect_probe')->count());
        $freshEffect = $effect->refresh();
        $this->assertSame(2, $freshEffect->attempts);
    }

    private function submittedCase(\App\Modules\Workflow\Models\WorkflowTemplate $template): CaseModel
    {
        $this->createCaseThroughApi($template, ['submit' => true])->assertCreated();

        return CaseModel::query()->latest('id')->firstOrFail();
    }

    private function bindEffectHandler(ProbeEffectHandler $handler): void
    {
        $this->app->instance(\App\Modules\Workflow\Domain\EffectRegistry::class, new \App\Modules\Workflow\Domain\EffectRegistry(
            [$handler],
            ['probe.local'],
        ));
    }
}
