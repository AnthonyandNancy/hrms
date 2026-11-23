<?php

declare(strict_types=1);

namespace Tests\Feature\Workflow;

use App\Modules\Workflow\Enums\EffectStatus;
use App\Modules\Workflow\Models\CaseModel;
use App\Modules\Workflow\Models\Effect;
use App\Modules\Workflow\Services\EffectExecutor;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CountingEffectHandler;
use Tests\Support\ProbeEffectHandler;
use Tests\Support\WorkflowTestHelpers;
use Tests\TestCase;

class EffectIdempotencyTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    public function test_same_effect_applied_twice_only_runs_handler_once(): void
    {
        $this->bootWorkflow();
        $handler = new CountingEffectHandler('idem.effect');
        $this->bindEffectHandler($handler);
        $template = $this->createTemplate([], $this->effectDefinition('idem.effect'));
        $case = $this->submittedCase($template);
        $this->decideThroughApi($case->id, 'idem-key-1')->assertSuccessful();

        $effect = Effect::query()->where('case_id', $case->id)->firstOrFail();
        $this->assertSame(1, $handler->calls);
        $this->assertSame(EffectStatus::APPLIED, $effect->status);

        $executor = app(EffectExecutor::class);
        $executor->apply(1, $effect->id);
        $executor->apply(1, $effect->id);

        $this->assertSame(1, $handler->calls);
        $this->assertSame(1, $effect->fresh()?->attempts);
        $this->assertSame(1, Effect::query()->count());
    }

    public function test_unique_constraint_rejects_duplicate_effect_sequence(): void
    {
        $this->bootWorkflow();
        $this->bindEffectHandler(new CountingEffectHandler('idem.effect'));
        $template = $this->createTemplate([], $this->effectDefinition('idem.effect'));
        $case = $this->submittedCase($template);
        $this->decideThroughApi($case->id, 'unique-key-1')->assertSuccessful();

        $effect = Effect::query()->firstOrFail();

        $this->expectException(UniqueConstraintViolationException::class);

        Effect::query()->create([
            'tenant_id' => $effect->tenant_id,
            'case_id' => $effect->case_id,
            'decision_id' => $effect->decision_id,
            'effect_type' => $effect->effect_type,
            'aggregate_type' => $effect->aggregate_type,
            'aggregate_id' => $effect->aggregate_id,
            'effect_seq' => $effect->effect_seq,
            'status' => EffectStatus::SCHEDULED,
            'attempts' => 0,
            'payload_json' => [],
        ]);
    }

    public function test_retry_after_failure_works_and_max_attempts_is_enforced(): void
    {
        $this->bootWorkflow();
        $handler = new ProbeEffectHandler;
        $handler->failuresRemaining = 10;
        $this->bindEffectHandler($handler);
        $template = $this->createTemplate([], $this->probeDefinition());
        $case = $this->submittedCase($template);
        $this->decideThroughApi($case->id, 'max-attempts-key-1')->assertSuccessful();

        $effect = Effect::query()->where('case_id', $case->id)->firstOrFail();
        $this->assertSame(EffectStatus::FAILED, $effect->status);
        $this->assertSame(1, $effect->attempts);

        for ($attempt = 2; $attempt <= EffectExecutor::MAX_ATTEMPTS; $attempt++) {
            $this->postJson("/v1/effects/{$effect->id}/retry", ['reason' => 'Retry after transient failure.'], ['Idempotency-Key' => "max-retry-{$attempt}"])
                ->assertStatus(202);
        }

        $exhausted = $effect->fresh();
        $this->assertSame(EffectExecutor::MAX_ATTEMPTS, $exhausted->attempts);
        $this->assertSame(true, $exhausted->requires_escalation);
        $this->assertSame(EffectStatus::FAILED, $exhausted->status);

        $this->postJson("/v1/effects/{$effect->id}/retry", ['reason' => 'One more retry should be rejected.'], ['Idempotency-Key' => 'max-retry-final'])
            ->assertConflict()
            ->assertJsonPath('data.reason', 'EFFECT_RETRY_EXHAUSTED');

        $this->assertSame(EffectExecutor::MAX_ATTEMPTS, $effect->fresh()?->attempts);
    }

    private function submittedCase(\App\Modules\Workflow\Models\WorkflowTemplate $template): CaseModel
    {
        $this->createCaseThroughApi($template, ['submit' => true])->assertCreated();

        return CaseModel::query()->latest('id')->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function effectDefinition(string $type): array
    {
        return [
            'nodes' => $this->singleApprovalDefinition()['nodes'],
            'effects' => [
                ['type' => $type, 'execution' => 'LOCAL', 'aggregate_type' => 'case', 'aggregate_id_field' => 'case_id'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function probeDefinition(): array
    {
        return [
            'nodes' => $this->singleApprovalDefinition()['nodes'],
            'effects' => [
                ['type' => 'probe.local', 'execution' => 'LOCAL', 'aggregate_type' => 'case', 'aggregate_id_field' => 'case_id'],
            ],
        ];
    }

    private function bindEffectHandler(\App\Modules\Workflow\Contracts\EffectHandler $handler, string $type = 'idem.effect'): void
    {
        $this->app->instance(\App\Modules\Workflow\Domain\EffectRegistry::class, new \App\Modules\Workflow\Domain\EffectRegistry(
            [$handler],
            [$handler->supports('probe.local') ? 'probe.local' : $type],
        ));
    }
}
