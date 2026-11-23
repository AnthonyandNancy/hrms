<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Modules\People\Contracts\EmploymentLifecycleDirectory;
use App\Modules\People\Models\Employment;
use Tests\Support\LifecycleTestCase;

final class UatOnboardingToFirstPayrollPreparationTest extends LifecycleTestCase
{
    public function test_onboarding_produces_payroll_preparation_inputs_from_contracts(): void
    {
        $scenario = $this->peopleScenario;
        $entity = $scenario->legalEntity();
        $org = $scenario->orgUnit($entity, 'PAY-PREP-ORG');

        $created = $this->createLifecycleCase('onboarding', [
            'person' => ['legal_name' => 'Payroll Prep Person'],
            'legal_entity_id' => $entity->id,
            'employee_no' => 'PAY-PREP-1',
            'org_unit_id' => $org->id,
            'hire_date' => '2026-12-01',
            'employment_type' => 'FULL_TIME',
            'terms' => [
                'work_location' => 'Shanghai',
                'work_schedule' => 'STANDARD',
                'salary_scheme_code' => 'PAY-SCHEME-1',
                'base_salary_minor' => 2_345_600,
                'salary_currency' => 'CNY',
                'notice_period_days' => 30,
            ],
            'submit' => true,
        ], 'payprep-create')->assertCreated();
        $this->approveCase((int) $created->json('data.case_id'), 'payprep-approve')->assertOk();

        $employment = Employment::query()->firstOrFail();
        $directory = app(EmploymentLifecycleDirectory::class);
        $terms = $directory->termsAsOf(1, $employment->id, '2026-12-01');
        $assignment = $directory->assignmentAsOf(1, $employment->id, '2026-12-01');

        $this->assertNotNull($terms);
        $this->assertNotNull($assignment);
        $this->assertSame(2_345_600, $terms->baseSalaryMinor);
        $this->assertSame('CNY', $terms->salaryCurrency);
        $this->assertSame('PAY-SCHEME-1', $terms->salarySchemeCode);
        $this->assertSame($entity->id, $employment->legal_entity_id);
        $this->assertSame($org->id, $assignment->orgUnitId);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'employment.created.v1']);
    }
}
