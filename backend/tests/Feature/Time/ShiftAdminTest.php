<?php

declare(strict_types=1);

namespace Tests\Feature\Time;

use App\Modules\Time\Models\ShiftTemplate;
use Tests\Support\Time\TimeHttpTestCase;
use Tests\Support\Time\TimeTestFixtures;

/**
 * M09 shift maintenance. Writes are command-driven, capability-guarded and
 * append versioned; historical rows are retained.
 *
 * Authored now; execution is NOT RUN until the shared routes/OpenAPI/IAM
 * catalogs are integrated by the parent agent and the owner runs the gate.
 */
final class ShiftAdminTest extends TimeHttpTestCase
{
    public function test_shift_create_and_disable_are_capability_guarded_and_versioned(): void
    {
        $this->actAsAttendanceSpecialist();
        $this->calendar();

        $created = $this->postJson('/v1/time/shifts', [
            'code' => 'DAY',
            'name' => '白班',
            'start_local_time' => '09:00',
            'end_local_time' => '18:00',
            'break_minutes' => 60,
            'valid_from' => '2026-11-01',
        ], ['Idempotency-Key' => 'shift-1'])->assertCreated();

        $id = (int) $created->json('data.id');
        $this->assertGreaterThan(0, $id);
        $this->assertDatabaseHas('shift_templates', ['id' => $id, 'code' => 'DAY', 'version' => 1, 'superseded_at' => null]);

        $this->postJson('/v1/time/shifts', [
            'code' => 'NIGHT',
            'name' => '夜班',
            'start_local_time' => '21:00',
            'end_local_time' => '06:00',
            'crosses_midnight' => true,
            'valid_from' => '2026-11-01',
        ], ['Idempotency-Key' => 'shift-2'])->assertCreated();

        $this->postJson("/v1/time/shifts/{$id}/disable", [
            'reason' => '合成停用：班次调整',
        ], ['Idempotency-Key' => 'shift-disable-1'])
            ->assertOk()
            ->assertJsonPath('data.code', 'DAY')
            ->assertJsonPath('data.valid_to', '2026-11-23');

        // Disable is effective-dated and preserves the historical row.
        $this->assertDatabaseHas('shift_templates', ['id' => $id, 'code' => 'DAY', 'superseded_at' => null]);
        $this->assertDatabaseHas('audit_events', ['action' => 'time.shift.created']);
        $this->assertDatabaseHas('audit_events', ['action' => 'time.shift.disabled']);
    }

    public function test_shift_update_supersedes_the_previous_version_without_rewriting_it(): void
    {
        $this->actAsAttendanceSpecialist();
        $this->calendar();

        $created = $this->postJson('/v1/time/shifts', [
            'code' => 'DAY',
            'name' => '白班',
            'start_local_time' => '09:00',
            'end_local_time' => '18:00',
            'valid_from' => '2026-11-01',
        ], ['Idempotency-Key' => 'shift-v1'])->assertCreated();

        $id = (int) $created->json('data.id');

        $updated = $this->patchJson("/v1/time/shifts/{$id}", [
            'name' => '白班（修订）',
            'break_minutes' => 45,
        ], ['Idempotency-Key' => 'shift-v2'])->assertCreated();

        $newId = (int) $updated->json('data.id');
        $this->assertNotSame($id, $newId);
        $this->assertSame(2, $updated->json('data.version'));
        $this->assertDatabaseHas('shift_templates', ['id' => $id, 'name' => '白班', 'version' => 1]);
        $this->assertDatabaseHas('shift_templates', ['id' => $newId, 'name' => '白班（修订）', 'version' => 2, 'superseded_at' => null]);
        $this->assertNotNull(ShiftTemplate::query()->whereKey($id)->value('superseded_at'));
    }

    public function test_shift_write_requires_idempotency_key_and_manage_capability(): void
    {
        $this->calendar();
        $this->actAsHttp(TimeTestFixtures::actor(
            employmentId: 1,
            scope: 'COMPANY',
            capabilities: ['time.shift.read'],
        ));

        $this->postJson('/v1/time/shifts', [
            'code' => 'DAY',
            'name' => '白班',
            'start_local_time' => '09:00',
            'end_local_time' => '18:00',
            'valid_from' => '2026-11-01',
        ], ['Idempotency-Key' => 'no-cap'])->assertForbidden();

        $this->assertDatabaseCount('shift_templates', 0);
    }

    public function test_shift_creation_is_idempotent_on_replay(): void
    {
        $this->actAsAttendanceSpecialist();
        $this->calendar();

        $payload = [
            'code' => 'DAY',
            'name' => '白班',
            'start_local_time' => '09:00',
            'end_local_time' => '18:00',
            'valid_from' => '2026-11-01',
        ];

        $first = $this->postJson('/v1/time/shifts', $payload, ['Idempotency-Key' => 'shift-replay'])
            ->assertCreated();
        $second = $this->postJson('/v1/time/shifts', $payload, ['Idempotency-Key' => 'shift-replay'])
            ->assertOk();

        $this->assertTrue($second->headers->has('Idempotency-Replayed'));
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertDatabaseCount('shift_templates', 1);
    }

    private function actAsAttendanceSpecialist(): void
    {
        $this->actAsHttp(TimeTestFixtures::actor(
            employmentId: 1,
            scope: 'COMPANY',
            capabilities: [
                'time.attendance.read',
                'time.attendance.manage',
                'time.shift.read',
                'time.shift.manage',
            ],
        ));
    }
}
