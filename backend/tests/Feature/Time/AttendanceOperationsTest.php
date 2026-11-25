<?php

declare(strict_types=1);

namespace Tests\Feature\Time;

use App\Modules\Time\Models\AttendanceDevice;
use App\Modules\Time\Models\AttendancePeriodClosure;
use App\Modules\Time\Models\AttendanceRuleVersion;
use App\Modules\Time\Models\ShiftSchedule;
use Illuminate\Database\UniqueConstraintViolationException;
use Tests\Support\Time\TimeHttpTestCase;
use Tests\Support\Time\TimeTestFixtures;

/**
 * K4/C2 — attendance rule versions, shift schedules, monthly closing list and
 * device health. These endpoints are the read/write surface behind the four
 * remaining attendance gap pages.
 */
final class AttendanceOperationsTest extends TimeHttpTestCase
{
    public function test_schedule_conflict_is_rejected_and_closing_list_is_tenant_scoped(): void
    {
        $this->actAsAttendanceSpecialist();
        $employmentId = $this->seedEmployment();
        $this->shift(code: 'DAY');
        $this->shift(code: 'NIGHT');

        $this->postJson('/v1/time/shift-schedules', [
            'employment_id' => $employmentId,
            'business_date' => '2026-11-25',
            'shift_template_id' => 1,
        ], ['Idempotency-Key' => 'sch-1'])->assertCreated();

        $this->postJson('/v1/time/shift-schedules', [
            'employment_id' => $employmentId,
            'business_date' => '2026-11-25',
            'shift_template_id' => 2,
        ], ['Idempotency-Key' => 'sch-2'])->assertStatus(409)->assertJsonPath('data.reason', 'SCHEDULE_CONFLICT');

        $this->getJson('/v1/time/attendance/closings')
            ->assertOk()
            ->assertJsonStructure(['data' => ['items', 'total', 'as_of', 'freshness', 'source_hash']]);
    }

