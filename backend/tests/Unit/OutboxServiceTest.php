<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Platform\Models\OutboxEvent;
use App\Modules\Platform\Services\OutboxService;
use App\Shared\Exceptions\ConflictException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OutboxServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_record_persists_event_with_json_payload_and_ulid(): void
    {
        $event = app(OutboxService::class)->record(
            eventType: 'employment.assignment.changed.v1',
            aggregateType: 'employment',
            aggregateId: 'emp_123',
            aggregateVersion: 7,
            payload: ['employment_id' => 'emp_123', 'assignment_id' => 'asg_456'],
        );

        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $event->id);
        $this->assertDatabaseHas('outbox_events', [
            'id' => $event->id,
            'event_type' => 'employment.assignment.changed.v1',
            'aggregate_version' => 7,
            'attempts' => 0,
        ]);
        $this->assertSame(['employment_id' => 'emp_123', 'assignment_id' => 'asg_456'], $event->fresh()?->payload_json);
        $this->assertNotNull($event->available_at);
        $this->assertNull($event->published_at);
    }

    public function test_record_rolls_back_with_caller_transaction(): void
    {
        DB::beginTransaction();
        app(OutboxService::class)->record(
            eventType: 'test.rolled.back.v1',
            aggregateType: 'test',
            aggregateId: '1',
            aggregateVersion: 1,
            payload: ['id' => '1'],
        );
        $this->assertSame(1, OutboxEvent::query()->count());
        DB::rollBack();

        $this->assertSame(0, OutboxEvent::query()->count());
    }

    public function test_payload_with_prohibited_sensitive_keys_is_rejected(): void
    {
        foreach (['id_number', 'password', 'bank_account'] as $key) {
            try {
                app(OutboxService::class)->record(
                    eventType: 'test.sensitive.v1',
                    aggregateType: 'test',
                    aggregateId: '1',
                    aggregateVersion: 1,
                    payload: ['nested' => [$key => 'secret-value']],
                );
                $this->fail("Payload key {$key} should have been rejected.");
            } catch (ConflictException $exception) {
                $this->assertSame('OUTBOX_PAYLOAD_SENSITIVE', $exception->details()['reason'] ?? null);
            }
        }

        $this->assertSame(0, OutboxEvent::query()->count());
    }

    public function test_dispatcher_contract_is_bound(): void
    {
        $this->assertInstanceOf(\App\Modules\Platform\Contracts\OutboxDispatcher::class, app(\App\Modules\Platform\Contracts\OutboxDispatcher::class));
    }
}
