<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Modules\IAM\Models\AuthEvent;
use App\Modules\Platform\Models\AuditEvent;
use App\Modules\Time\Contracts\TimeActor;
use Tests\Support\Time\TimeHttpTestCase;
use Tests\Support\Time\TimeTestFixtures;

final class AuditQueryTest extends TimeHttpTestCase
{
    public function test_audit_query_filters_server_side_and_export_is_whitelisted(): void
    {
        AuthEvent::query()->create([
            'user_id' => 15,
            'event_type' => 'login',
            'result' => 'SUCCESS',
            'request_id' => 'req-audit-1',
            'metadata_json' => ['ip' => '127.0.0.1'],
            'occurred_at' => '2026-11-15 08:00:00',
        ]);
        AuthEvent::query()->create([
            'user_id' => 16,
            'event_type' => 'login',
            'result' => 'FAILURE',
            'request_id' => 'req-audit-2',
            'metadata_json' => ['ip' => '127.0.0.2'],
            'occurred_at' => '2026-11-16 08:00:00',
        ]);

        $this->actAsHttp($this->auditAdmin());

        $this->getJson('/v1/audit/events?actor=15&result=SUCCESS&from=2026-11-01&to=2026-11-30')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.request_id', 'req-audit-1');

        $this->postJson('/v1/audit/events/export', [
            'fields' => ['event_type', 'result', 'occurred_at'],
        ], ['Idempotency-Key' => 'audit-export-1'])
            ->assertOk()
            ->assertJsonPath('data.fields', ['event_type', 'result', 'occurred_at'])
            ->assertJsonStructure(['data' => ['fields', 'rows']]);

        $this->postJson('/v1/audit/events/export', [
            'fields' => ['password'],
        ], ['Idempotency-Key' => 'audit-export-2'])
            ->assertUnprocessable()
            ->assertJsonPath('data.reason', 'EXPORT_FIELD_NOT_ALLOWED');

        $this->assertDatabaseHas('audit_events', ['action' => 'iam.audit.exported']);
    }

    public function test_audit_routes_reject_callers_without_audit_capability(): void
    {
        $this->actAsHttp(TimeTestFixtures::actor(
            userId: 7001,
            scope: 'COMPANY',
            capabilities: ['time.calendar.read'],
        ));

        $this->getJson('/v1/audit/events')->assertForbidden();
        $this->postJson('/v1/audit/events/export', ['fields' => ['result']], ['Idempotency-Key' => 'audit-denied'])
            ->assertForbidden();

        $this->assertSame(0, AuditEvent::query()->where('action', 'iam.audit.exported')->count());
    }

    private function auditAdmin(): TimeActor
    {
        return TimeTestFixtures::actor(
            userId: 9001,
            employmentId: 1,
            scope: 'COMPANY',
            capabilities: [
                'iam.audit.read',
                'system.settings.manage',
                'time.calendar.read',
                'time.calendar.manage',
            ],
        );
    }
}
