<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Talent\Models\PerformanceCycle;
use App\Modules\Talent\Models\PerformanceReview;
use App\Modules\Talent\Models\TrainingEnrollment;
use App\Modules\Talent\Models\TrainingProgram;
use App\Modules\Talent\Models\TrainingSession;
use App\Modules\Talent\Providers\TalentServiceProvider;
use App\Modules\Workflow\Domain\EffectRegistry;
use App\Modules\Workflow\Services\DomainEffectHandlerAdapter;
use App\Modules\Workflow\Services\NullEffectHandler;

abstract class TalentTestCase extends LifecycleTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $handlers = [new NullEffectHandler];

        foreach ([\App\Modules\Lifecycle\Providers\LifecycleServiceProvider::EFFECT_HANDLERS, TalentServiceProvider::EFFECT_HANDLERS] as $classes) {
            foreach ($classes as $class) {
                $handlers[] = new DomainEffectHandlerAdapter(app($class));
            }
        }

        $types = [
            'workflow.noop',
            ...\App\Modules\Lifecycle\Providers\LifecycleServiceProvider::EFFECT_TYPES,
            ...TalentServiceProvider::EFFECT_TYPES,
        ];

        $this->app->instance(EffectRegistry::class, new EffectRegistry($handlers, $types));
    }

    protected function createPerformanceCycle(string $name = 'Synthetic 2026 cycle'): PerformanceCycle
    {
        return PerformanceCycle::query()->create([
            'name' => $name,
            'period_from' => '2026-01-01',
            'period_to' => '2026-12-31',
            'status' => 'ACTIVE',
            'recorded_at' => $this->clock->now(),
        ]);
    }

    protected function createReview(PerformanceCycle $cycle, int $employmentId, string $selfRating = '3'): PerformanceReview
    {
        return PerformanceReview::query()->create([
            'cycle_id' => (int) $cycle->id,
            'employment_id' => $employmentId,
            'status' => 'DRAFT',
            'self_rating' => $selfRating,
            'recorded_at' => $this->clock->now(),
        ]);
    }

    protected function seedTrainingSession(int $capacity = 5, int $employmentId = 1): TrainingSession
    {
        $program = TrainingProgram::query()->firstOrCreate(
            ['code' => 'SYN-LIFECYCLE'],
            [
                'title' => 'Synthetic lifecycle programme',
                'status' => 'ACTIVE',
                'default_capacity' => $capacity,
                'recorded_at' => $this->clock->now(),
            ],
        );

        $session = TrainingSession::query()->create([
            'program_id' => (int) $program->id,
            'starts_on' => '2026-12-01',
            'ends_on' => '2026-12-01',
            'starts_at' => '2026-12-01 09:00:00',
            'ends_at' => '2026-12-01 17:00:00',
            'capacity' => $capacity,
            'location' => 'Synthetic training room',
            'status' => 'SCHEDULED',
            'recorded_at' => $this->clock->now(),
        ]);

        TrainingEnrollment::query()->firstOrCreate(
            ['session_id' => (int) $session->id, 'employment_id' => $employmentId],
            [
                'status' => 'COMPLETED',
                'attended' => true,
                'completion_result' => 'PASS',
                'score' => 88,
                'completed_at' => $this->clock->now(),
                'recorded_at' => $this->clock->now(),
            ],
        );

        return $session;
    }
}
