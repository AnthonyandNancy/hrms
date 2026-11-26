<?php

declare(strict_types=1);

namespace Tests\Feature\Lifecycle;

use App\Modules\Lifecycle\Models\EmploymentContractVersion;
use App\Modules\People\Contracts\EmploymentLifecycleDirectory;
use App\Modules\People\Models\EmploymentTerms;
use Tests\Support\LifecycleTestCase;

final class ContractRenewalTest extends LifecycleTestCase
{
    public function test_contract_renewal_creates_next_terms_and_document_version_and_keeps_old_as_of(): void
    {
        $scenario = $this->peopleScenario;
        $employment = $scenario->employment($scenario->person(), 'REN-0001');
        $scenario->terms($employment, 1, [
            'effective_from' => '2026-01-01',
            'effective_to' => '9999-12-31',
            'base_salary_minor' => 1_000_000,
            'salary_currency' => 'CNY',
        ]);

        $created = $this->createLifecycleCase('contract-renewals', [
            'employment_id' => $employment->id,
            'effective_date' => '2027-01-01',
            'effective_to' => '2028-01-01',
            'expires_at' => '2028-01-01',
            'base_salary_minor' => 1_200_000,
            'submit' => true,
        ], 'renew-create')->assertCreated();

        $this->approveCase((int) $created->json('data.case_id'), 'renew-approve')->assertOk();

        $directory = app(EmploymentLifecycleDirectory::class);
        $before = $directory->termsAsOf($employment->id, '2026-12-31');
        $after = $directory->termsAsOf($employment->id, '2027-01-01');
        $this->assertNotNull($before);
        $this->assertNotNull($after);
        $this->assertSame(1, $before->version);
        $this->assertSame(1_000_000, $before->baseSalaryMinor);
        $this->assertSame(2, $after->version);
        $this->assertSame(1_200_000, $after->baseSalaryMinor);

        $versions = EmploymentTerms::query()->where('employment_id', $employment->id)->orderBy('version')->get();
        $this->assertCount(2, $versions);
        $this->assertSame('2027-01-01', (string) $versions->first()->effective_to);

        $contract = EmploymentContractVersion::query()->where('employment_id', $employment->id)->firstOrFail();
        $this->assertSame(2, (int) $contract->terms_version);
        $this->assertSame(1, (int) $contract->document_version);
    }
}