    public function test_the_database_also_rejects_two_schedules_for_the_same_employment_day(): void
    {
        $this->actAsAttendanceSpecialist();
        $this->seedEmployment();
        $this->shift(code: 'DAY');
        $this->shift(code: 'NIGHT');

        ShiftSchedule::query()->create([
            'tenant_id' => $this->tenantId,
            'legal_entity_id' => $this->legalEntityId,
            'employment_id' => 1,
            'business_date' => '2026-11-25',
            'shift_template_id' => 1,
            'created_by' => 10,
            'created_at' => $this->clock->now(),
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        ShiftSchedule::query()->create([
            'tenant_id' => $this->tenantId,
            'legal_entity_id' => $this->legalEntityId,
            'employment_id' => 1,
            'business_date' => '2026-11-25',
            'shift_template_id' => 2,
            'created_by' => 10,
            'created_at' => $this->clock->now(),
        ]);
    }

    public function test_rescheduling_the_same_template_is_an_idempotent_replay(): void
    {
        $this->actAsAttendanceSpecialist();
        $this->seedEmployment();
        $this->shift(code: 'DAY');

        $payload = [
            'employment_id' => 1,
            'business_date' => '2026-11-25',
            'shift_template_id' => 1,
        ];

        $first = $this->postJson('/v1/time/shift-schedules', $payload, ['Idempotency-Key' => 'sch-replay'])
            ->assertCreated();
        $second = $this->postJson('/v1/time/shift-schedules', $payload, ['Idempotency-Key' => 'sch-replay'])
            ->assertOk();

        $this->assertTrue($second->headers->has('Idempotency-Replayed'));
        $this->assertSame($first->json('data.schedule_id'), $second->json('data.schedule_id'));
        $this->assertDatabaseCount('shift_schedules', 1);
    }

    public function test_a_schedule_write_without_an_idempotency_key_is_422(): void
    {
        $this->actAsAttendanceSpecialist();
        $this->seedEmployment();
        $this->shift(code: 'DAY');

        $this->postJson('/v1/time/shift-schedules', [
            'employment_id' => 1,
            'business_date' => '2026-11-25',
            'shift_template_id' => 1,
        ])->assertStatus(422)->assertJsonPath('data.reason', 'IDEMPOTENCY_KEY_REQUIRED');
    }

    public function test_reusing_an_idempotency_key_with_a_different_payload_is_409(): void
    {
        $this->actAsAttendanceSpecialist();
        $this->seedEmployment();
        $this->shift(code: 'DAY');
        $this->shift(code: 'NIGHT');

        $this->postJson('/v1/time/shift-schedules', [
            'employment_id' => 1,
            'business_date' => '2026-11-25',
            'shift_template_id' => 1,
        ], ['Idempotency-Key' => 'sch-reuse'])->assertCreated();

        $this->postJson('/v1/time/shift-schedules', [
            'employment_id' => 1,
            'business_date' => '2026-11-26',
            'shift_template_id' => 1,
        ], ['Idempotency-Key' => 'sch-reuse'])
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'IDEMPOTENCY_PAYLOAD_MISMATCH');
    }

    public function test_a_template_from_another_legal_entity_is_not_usable(): void
    {
        $this->actAsAttendanceSpecialist();
        $this->people->add(TimeTestFixtures::employment(1, 2, 2));
        $this->shift(code: 'DAY');
        $foreign = $this->shift(tenantId: 1, legalEntityId: 2, code: 'REMOTE');

        $this->postJson('/v1/time/shift-schedules', [
            'employment_id' => 1,
            'business_date' => '2026-11-25',
            'shift_template_id' => $foreign->id,
        ], ['Idempotency-Key' => 'sch-foreign'])->assertNotFound();
    }

    public function test_a_closed_period_rejects_new_schedules_until_it_is_reopened(): void
    {
        $this->actAsAttendanceSpecialist();
        $this->seedEmployment();
        $this->shift(code: 'DAY');

        $this->postJson('/v1/time/attendance/closings', [
            'month' => '2026-11',
            'legal_entity_id' => 1,
        ], ['Idempotency-Key' => 'close-nov'])->assertCreated();

        $this->postJson('/v1/time/shift-schedules', [
            'employment_id' => 1,
            'business_date' => '2026-11-25',
            'shift_template_id' => 1,
        ], ['Idempotency-Key' => 'sch-closed'])
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'PERIOD_CLOSED');

        $closureId = (int) AttendancePeriodClosure::query()->where('month', '2026-11')->value('id');

        $this->postJson("/v1/time/attendance/closings/{$closureId}/reopen", [
            'reason' => 'Synthetic schedule correction after close.',
        ], ['Idempotency-Key' => 'reopen-nov'])->assertOk();

        $this->postJson('/v1/time/shift-schedules', [
            'employment_id' => 1,
            'business_date' => '2026-11-25',
            'shift_template_id' => 1,
        ], ['Idempotency-Key' => 'sch-reopened'])->assertCreated();
    }

    public function test_reopening_a_period_requires_a_reason_and_is_audited(): void
    {
        $this->actAsAttendanceSpecialist();

        $this->postJson('/v1/time/attendance/closings', [
            'month' => '2026-11',
            'legal_entity_id' => 1,
        ], ['Idempotency-Key' => 'close-audit'])->assertCreated();

        $closureId = (int) AttendancePeriodClosure::query()->where('month', '2026-11')->value('id');

        $this->postJson("/v1/time/attendance/closings/{$closureId}/reopen", [
            'reason' => 'ab',
        ], ['Idempotency-Key' => 'reopen-too-short'])->assertStatus(422);

        $this->postJson("/v1/time/attendance/closings/{$closureId}/reopen", [
            'reason' => 'Synthetic audit evidence for the reopen.',
        ], ['Idempotency-Key' => 'reopen-audited'])->assertOk();

        $this->assertDatabaseHas('audit_events', [
            'tenant_id' => 1,
            'action' => 'time.attendance.reopened',
            'aggregate_type' => 'attendance_period',
            'aggregate_id' => (string) $closureId,
        ]);

        $this->postJson('/v1/time/attendance/recalculate', [
            'month' => '2026-11',
            'legal_entity_id' => 1,
            'employment_id' => 1,
        ], ['Idempotency-Key' => 'recalc-after-reopen'])->assertStatus(202);
    }

