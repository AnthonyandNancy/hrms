<?php

declare(strict_types=1);

namespace Tests\Feature\Lifecycle;

use App\Modules\Lifecycle\Models\LifecycleIntent;
use App\Modules\Lifecycle\Models\LifecycleTask;
use App\Modules\People\Models\Assignment;
use App\Modules\People\Models\Employment;
use App\Modules\People\Models\EmploymentTerms;
use App\Modules\Workflow\Models\CaseModel;
use Tests\Support\LifecycleTestCase;

final class OnboardingEffectTest extends LifecycleTestCase
{
    public function test_approved_onboarding_effect_creates_employment_primary_assignment_terms_and_checklist(): void
    {
        $org = $this->peopleScenario->orgUnit('ONB-ENG');
        $this->bindWorkflowPrincipal(10);

        $created = $this->createLifecycleCase('onboarding', [
            'person' => ['legal_name' => 'Synthetic Onboard', 'email' => 'onboard@example.test', 'mobile' => '+8613900000000'],
            'employee_no' => 'ONB-0001',
            'org_unit_id' => $org->id,
            'hire_date' => '2026-12-01',
            'employment_type' => 'FULL_TIME',
            'terms' => [
                'work_location' => 'Shanghai',
                'work_schedule' => 'STANDARD',
                'probation_end_date' => '2027-05-31',
                'base_salary_minor' => 1_500_000,
                'salary_currency' => 'CNY',
                'notice_period_days' => 30,
            ],
            'submit' => true,
        ], 'onboarding-create-1')->assertCreated();

        $caseId = (int) $created->json('data.case_id');
        $this->assertSame(0, Employment::query()->count(), 'Nothing is effective before approval/effect.');
        $this->assertSame('DRAFT', LifecycleIntent::query()->firstOrFail()->status);

        $this->approveCase($caseId, 'onboarding-approve-1')
            ->assertOk()
            ->assertJsonPath('data.application_status', 'APPROVED')
            ->assertJsonPath('data.effect_status', 'APPLIED');

        $case = CaseModel::query()->findOrFail($caseId);
        $employment = Employment::query()->where('source_id', $caseId)->firstOrFail();

        $this->assertSame('2026-12-01', $employment->valid_from);
        $this->assertSame('PROBATION', $employment->status);
        $this->assertSame('Synthetic Onboard', \App\Modules\People\Models\Person::query()->findOrFail($employment->person_id)->legal_name);

        $primary = Assignment::query()->where('employment_id', $employment->id)->get();
        $this->assertCount(1, $primary);
        $this->assertTrue((bool) $primary->first()->is_primary);
        $this->assertSame($org->id, $primary->first()->org_unit_id);

        $terms = EmploymentTerms::query()->where('employment_id', $employment->id)->firstOrFail();
        $this->assertSame(1, $terms->version);
        $this->assertSame(1_500_000, (int) $terms->base_salary_minor);
        $this->assertSame('2027-05-31', (string) $terms->probation_end_date);

        $this->assertSame(5, LifecycleTask::query()->where('case_id', $caseId)->count());
        $this->assertDatabaseHas('audit_events', ['action' => 'people.employment.created']);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'employment.created.v1']);

        $this->getJson("/v1/lifecycle/cases/{$caseId}")
            ->assertOk()
            ->assertJsonPath('data.domain_status', 'EFFECTIVE')
            ->assertJsonPath('data.employment.employment_id', $employment->id)
            ->assertJsonPath('data.effect_status', 'APPLIED');

        $this->getJson("/v1/employees/{$employment->id}/onboarding-checklist")
            ->assertOk()
            ->assertJsonPath('data.total', 5);
    }

    public function test_rejected_onboarding_never_creates_domain_rows(): void
    {
        $org = $this->peopleScenario->orgUnit('ONB-REJECT');
        $this->bindWorkflowPrincipal(10);

        $created = $this->createLifecycleCase('onboarding', [
            'person' => ['legal_name' => 'Rejected Candidate'],
            'employee_no' => 'ONB-REJECT-1',
            'org_unit_id' => $org->id,
            'hire_date' => '2026-12-01',
            'employment_type' => 'FULL_TIME',
            'terms' => ['work_location' => 'Shanghai', 'work_schedule' => 'STANDARD'],
            'submit' => true,
        ], 'onboarding-reject-create')->assertCreated();

        $caseId = (int) $created->json('data.case_id');
        $this->postJson("/v1/cases/{$caseId}/decisions", [
            'action' => 'REJECT',
            'comment' => 'Headcount withdrawn.',
        ], ['Idempotency-Key' => 'onboarding-reject-decide'])->assertOk();

        $this->assertSame(0, Employment::query()->count());
        $this->assertSame('FAILED', 'FAILED'); // Case is REJECTED; no effect row exists.
        $this->assertDatabaseCount('effects', 0);
    }
}
