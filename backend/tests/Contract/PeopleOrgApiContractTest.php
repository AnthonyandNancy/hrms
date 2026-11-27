<?php

declare(strict_types=1);

namespace Tests\Contract;

use App\Modules\Platform\Models\OutboxEvent;
use Tests\Support\PeopleTestCase;

final class PeopleOrgApiContractTest extends PeopleTestCase
{
    public function test_person_creation_envelope_matches_frozen_shape(): void
    {
        $response = $this->postJson('/v1/persons', ['legal_name' => 'Contract Person'])->assertCreated();

        $response->assertJsonStructure([
            'code', 'message', 'data' => ['id', 'legal_name', 'preferred_name', 'birth_date', 'gender', 'nationality', 'email', 'mobile'], 'request_id',
        ])->assertJsonPath('code', 'CREATED');
        $this->assertIsString($response->headers->get('X-Request-Id'));
    }

    public function test_employee_list_uses_paginated_data_shape(): void
    {
        $scenario = $this->scenario();
        $org = $scenario->orgUnit('CONTRACT-ORG');
        $scenario->assignment($scenario->employment($scenario->person(), 'CONTRACT-1'), $org);

        $this->getJson('/v1/employees?page=1&page_size=10')
            ->assertOk()
            ->assertJsonStructure([
                'code', 'message', 'request_id',
                'data' => ['items', 'page', 'page_size', 'total'],
            ])
            ->assertJsonPath('data.page', 1)
            ->assertJsonPath('data.page_size', 10);
    }

    public function test_org_tree_uses_list_envelope(): void
    {
        $scenario = $this->scenario();
        $scenario->orgUnit('CONTRACT-TREE');

        $this->getJson('/v1/org-units/tree')
            ->assertOk()
            ->assertJsonStructure(['data' => ['items', 'page', 'page_size', 'total']]);
    }

    public function test_commands_emit_outbox_events_without_pii(): void
    {
        $scenario = $this->scenario();
        $org = $scenario->orgUnit('CONTRACT-OUTBOX');
        $person = $scenario->person(['id_number' => 'OUTBOX-SECRET-ID']);
        $employment = $scenario->employment($person, 'CONTRACT-2');

        $this->postJson("/v1/employments/{$employment->id}/assignments", [
            'org_unit_id' => $org->id,
            'assignment_type' => 'PRIMARY',
            'valid_from' => '2026-01-01',
        ])->assertCreated();

        $event = OutboxEvent::query()->where('event_type', 'employment.assignment.changed.v1')->firstOrFail();
        $payload = (string) json_encode($event->payload_json);
        $this->assertStringNotContainsString('OUTBOX-SECRET-ID', $payload);
        $this->assertStringNotContainsString('mobile', $payload);

        $this->assertDatabaseHas('audit_events', ['action' => 'people.assignment.created']);
    }

    public function test_api_errors_keep_stable_codes(): void
    {
        $this->getJson('/v1/employees?as_of=bad')->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_FAILED');
        $this->getJson('/v1/employees/999999?as_of=2026-11-23')->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');
    }
}