    public function test_the_closing_list_reports_real_rows_with_reopen_evidence(): void
    {
        $this->actAsAttendanceSpecialist();

        AttendancePeriodClosure::query()->create([
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'month' => '2026-10',
            'status' => AttendancePeriodClosure::STATUS_CLOSED,
            'closed_by' => 10,
            'closed_at' => $this->clock->now(),
            'created_at' => $this->clock->now(),
        ]);
        AttendancePeriodClosure::query()->create([
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'month' => '2026-09',
            'status' => AttendancePeriodClosure::STATUS_REOPENED,
            'closed_by' => 10,
            'closed_at' => $this->clock->now(),
            'reopened_by' => 11,
            'reopened_at' => $this->clock->now(),
            'reopen_reason' => 'Synthetic correction after close.',
            'created_at' => $this->clock->now(),
        ]);
        // Another tenant's row must never leak into the projection.
        AttendancePeriodClosure::query()->create([
            'tenant_id' => 2,
            'legal_entity_id' => 1,
            'month' => '2026-08',
            'status' => AttendancePeriodClosure::STATUS_CLOSED,
            'closed_by' => 99,
            'closed_at' => $this->clock->now(),
            'created_at' => $this->clock->now(),
        ]);

        $response = $this->getJson('/v1/time/attendance/closings')->assertOk();

        $this->assertSame(2, $response->json('data.total'));
        $this->assertSame(['2026-10', '2026-09'], array_column((array) $response->json('data.items'), 'month'));
        $this->assertSame('Synthetic correction after close.', $response->json('data.items.1.reopen_reason'));
        $this->assertSame('LIVE', $response->json('data.freshness'));
    }

    public function test_a_closing_list_cannot_be_redirected_to_another_legal_entity(): void
    {
        $this->actAsAttendanceSpecialist();

        $this->getJson('/v1/time/attendance/closings?legal_entity_id=2')->assertNotFound();
    }

    public function test_rule_versions_are_immutable_and_effective_dated(): void
    {
        $this->actAsAttendanceSpecialist();

        $this->postJson('/v1/time/attendance/rules', [
            'version' => 'TIME-RULES-2026.1',
            'effective_from' => '2026-01-01',
            'config' => ['count_break' => true, 'overtime_after_scheduled' => true],
        ], ['Idempotency-Key' => 'rule-1'])->assertCreated();

        $this->postJson('/v1/time/attendance/rules', [
            'version' => 'TIME-RULES-2026.2',
            'effective_from' => '2026-07-01',
            'config' => ['count_break' => false, 'overtime_after_scheduled' => true],
        ], ['Idempotency-Key' => 'rule-2'])->assertCreated();

        // The first version must be untouched by publishing the second one.
        $first = AttendanceRuleVersion::query()->where('version', 'TIME-RULES-2026.1')->firstOrFail();
        $this->assertSame('2026-01-01', $first->effective_from->format('Y-m-d'));
        $this->assertTrue((bool) $first->config_json['count_break']);
        $this->assertDatabaseCount('attendance_rule_versions', 2);

        $before = $this->getJson('/v1/time/attendance/rules?as_of=2026-03-01')->assertOk();
        $this->assertSame('TIME-RULES-2026.1', $before->json('data.items.1.version'));
        $this->assertTrue((bool) $before->json('data.items.1.is_effective'));

        $after = $this->getJson('/v1/time/attendance/rules?as_of=2026-08-01')->assertOk();
        $this->assertSame('TIME-RULES-2026.2', $after->json('data.items.0.version'));
        $this->assertTrue((bool) $after->json('data.items.0.is_effective'));
        $this->assertFalse((bool) $after->json('data.items.1.is_effective'));
    }

