<?php

declare(strict_types=1);

namespace Tests\Feature\Lifecycle;

use App\Modules\People\Contracts\EmploymentLifecycleDirectory;
use App\Modules\People\Models\EmploymentTerms;
use Tests\Support\LifecycleTestCase;

final class SalaryChangeHistoryTest extends LifecycleTestCase
{
    public function test_salary_change_appends_terms_and_never_rewrites_history(): void
    {
        $scenario = $this->peopleScenario;
        $employment = $scenario->employment($scenario->person(), 'SAL-0001');
        $scenario->terms($employment, 1, [
            'effective_from' => '2026-01-01',
            'base_salary_minor' => 1_000_000,
            'salary_currency' => 'CNY',
        ]);

        $created = $this->createLifecycleCase('salary-changes', [
            'employment_id' => $employment->id,
            'effective_date' => '2027-01-01',
            'base_salary_minor' => 1_350_000,
            'salary_currency' => 'CNY',
            'reason' => 'Annual raise',
            'submit' => true,
        ], 'salary-create')->assertCreated();

        $this->approveCase((int) $created->json('data.case_id'), 'salary-approve')->assertOk();

        $directory = app(EmploymentLifecycleDirectory::class);
        $this->assertSame(1_000_000, $directory->termsAsOf($employment->id, '2026-12-31')?->baseSalaryMinor);
        $this->assertSame(1_350_000, $directory->termsAsOf($employment->id, '2027-01-01')?->baseSalaryMinor);

        $rows = EmploymentTerms::query()->where('employment_id', $employment->id)->orderBy('version')->get();
        $this->assertCount(2, $rows);
        $this->assertSame(1_000_000, (int) $rows[0]->base_salary_minor);
        $this->assertSame('2027-01-01', (string) $rows[0]->effective_to);
    }
}
