<?php

declare(strict_types=1);

namespace Tests\Feature\Lifecycle;

use App\Modules\Talent\Models\PerformanceTemplate;
use App\Modules\Talent\Models\Requisition;
use App\Modules\Talent\Models\TrainingEnrollment;
use App\Modules\Talent\Models\TrainingProgram;
use App\Modules\Talent\Models\TrainingSession;
use Tests\Support\TalentTestCase;

final class TalentRedLinesTest extends TalentTestCase
{
    public function test_template_weights_must_sum_to_one_hundred_before_publish(): void
    {
        $this->actAs(10, 1);

        $this->postJson('/v1/performance/templates', [
            'code' => 'TPL-ENG',
            'name' => 'Engineering template',
            'sections' => [
                ['key' => 'goals', 'weight' => 70],
                ['key' => 'values', 'weight' => 20],
            ],
        ], ['Idempotency-Key' => 'tpl-weight-invalid'])
            ->assertUnprocessable()
            ->assertJsonPath('data.reason', 'INVALID_WEIGHT_TOTAL');

        $this->assertSame(0, PerformanceTemplate::query()->count());
    }

    public function test_template_with_full_weights_is_versioned_and_listable(): void
    {
        $this->actAs(10, 1);

        $created = $this->postJson('/v1/performance/templates', [
            'code' => 'TPL-ENG',
            'name' => 'Engineering template',
            'sections' => [
                ['key' => 'goals', 'weight' => 70],
                ['key' => 'values', 'weight' => 30],
            ],
        ], ['Idempotency-Key' => 'tpl-weight-valid'])->assertCreated();

        $id = (int) $created->json('data.template_id');
        $this->assertDatabaseHas('performance_templates', ['id' => $id, 'code' => 'TPL-ENG', 'version' => 1]);

        $this->getJson('/v1/performance/templates')->assertOk()->assertJsonPath('data.items.0.code', 'TPL-ENG');
    }

    public function test_unapproved_requisition_cannot_open_an_approved_posting(): void
    {
        $this->actAs(10, 1);
        $requisition = Requisition::query()->create([
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'org_unit_id' => 1,
            'requisition_no' => 'REQ-DRAFT',
            'title' => 'Synthetic role',
            'status' => 'PENDING_APPROVAL',
            'headcount' => 1,
            'recorded_at' => $this->clock->now(),
        ]);

        $this->postJson("/v1/recruitment/requisitions/{$requisition->id}/openings", [
            'channel' => 'INTERNAL',
        ], ['Idempotency-Key' => 'opening-unapproved'])
            ->assertConflict()
            ->assertJsonPath('data.reason', 'REQUISITION_NOT_APPROVED');
    }

    public function test_duplicate_training_enrollment_is_idempotent(): void
    {
        $this->actAs(10, 1);
        $program = TrainingProgram::query()->create([
            'tenant_id' => 1,
            'code' => 'SAFE-101',
            'title' => 'Synthetic safety',
            'status' => 'ACTIVE',
            'default_capacity' => 5,
            'recorded_at' => $this->clock->now(),
        ]);
        $session = TrainingSession::query()->create([
            'tenant_id' => 1,
            'program_id' => $program->id,
            'starts_on' => '2026-12-01',
            'ends_on' => '2026-12-01',
            'starts_at' => '2026-12-01 09:00:00',
            'ends_at' => '2026-12-01 12:00:00',
            'capacity' => 2,
            'status' => 'SCHEDULED',
            'recorded_at' => $this->clock->now(),
        ]);

        $payload = ['session_id' => $session->id, 'employment_id' => 1];
        $first = $this->postJson('/v1/training/enrollments', $payload, ['Idempotency-Key' => 'enroll-1'])->assertCreated();
        $second = $this->postJson('/v1/training/enrollments', $payload, ['Idempotency-Key' => 'enroll-2'])->assertCreated();

        $this->assertSame($first->json('data.enrollment_id'), $second->json('data.enrollment_id'));
        $this->assertSame(1, TrainingEnrollment::query()->count());
    }
}
