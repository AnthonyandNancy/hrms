<?php

declare(strict_types=1);

namespace Tests\Feature\People;

use App\Modules\People\Models\EmployeeEducation;
use Tests\Support\PeopleTestCase;

/**
 * Retirement is append-only: the row stays in the database with superseded_at
 * set, disappears from the live list, and records actor/reason audit evidence.
 */
final class EmployeeProfileRetireTest extends PeopleTestCase
{
    public function test_retire_supersedes_the_row_without_deleting_history(): void
    {
        $scenario = $this->scenario();
        $employment = $scenario->employment($scenario->person(), 'RET-0001');
        $scenario->assignment($employment, $scenario->orgUnit('RET-ORG'));

        $id = (int) $this->postJson("/v1/employees/{$employment->id}/education", [
            'school' => '合成大学',
            'degree' => 'BACHELOR',
            'start_date' => '2010-09-01',
        ], ['Idempotency-Key' => 'retire-education-create'])->assertCreated()->json('data.id');

        $this->deleteJson("/v1/employees/{$employment->id}/education/{$id}", [
            'reason' => '合成重复记录',
            'effective_from' => '2026-11-27',
        ], ['Idempotency-Key' => 'retire-education-1'])
            ->assertOk()
            ->assertJsonPath('data.retired', true)
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.employment_id', (int) $employment->id);

        $row = EmployeeEducation::query()->whereKey($id)->firstOrFail();
        $this->assertNotNull($row->getAttribute('superseded_at'), 'retire must set superseded_at');
        $this->assertDatabaseHas('audit_events', ['action' => 'people.profile.retired']);

        $this->getJson("/v1/employees/{$employment->id}/education")
            ->assertOk()
            ->assertJsonPath('data.total', 0);
    }

    public function test_retire_replays_with_the_same_key_and_rejects_a_second_logical_retire(): void
    {
        $scenario = $this->scenario();
        $employment = $scenario->employment($scenario->person(), 'RET-0002');
        $scenario->assignment($employment, $scenario->orgUnit('RET-ORG-2'));

        $id = (int) $this->postJson("/v1/employees/{$employment->id}/education", [
            'school' => '合成大学',
            'degree' => 'BACHELOR',
            'start_date' => '2010-09-01',
        ], ['Idempotency-Key' => 'retire-education-create-2'])->assertCreated()->json('data.id');

        $body = ['reason' => '合成重复记录', 'effective_from' => '2026-11-27'];
        $headers = ['Idempotency-Key' => 'retire-education-2'];

        $first = $this->deleteJson("/v1/employees/{$employment->id}/education/{$id}", $body, $headers)->assertOk();
        $second = $this->deleteJson("/v1/employees/{$employment->id}/education/{$id}", $body, $headers)->assertOk();
        $this->assertSame($first->json('data.superseded_at'), $second->json('data.superseded_at'));
        $this->assertSame('true', $second->headers->get('idempotency-replayed'));

        $this->deleteJson("/v1/employees/{$employment->id}/education/{$id}", $body, ['Idempotency-Key' => 'retire-education-2b'])
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'PROFILE_ALREADY_RETIRED');
    }

    public function test_retire_rejects_a_row_belonging_to_another_employment_as_404(): void
    {
        $scenario = $this->scenario();
        $org = $scenario->orgUnit('RET-SCOPE-ORG');
        $first = $scenario->employment($scenario->person(), 'RET-0003');
        $second = $scenario->employment($scenario->person(), 'RET-0004');
        $scenario->assignment($first, $org);
        $scenario->assignment($second, $org);

        $id = (int) $this->postJson("/v1/employees/{$first->id}/education", [
            'school' => '合成大学',
            'degree' => 'BACHELOR',
            'start_date' => '2010-09-01',
        ], ['Idempotency-Key' => 'retire-scope-create'])->assertCreated()->json('data.id');

        $this->deleteJson("/v1/employees/{$second->id}/education/{$id}", [
            'reason' => '越权退役',
            'effective_from' => '2026-11-27',
        ], ['Idempotency-Key' => 'retire-scope-1'])->assertNotFound();

        $this->assertNull(EmployeeEducation::query()->whereKey($id)->value('superseded_at'));
    }

    public function test_retire_requires_manage_capability_and_an_idempotency_key(): void
    {
        $scenario = $this->scenario();
        $employment = $scenario->employment($scenario->person(), 'RET-0005');
        $scenario->assignment($employment, $scenario->orgUnit('RET-DENY-ORG'));

        $id = (int) $this->postJson("/v1/employees/{$employment->id}/education", [
            'school' => '合成大学',
            'degree' => 'BACHELOR',
            'start_date' => '2010-09-01',
        ], ['Idempotency-Key' => 'retire-deny-create'])->assertCreated()->json('data.id');

        $this->actingAsUserWithCapabilities(['people.employee.read']);
        $this->deleteJson("/v1/employees/{$employment->id}/education/{$id}", [
            'reason' => '无权限退役',
            'effective_from' => '2026-11-27',
        ], ['Idempotency-Key' => 'retire-deny-1'])->assertForbidden();
        $this->assertNull(EmployeeEducation::query()->whereKey($id)->value('superseded_at'));

        $this->actingAsUserWithCapabilities(['people.employee.read', 'people.employee.manage']);
        $this->deleteJson("/v1/employees/{$employment->id}/education/{$id}", [
            'reason' => '缺少幂等键',
            'effective_from' => '2026-11-27',
        ])->assertStatus(422)->assertJsonPath('data.reason', 'IDEMPOTENCY_KEY_REQUIRED');
    }
}
