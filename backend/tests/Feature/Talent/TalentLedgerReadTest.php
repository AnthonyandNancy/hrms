<?php

declare(strict_types=1);

namespace Tests\Feature\Talent;

use App\Modules\Talent\Models\PerformanceCycle;
use App\Modules\Talent\Models\PerformanceReview;
use App\Modules\Talent\Models\TrainingProgram;
use App\Modules\Talent\Models\TrainingSession;
use Tests\Support\TalentTestCase;

final class TalentLedgerReadTest extends TalentTestCase
{
    public function test_performance_reviews_can_be_listed_and_filtered_by_cycle(): void
    {
        $cycle = PerformanceCycle::query()->create([
            'tenant_id' => 1,
            'name' => 'Synthetic 2026 review',
            'period_from' => '2026-01-01',
            'period_to' => '2026-12-31',
            'status' => 'ACTIVE',
            'recorded_at' => $this->clock->now(),
        ]);
        $review = PerformanceReview::query()->create([
            'tenant_id' => 1,
            'cycle_id' => $cycle->id,
            'employment_id' => 1,
            'manager_employment_id' => 2,
            'goals_text' => 'Synthetic goals',
            'self_assessment' => null,
            'self_rating' => null,
            'status' => 'DRAFT',
            'recorded_at' => $this->clock->now(),
        ]);

        $this->getJson('/v1/performance/reviews?cycle_id='.$cycle->id)
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.review_id', $review->id)
            ->assertJsonPath('data.items.0.status', 'DRAFT');

        $this->getJson('/v1/performance/reviews?cycle_id=999999')
            ->assertOk()
            ->assertJsonPath('data.total', 0);
    }

    public function test_training_sessions_can_be_listed_and_filtered_by_program(): void
    {
        $program = TrainingProgram::query()->create([
            'tenant_id' => 1,
            'code' => 'SYN-PROG',
            'title' => 'Synthetic program',
            'status' => 'ACTIVE',
            'recorded_at' => $this->clock->now(),
        ]);
        $session = TrainingSession::query()->create([
            'tenant_id' => 1,
            'program_id' => $program->id,
            'starts_on' => '2026-12-01',
            'ends_on' => '2026-12-01',
            'starts_at' => '2026-12-01 09:00:00',
            'ends_at' => '2026-12-01 12:00:00',
            'capacity' => 20,
            'location' => 'Synthetic room',
            'status' => 'SCHEDULED',
            'recorded_at' => $this->clock->now(),
        ]);

        $this->getJson('/v1/training/sessions?program_id='.$program->id)
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.session_id', $session->id)
            ->assertJsonPath('data.items.0.capacity', 20);

        $this->getJson('/v1/training/sessions?program_id=999999')
            ->assertOk()
            ->assertJsonPath('data.total', 0);
    }
}
