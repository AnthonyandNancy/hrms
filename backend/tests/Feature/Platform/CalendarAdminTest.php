<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Modules\Platform\Models\AuditEvent;
use App\Modules\Time\Models\Holiday;
use Tests\Support\PeopleTestCase;

final class CalendarAdminTest extends PeopleTestCase
{
    public function test_publishing_a_calendar_increments_its_version_and_records_holidays_and_audit(): void
    {
        $entity = $this->scenario()->legalEntity();
        $this->actAs(1, 10, 1);

        $this->postJson('/v1/system/calendars/'.$entity->id.'/publish', [
            'timezone' => 'Asia/Shanghai',
            'workweek' => [1, 2, 3, 4, 5],
            'standard_day_minutes' => 480,
            'holidays' => [
                ['date' => '2026-10-01', 'name' => 'Synthetic National Day', 'type' => 'PUBLIC'],
            ],
        ], ['Idempotency-Key' => 'calendar-publish-1', 'X-Legal-Entity-Id' => (string) $entity->id])
            ->assertOk()
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.legal_entity_id', (int) $entity->id);

        $this->postJson('/v1/system/calendars/'.$entity->id.'/publish', [
            'timezone' => 'Asia/Shanghai',
            'workweek' => [1, 2, 3, 4, 5, 6],
            'standard_day_minutes' => 480,
            'holidays' => [
                ['date' => '2026-10-01', 'name' => 'Synthetic National Day', 'type' => 'PUBLIC'],
                ['date' => '2026-10-02', 'name' => 'Synthetic National Day (day 2)', 'type' => 'PUBLIC'],
            ],
        ], ['Idempotency-Key' => 'calendar-publish-2', 'X-Legal-Entity-Id' => (string) $entity->id])
            ->assertOk()
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.workweek.5', 6)
            ->assertJsonCount(2, 'data.holidays');

        $this->assertDatabaseHas('legal_entity_calendars', [
            'tenant_id' => 1,
            'legal_entity_id' => (int) $entity->id,
            'version' => 2,
        ]);
        $this->assertDatabaseHas('holidays', [
            'tenant_id' => 1,
            'legal_entity_id' => (int) $entity->id,
            'date' => '2026-10-01',
            'name' => 'Synthetic National Day',
        ]);
        $this->assertSame(2, Holiday::query()->count());
        $this->assertTrue(AuditEvent::query()->where('action', 'time.calendar.published')->exists());
    }

    public function test_calendar_publish_validates_timezone_and_requires_idempotency(): void
    {
        $entity = $this->scenario()->legalEntity();
        $this->actAs(1, 10, 1);

        $this->postJson('/v1/system/calendars/'.$entity->id.'/publish', [
            'timezone' => 'Not/AZone',
            'workweek' => [1, 2, 3, 4, 5],
            'standard_day_minutes' => 480,
        ], ['Idempotency-Key' => 'calendar-bad-timezone', 'X-Legal-Entity-Id' => (string) $entity->id])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'VALIDATION_FAILED');

        $this->postJson('/v1/system/calendars/'.$entity->id.'/publish', [
            'timezone' => 'Asia/Shanghai',
            'workweek' => [1, 2, 3, 4, 5],
            'standard_day_minutes' => 480,
        ], ['X-Legal-Entity-Id' => (string) $entity->id])
            ->assertUnprocessable()
            ->assertJsonPath('data.reason', 'IDEMPOTENCY_KEY_REQUIRED');
    }
}
