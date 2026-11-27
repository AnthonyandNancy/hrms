<?php

declare(strict_types=1);

namespace Tests\Feature\Time;

use App\Modules\Time\Models\AttendanceDay;
use App\Modules\Time\Models\PunchRecord;
use Tests\Support\Time\TimeHttpTestCase;
use Tests\Support\Time\TimeTestFixtures;

/**
 * M09 attendance records/punch reads are scoped before pagination, and CSV
 * export has a narrow field whitelist with audit evidence.
 *
 * These tests are authored now and marked NOT RUN by the M09 delivery report;
 * they must not be described as passing until the shared route/OpenAPI/catalog
 * integration is applied and the owner runs the gate.
 */
final class AttendanceRecordFilterTest extends TimeHttpTestCase
{
    public function test_records_are_scoped_before_pagination_and_export_has_no_punch_hash(): void
    {
        $this->actAsAttendanceSpecialist();
        $this->seedAttendanceForTwoEmployees();

        $this->getJson('/v1/time/attendance/records?from=2026-11-01&to=2026-11-30&page=1&page_size=1')
            ->assertOk()
            ->assertJsonPath('data.page_size', 1)
            ->assertJsonPath('data.total', 2)
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.employee_no', 'E0001');

        $export = $this->get('/v1/time/attendance/records/export?from=2026-11-01&to=2026-11-30');

        $export->assertOk();
        $this->assertStringContainsString('text/csv', (string) $export->headers->get('content-type'));
        $content = (string) $export->getContent();
        $this->assertStringContainsString('employee_no,employee_name,business_date,status', $content);
        $this->assertStringNotContainsString('raw_payload_hash', $content);
        $this->assertStringNotContainsString(hash('sha256', 'punch-one'), $content);
        $this->assertDatabaseHas('audit_events', ['action' => 'time.attendance.exported']);
    }

    public function test_self_actor_is_pinned_to_session_employment_even_when_query_supplies_another_id(): void
    {
        $this->seedAttendanceForTwoEmployees();
        $this->actAsHttp(TimeTestFixtures::actor(employmentId: 1, scope: 'SELF'));

        $this->getJson('/v1/time/attendance/records?employment_id=2&from=2026-11-01&to=2026-11-30')
            ->assertNotFound();

        $response = $this->getJson('/v1/time/attendance/records?from=2026-11-01&to=2026-11-30')
            ->assertOk();

        $this->assertSame(1, $response->json('data.total'));
        $this->assertSame(1, $response->json('data.items.0.employment_id'));
    }

    public function test_manager_scope_applies_before_count_and_pagination(): void
    {
        $this->seedAttendanceForTwoEmployees();
        $this->actAsHttp(TimeTestFixtures::actor(
            employmentId: 1,
            scope: 'MANAGED',
            managedEmploymentIds: [2],
            capabilities: ['time.attendance.read', 'time.punch.read', 'time.attendance.export'],
        ));

        $response = $this->getJson('/v1/time/attendance/records?from=2026-11-01&to=2026-11-30&page=1&page_size=1')
            ->assertOk();

        $this->assertSame(1, $response->json('data.total'));
        $this->assertSame(2, $response->json('data.items.0.employment_id'));
    }

    public function test_punch_reads_never_expose_hash_or_mutation_routes(): void
    {
        $this->actAsAttendanceSpecialist();
        $this->seedAttendanceForTwoEmployees();

        $response = $this->getJson('/v1/time/punches?from=2026-11-01&to=2026-11-30&employment_id=1&page=1&page_size=20')
            ->assertOk()
            ->assertJsonPath('data.page_size', 20)
            ->assertJsonPath('data.items.0.employment_id', 1);

        $item = (array) $response->json('data.items.0');
        $this->assertArrayNotHasKey('raw_payload_hash', $item);
        $this->assertArrayHasKey('punched_at_utc', $item);
        $this->assertArrayHasKey('source', $item);
        $this->assertArrayHasKey('device_id', $item);

        // Raw punches have no edit/delete HTTP surface.
        $this->deleteJson('/v1/time/punches/'.(int) $item['id'])->assertStatus(404);
        $this->patchJson('/v1/time/punches/'.(int) $item['id'], ['source' => 'MANUAL'])->assertStatus(404);
    }

    public function test_export_requires_audit_capability_but_read_does_not_emit_files_without_it(): void
    {
        $this->seedAttendanceForTwoEmployees();
        $this->actAsHttp(TimeTestFixtures::actor(
            employmentId: 1,
            scope: 'COMPANY',
            capabilities: ['time.attendance.read'],
        ));

        $this->get('/v1/time/attendance/records/export?from=2026-11-01&to=2026-11-30')
            ->assertForbidden();
        $this->assertDatabaseMissing('audit_events', ['action' => 'time.attendance.exported']);
    }

    private function actAsAttendanceSpecialist(): void
    {
        $this->calendar();
        $this->actAsHttp(TimeTestFixtures::actor(
            employmentId: 1,
            scope: 'COMPANY',
            capabilities: [
                'time.attendance.read',
                'time.punch.read',
                'time.attendance.export',
                'time.attendance.manage',
            ],
        ));
    }

    private function seedAttendanceForTwoEmployees(): void
    {
        $this->people->add(TimeTestFixtures::employment(1));
        $this->people->add(TimeTestFixtures::employment(2));
        $dayShift = $this->shift(code: 'DAY');
        $this->assignShift(1, $dayShift);

        $this->seedDay(1, '2026-11-24');
        $this->seedDay(2, '2026-11-24');
    }

    private function seedDay(int $employmentId, string $businessDate): void
    {
        AttendanceDay::query()->create([
            'employment_id' => $employmentId,
            'business_date' => $businessDate,
            'scheduled_minutes' => 480,
            'worked_minutes' => 480,
            'status' => 'PRESENT',
            'rule_version' => 'TEST',
            'source_hash' => hash('sha256', 'day-'.$employmentId),
            'calculated_at' => $this->clock->now(),
        ]);

        PunchRecord::query()->create([
            'employment_id' => $employmentId,
            'punched_at_utc' => '2026-11-24T01:00:00Z',
            'source' => 'IMPORT',
            'raw_payload_hash' => hash('sha256', $employmentId === 1 ? 'punch-one' : 'punch-two'),
            'created_at' => $this->clock->now(),
        ]);
    }
}
