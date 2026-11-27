<?php

declare(strict_types=1);

namespace Tests\Feature\People;

use Tests\Support\PeopleTestCase;

/**
 * Employee export reuses list scope and as-of predicates, exposes only the
 * fixed non-sensitive whitelist, and never accepts client field expansion.
 */
final class EmployeeExportTest extends PeopleTestCase
{
    public function test_export_returns_csv_with_the_whitelisted_columns(): void
    {
        $scenario = $this->scenario();
        $unit = $scenario->orgUnit('EXPORT-ORG', name: '合成研发部');
        $position = $scenario->position($unit, 'EXPORT-POS', ['title' => '合成工程师']);
        $person = $scenario->person(['legal_name' => '合成导出员工']);
        $employment = $scenario->employment($person, 'EXP-0001', ['hire_date' => '2026-01-02']);
        $scenario->assignment($employment, $unit, ['position_id' => $position->id]);

        $response = $this->get('/v1/employees/export?as_of=2026-11-23')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $content = (string) $response->getContent();
        $this->assertStringContainsString('employee_no,person_name,status,hire_date,org_unit_name,position_title', $content);
        $this->assertStringContainsString('EXP-0001', $content);
        $this->assertStringContainsString('合成导出员工', $content);
        $this->assertStringContainsString('合成研发部', $content);
        $this->assertStringContainsString('合成工程师', $content);
        $this->assertStringNotContainsString('national_id', $content);
        $this->assertStringNotContainsString('id_number', $content);
        $this->assertStringNotContainsString('mobile', $content);

        $this->assertDatabaseHas('audit_events', ['action' => 'people.employee.exported']);
    }

    public function test_export_applies_the_org_filter_and_never_returns_other_units(): void
    {
        $scenario = $this->scenario();
        $includedUnit = $scenario->orgUnit('EXPORT-IN', name: '合成已选部门');
        $excludedUnit = $scenario->orgUnit('EXPORT-OUT', name: '合成未选部门');
        $included = $scenario->employment($scenario->person(['legal_name' => '合成已选员工']), 'EXP-IN-1');
        $excluded = $scenario->employment($scenario->person(['legal_name' => '合成未选员工']), 'EXP-OUT-1');
        $scenario->assignment($included, $includedUnit);
        $scenario->assignment($excluded, $excludedUnit);

        $content = (string) $this->get("/v1/employees/export?as_of=2026-11-23&org_unit_id={$includedUnit->id}")
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('EXP-IN-1', $content);
        $this->assertStringNotContainsString('EXP-OUT-1', $content);
    }

    public function test_export_rejects_client_controlled_fields(): void
    {
        $this->get('/v1/employees/export?as_of=2026-11-23&fields=national_id')
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_FAILED');
    }

    public function test_export_requires_the_export_capability(): void
    {
        $this->actingAsUserWithCapabilities(['people.employee.read']);

        $this->get('/v1/employees/export?as_of=2026-11-23')->assertForbidden();
    }
}
