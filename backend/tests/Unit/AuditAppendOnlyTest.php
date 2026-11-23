<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Platform\Models\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditAppendOnlyTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_audit_event_persists_json_payloads(): void
    {
        $event = AuditEvent::query()->create([
            'tenant_id' => 1,
            'actor_user_id' => 7,
            'actor_type' => 'USER',
            'action' => 'user.updated',
            'aggregate_type' => 'user',
            'aggregate_id' => '42',
            'before_json' => ['name' => 'Old'],
            'after_json' => ['name' => 'New'],
            'metadata_json' => ['reason' => 'test'],
            'ip' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'request_id' => 'req-12345678',
            'occurred_at' => now()->utc(),
        ]);

        $this->assertDatabaseHas('audit_events', [
            'id' => $event->id,
            'action' => 'user.updated',
            'request_id' => 'req-12345678',
        ]);
        $this->assertSame(['name' => 'New'], $event->fresh()?->after_json);
    }

    public function test_updating_audit_event_throws(): void
    {
        $event = AuditEvent::query()->create($this->attributes());

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Audit events are append-only.');

        $event->update(['action' => 'tampered']);
    }

    public function test_deleting_audit_event_throws(): void
    {
        $event = AuditEvent::query()->create($this->attributes());

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Audit events are append-only.');

        $event->delete();
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(): array
    {
        return [
            'tenant_id' => 1,
            'actor_type' => 'SYSTEM',
            'action' => 'test.action',
            'aggregate_type' => 'test',
            'aggregate_id' => '1',
            'metadata_json' => [],
            'occurred_at' => now()->utc(),
        ];
    }
}
