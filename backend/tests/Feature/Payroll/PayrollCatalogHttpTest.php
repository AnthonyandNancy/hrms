<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Modules\Payroll\Services\PayrollItemService;
use Tests\Support\PayrollTestCase;

/**
 * Item-version HTTP contract. Route registration is owned by the lead because
 * backend/routes/api.php is outside the M13 write domain; these tests document
 * the expected envelope, server-owned versioning, replay and validation.
 *
 * NOT RUN in this workspace (tests are deferred by the task instructions).
 */
final class PayrollCatalogHttpTest extends PayrollTestCase
{
    public function test_create_version_and_retire_return_single_envelopes_and_server_version(): void
    {
        $this->actAs(10);

        $created = $this->postJson('/v1/payroll/items', [
            'code' => 'DEMO_BONUS',
            'name' => 'Synthetic demo bonus',
            'type' => 'EARNING',
            'calculation' => 'FIXED',
        ], $this->headers('item-create-1'))->assertCreated();

        $this->assertSame(1, (int) $created->json('data.version'));
        $this->assertSame('DEMO_BONUS', $created->json('data.code'));
        $this->assertArrayNotHasKey('amount_cents', (array) $created->json('data'));

        $itemId = (int) $created->json('data.id');

        $versioned = $this->postJson("/v1/payroll/items/{$itemId}/versions", [
            'name' => 'Synthetic renamed bonus',
            'effective_from' => '2026-12-01',
            'version' => 99,
        ], $this->headers('item-version-1'))->assertCreated();

        $this->assertSame(2, (int) $versioned->json('data.version'));
        $this->assertSame('Synthetic renamed bonus', $versioned->json('data.name'));

        $retired = $this->postJson("/v1/payroll/items/{$itemId}/retire", [
            'reason' => 'Synthetic merged into allowance',
        ], $this->headers('item-retire-1'))->assertOk();

        $this->assertSame('RETIRED', $retired->json('data.status'));
        $this->assertDatabaseCount('payroll_item_versions', 2);
    }

    public function test_idempotent_replay_returns_200_without_creating_a_second_version(): void
    {
        $this->actAs(10);

        $payload = [
            'code' => 'DEMO_ALLOWANCE',
            'name' => 'Synthetic demo allowance',
            'type' => 'EARNING',
        ];
        $first = $this->postJson('/v1/payroll/items', $payload, $this->headers('item-idem-1'))
            ->assertCreated();
        $itemId = (int) $first->json('data.id');

        $versionPayload = ['name' => 'Synthetic allowance v2', 'effective_from' => '2026-12-01'];
        $this->postJson("/v1/payroll/items/{$itemId}/versions", $versionPayload, $this->headers('item-version-idem'))
            ->assertCreated();

        $replay = $this->postJson(
            "/v1/payroll/items/{$itemId}/versions",
            $versionPayload,
            $this->headers('item-version-idem'),
        )->assertOk();

        $this->assertSame('true', $replay->headers->get('Idempotency-Replayed'));
        $this->assertDatabaseCount('payroll_item_versions', 2);
    }

    public function test_missing_idempotency_key_is_a_validation_error(): void
    {
        $this->actAs(10);

        $this->postJson('/v1/payroll/items', [
            'code' => 'DEMO_NO_KEY',
            'name' => 'Synthetic no-key item',
            'type' => 'EARNING',
        ])->assertStatus(422)->assertJsonPath('data.reason', 'IDEMPOTENCY_KEY_REQUIRED');
    }

    public function test_money_fields_are_rejected_on_item_writes(): void
    {
        $this->actAs(10);

        $this->postJson('/v1/payroll/items', [
            'code' => 'DEMO_MONEY',
            'name' => 'Synthetic money item',
            'type' => 'EARNING',
            'amount_cents' => 1000,
        ], $this->headers('item-money-1'))->assertStatus(422);
    }

    public function test_overlapping_effective_date_is_409_and_retired_item_cannot_be_versioned(): void
    {
        $this->actAs(10);
        $service = app(PayrollItemService::class);
        $first = $service->create(10, [
            'code' => 'DEMO_OVERLAP',
            'name' => 'Synthetic overlap item',
            'type' => 'EARNING',
            'effective_from' => '2026-01-01',
        ]);

        $this->postJson("/v1/payroll/items/{$first->payroll_item_id}/versions", [
            'name' => 'Synthetic overlap v2',
            'effective_from' => '2026-01-01',
        ], $this->headers('overlap-1'))
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'PAYROLL_ITEM_VERSION_OVERLAP');

        $this->postJson("/v1/payroll/items/{$first->payroll_item_id}/retire", [
            'reason' => 'Synthetic retirement',
        ], $this->headers('retire-overlap-1'))->assertOk();

        $this->postJson("/v1/payroll/items/{$first->payroll_item_id}/versions", [
            'name' => 'Synthetic after retire',
            'effective_from' => '2026-12-01',
        ], $this->headers('retired-version-1'))
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'PAYROLL_ITEM_RETIRED');
    }

    /**
     * @return array<string, string>
     */
    private function headers(string $idempotencyKey): array
    {
        return [
            'Idempotency-Key' => $idempotencyKey,
            'X-CSRF-Token' => 'test-csrf-token',
        ];
    }
}
