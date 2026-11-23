<?php

declare(strict_types=1);

namespace Tests\Feature\Workflow;

use App\Modules\Workflow\Enums\EffectStatus;
use App\Modules\Workflow\Models\Effect;
use App\Shared\Time\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CountingEffectHandler;
use Tests\Support\WorkflowTestHelpers;
use Tests\TestCase;

class ScheduledEffectTest extends TestCase
{
    use RefreshDatabase;
    use WorkflowTestHelpers;

    public function test_future_effect_is_not_applied_today_and_applies_once_on_due_date(): void
    {
        $this->bootWorkflow();
        $handler = new CountingEffectHandler('scheduled.effect');
        $this->bindEffectHandler($handler, 'scheduled.effect');
        $template = $this->createTemplate([], [
            'nodes' => $this->singleApprovalDefinition()['nodes'],
            'effects' => [
                [
                    'type' => 'scheduled.effect',
                    'execution' => 'SCHEDULED',
                    'scheduled_for' => '2026-12-01',
                    'aggregate_type' => 'case',
                    'aggregate_id_field' => 'case_id',
                ],
            ],
        ]);
        $case = $this->submittedCase($template);

        $this->decideThroughApi($case->id, 'scheduled-key-1')->assertSuccessful();

        $effect = Effect::query()->where('case_id', $case->id)->firstOrFail();
        $this->assertSame(EffectStatus::SCHEDULED, $effect->status);
        $this->assertSame(0, $handler->calls);
        $this->assertSame('SCHEDULED', $case->fresh()?->effect_status->value);

        $this->artisan('effects:run-due', ['--limit' => 10])->assertSuccessful();
        $this->assertSame(0, $handler->calls);

        $this->clock->setNow(CarbonImmutable::parse('2026-12-01T09:00:00Z'));
        $this->app->instance(Clock::class, $this->clock);

        $this->artisan('effects:run-due', ['--limit' => 10])->assertSuccessful();
        $this->assertSame(1, $handler->calls);
        $freshEffect = $effect->refresh();
        $this->assertSame(EffectStatus::APPLIED, $freshEffect->status);
        $this->assertSame(1, $freshEffect->attempts);

        // Second run (and a missed-day catch-up) must not apply twice.
        $this->artisan('effects:run-due', ['--limit' => 10])->assertSuccessful();
        $this->assertSame(1, $handler->calls);
        $this->assertSame(1, Effect::query()->count());
    }

    public function test_due_scheduler_only_processes_effects_that_are_due(): void
    {
        $this->bootWorkflow();
        $handler = new CountingEffectHandler('scheduled.effect');
        $this->bindEffectHandler($handler, 'scheduled.effect');

        $effects = [];
        foreach (['2026-11-23', '2026-11-24', '2026-12-31'] as $date) {
            $template = $this->createTemplate([
                'code' => 'scheduled.'.str_replace('-', '', $date),
                'version' => 1,
            ], [
                'nodes' => $this->singleApprovalDefinition()['nodes'],
                'effects' => [[
                    'type' => 'scheduled.effect',
                    'execution' => 'SCHEDULED',
                    'scheduled_for' => $date,
                    'aggregate_type' => 'case',
                    'aggregate_id_field' => 'case_id',
                ]],
            ]);
            $case = $this->submittedCase($template);
            $this->decideThroughApi($case->id, 'due-key-'.$date)->assertSuccessful();
            $effects[$date] = Effect::query()->where('case_id', $case->id)->firstOrFail();
        }

        $this->artisan('effects:run-due', ['--limit' => 10])->assertSuccessful();

        $this->assertSame(1, $handler->calls);
        $this->assertSame(EffectStatus::APPLIED, $effects['2026-11-23']->fresh()?->status);
        $this->assertSame(EffectStatus::SCHEDULED, $effects['2026-11-24']->fresh()?->status);
        $this->assertSame(EffectStatus::SCHEDULED, $effects['2026-12-31']->fresh()?->status);
    }

    public function test_due_scheduler_limit_is_respected(): void
    {
        $this->bootWorkflow();
        $handler = new CountingEffectHandler('scheduled.effect');
        $this->bindEffectHandler($handler, 'scheduled.effect');

        foreach ([1, 2, 3] as $index) {
            $template = $this->createTemplate(['code' => "limit.{$index}"], [
                'nodes' => $this->singleApprovalDefinition()['nodes'],
                'effects' => [[
                    'type' => 'scheduled.effect',
                    'execution' => 'SCHEDULED',
                    'scheduled_for' => '2026-11-23',
                    'aggregate_type' => 'case',
                    'aggregate_id_field' => 'case_id',
                ]],
            ]);
            $case = $this->submittedCase($template);
            $this->decideThroughApi($case->id, "limit-key-{$index}")->assertSuccessful();
        }

        $this->artisan('effects:run-due', ['--limit' => 2])->assertSuccessful();
        $this->assertSame(2, $handler->calls);

        $this->artisan('effects:run-due', ['--limit' => 2])->assertSuccessful();
        $this->assertSame(3, $handler->calls);
    }
}