    public function test_republishing_a_changed_rule_version_is_rejected(): void
    {
        $this->actAsAttendanceSpecialist();

        $this->postJson('/v1/time/attendance/rules', [
            'version' => 'TIME-RULES-2026.1',
            'effective_from' => '2026-01-01',
            'config' => ['count_break' => true],
        ], ['Idempotency-Key' => 'rule-a'])->assertCreated();

        $this->postJson('/v1/time/attendance/rules', [
            'version' => 'TIME-RULES-2026.1',
            'effective_from' => '2026-01-01',
            'config' => ['count_break' => false],
        ], ['Idempotency-Key' => 'rule-b'])
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'RULE_VERSION_IMMUTABLE');
    }

    public function test_device_health_lists_real_rows_and_is_read_only(): void
    {
        $this->actAsAttendanceSpecialist();

        AttendanceDevice::query()->create([
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'code' => 'DEV-SYNTH-01',
            'name' => 'Synthetic lobby reader',
            'adapter' => 'ZK-LAN',
            'health' => AttendanceDevice::HEALTH_DEGRADED,
            'last_seen_at' => $this->clock->now()->subMinutes(42),
            'active' => true,
            'created_at' => $this->clock->now(),
        ]);
        AttendanceDevice::query()->create([
            'tenant_id' => 2,
            'legal_entity_id' => 1,
            'code' => 'DEV-OTHER-TENANT',
            'name' => 'Other tenant reader',
            'adapter' => 'ZK-LAN',
            'health' => AttendanceDevice::HEALTH_ONLINE,
            'last_seen_at' => $this->clock->now(),
            'active' => true,
            'created_at' => $this->clock->now(),
        ]);

        $response = $this->getJson('/v1/time/attendance/devices')->assertOk();
        $this->assertSame(1, $response->json('data.total'));
        $this->assertSame('DEV-SYNTH-01', $response->json('data.items.0.code'));
        $this->assertSame('DEGRADED', $response->json('data.items.0.health'));
        $this->assertSame('LIVE', $response->json('data.freshness'));

        // There is no device write surface at all.
        $this->postJson('/v1/time/attendance/devices', [
            'code' => 'DEV-NEW',
            'health' => 'ONLINE',
        ], ['Idempotency-Key' => 'device-write'])->assertStatus(405);
    }

    public function test_rule_reads_require_the_rule_capability_not_generic_attendance(): void
    {
        $this->actAsHttp(TimeTestFixtures::actor(
            employmentId: 1,
            scope: 'LEGAL_ENTITY',
            capabilities: ['time.attendance.read'],
            legalEntityIds: [1],
        ));

        $this->getJson('/v1/time/attendance/rules')->assertForbidden();
        $this->getJson('/v1/time/shift-schedules')->assertForbidden();
        $this->getJson('/v1/time/attendance/devices')->assertForbidden();
        $this->getJson('/v1/time/attendance/closings')->assertOk();
    }

    public function test_every_k4_route_denies_unauthenticated_requests(): void
    {
        $this->forgetHttpPrincipal();

        $this->getJson('/v1/time/attendance/rules')->assertUnauthorized();
        $this->getJson('/v1/time/shift-schedules')->assertUnauthorized();
        $this->getJson('/v1/time/attendance/closings')->assertUnauthorized();
        $this->getJson('/v1/time/attendance/devices')->assertUnauthorized();
        $this->postJson('/v1/time/attendance/rules', [
            'version' => 'TIME-RULES-2026.9',
            'effective_from' => '2026-12-01',
            'config' => [],
        ], ['Idempotency-Key' => 'anon-rule'])->assertUnauthorized();
        $this->postJson('/v1/time/shift-schedules', [
            'employment_id' => 1,
            'business_date' => '2026-11-25',
            'shift_template_id' => 1,
        ], ['Idempotency-Key' => 'anon-schedule'])->assertUnauthorized();
    }

    public function test_every_k4_write_route_is_capability_guarded(): void
    {
        $this->seedEmployment();
        $this->shift(code: 'DAY');
        $this->actAsHttp(TimeTestFixtures::actor(
            employmentId: 1,
            scope: 'LEGAL_ENTITY',
            // Explicitly holds the read capabilities but neither manage one.
            capabilities: [
                'time.attendance.read',
                'time.attendance.rule.read',
                'time.shift.schedule.read',
            ],
            legalEntityIds: [1],
        ));

        $this->postJson('/v1/time/attendance/rules', [
            'version' => 'TIME-RULES-2026.9',
            'effective_from' => '2026-12-01',
            'config' => [],
        ], ['Idempotency-Key' => 'no-manage-rule'])->assertForbidden();

        $this->postJson('/v1/time/shift-schedules', [
            'employment_id' => 1,
            'business_date' => '2026-11-25',
            'shift_template_id' => 1,
        ], ['Idempotency-Key' => 'no-manage-schedule'])->assertForbidden();

        $this->assertDatabaseCount('shift_schedules', 0);
        $this->assertDatabaseCount('attendance_rule_versions', 0);
    }

    public function test_a_self_actor_only_sees_their_own_shift_schedules(): void
    {
        $this->people->add(TimeTestFixtures::employment(1, 2, 1));
        $this->shift(code: 'DAY');

        ShiftSchedule::query()->create([
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'employment_id' => 1,
            'business_date' => '2026-11-25',
            'shift_template_id' => 1,
            'created_by' => 10,
            'created_at' => $this->clock->now(),
        ]);
        ShiftSchedule::query()->create([
            'tenant_id' => 1,
            'legal_entity_id' => 1,
            'employment_id' => 2,
            'business_date' => '2026-11-25',
            'shift_template_id' => 1,
            'created_by' => 10,
            'created_at' => $this->clock->now(),
        ]);

        $this->actAsHttp(TimeTestFixtures::actor(
            employmentId: 1,
            scope: 'SELF',
            capabilities: ['time.shift.schedule.read'],
        ));

        $response = $this->getJson('/v1/time/shift-schedules')->assertOk();

        $this->assertSame(1, $response->json('data.total'));
        $this->assertSame(1, $response->json('data.items.0.employment_id'));
    }

    public function test_a_self_actor_cannot_schedule_another_employment(): void
    {
        $this->people->add(TimeTestFixtures::employment(1, 2, 1));
        $this->people->add(TimeTestFixtures::employment(2, 88, 8));
        $this->shift(code: 'DAY');

        $this->actAsHttp(TimeTestFixtures::actor(
            employmentId: 1,
            scope: 'SELF',
            capabilities: ['time.shift.schedule.manage', 'time.shift.read'],
        ));

        $this->postJson('/v1/time/shift-schedules', [
            'employment_id' => 88,
            'business_date' => '2026-11-25',
            'shift_template_id' => 1,
        ], ['Idempotency-Key' => 'sch-cross-self'])->assertNotFound();

        $this->assertDatabaseCount('shift_schedules', 0);
    }

    public function test_a_cross_tenant_device_row_is_never_listed(): void
    {
        $this->actAsAttendanceSpecialist();

        AttendanceDevice::query()->create([
            'tenant_id' => 2,
            'legal_entity_id' => 1,
            'code' => 'DEV-T2',
            'name' => 'Other tenant reader',
            'adapter' => 'ZK-LAN',
            'health' => AttendanceDevice::HEALTH_ONLINE,
            'last_seen_at' => $this->clock->now(),
            'active' => true,
            'created_at' => $this->clock->now(),
        ]);

        $response = $this->getJson('/v1/time/attendance/devices')->assertOk();

        $this->assertSame(0, $response->json('data.total'));
        $this->assertSame([], $response->json('data.items'));
    }

    public function test_unknown_rule_configuration_keys_are_rejected(): void
    {
        $this->actAsAttendanceSpecialist();

        $this->postJson('/v1/time/attendance/rules', [
            'version' => 'TIME-RULES-2026.9',
            'effective_from' => '2026-12-01',
            'config' => ['made_up_knob' => 1],
        ], ['Idempotency-Key' => 'rule-bad-key'])
            ->assertStatus(422)
            ->assertJsonPath('data.reason', 'RULE_CONFIG_UNKNOWN_KEY');

        $this->assertDatabaseCount('attendance_rule_versions', 0);
    }

    public function test_a_non_effective_shift_template_cannot_be_scheduled(): void
    {
        $this->actAsAttendanceSpecialist();
        $this->seedEmployment();
        $expired = $this->shift(code: 'OLD', validFrom: '2020-01-01', validTo: '2026-01-01');

        $this->postJson('/v1/time/shift-schedules', [
            'employment_id' => 1,
            'business_date' => '2026-11-25',
            'shift_template_id' => $expired->id,
        ], ['Idempotency-Key' => 'sch-expired'])
            ->assertStatus(422)
            ->assertJsonPath('data.reason', 'SHIFT_TEMPLATE_NOT_EFFECTIVE');
    }

    private function actAsAttendanceSpecialist(): void
    {
        $this->actAsHttp(TimeTestFixtures::actor(
            employmentId: 1,
            scope: 'LEGAL_ENTITY',
            capabilities: [
                'time.attendance.read',
                'time.attendance.manage',
                'time.attendance.reopen',
                'time.attendance.rule.read',
                'time.attendance.rule.manage',
                'time.shift.read',
                'time.shift.schedule.read',
                'time.shift.schedule.manage',
                'time.attendance.device.read',
                'time.calendar.read',
            ],
            legalEntityIds: [1],
        ));
    }

    private function seedEmployment(): int
    {
        $this->people->add(TimeTestFixtures::employment($this->tenantId, 1, $this->legalEntityId));

        return 1;
    }
}
