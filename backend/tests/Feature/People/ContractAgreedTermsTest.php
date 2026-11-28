<?php

declare(strict_types=1);

namespace Tests\Feature\People;

use App\Modules\Lifecycle\Models\EmploymentContractVersion;
use App\Modules\People\Models\Employment;
use App\Modules\People\Models\EmploymentContract;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\PeopleTestCase;

final class ContractAgreedTermsTest extends PeopleTestCase
{
    public function test_agreed_salary_is_stored_on_an_append_only_contract_version(): void
    {
        $employment = $this->employment('AGREE-0001');

        $response = $this->postJson("/v1/employments/{$employment->id}/contracts", [
            'contract_no' => 'AGREE-C-1',
            'contract_type' => 'FIXED_TERM',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'DRAFT',
            'agreed_salary_cents' => 900000,
            'agreed_currency' => 'CNY',
        ], ['Idempotency-Key' => 'agreed-contract-1'])->assertCreated();

        $contractId = (int) $response->json('data.id');
        $version = EmploymentContractVersion::query()
            ->where('contract_id', $contractId)
            ->where('change_type', 'CREATION')
            ->firstOrFail();

        $this->assertSame(900000, (int) $version->agreed_salary_minor);
        $this->assertSame('CNY', (string) $version->agreed_currency);
        $this->assertSame(64, strlen((string) $version->terms_hash));

        try {
            $version->forceFill(['reason' => '不可改写的合成变更'])->save();
            $this->fail('Contract versions must be append-only.');
        } catch (\DomainException $exception) {
            $this->assertStringContainsString('append-only', $exception->getMessage());
        }

        try {
            DB::table('employment_contract_versions')->where('id', $version->id)->update(['reason' => 'raw update']);
            $this->fail('The database must reject a raw contract-version update.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('append-only', $exception->getMessage());
        }
    }

    public function test_contract_activation_is_effective_dated_and_returns_object_actions(): void
    {
        $employment = $this->employment('ACTIVATE-0001');
        $created = $this->postJson("/v1/employments/{$employment->id}/contracts", [
            'contract_no' => 'ACTIVATE-C-1',
            'contract_type' => 'FIXED_TERM',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'DRAFT',
            'agreed_salary_cents' => 850000,
            'agreed_currency' => 'CNY',
        ], ['Idempotency-Key' => 'activate-create-1'])->assertCreated();

        $contractId = (int) $created->json('data.id');

        $this->postJson("/v1/employment-contracts/{$contractId}/activate", [
            'effective_from' => '2026-01-01',
            'reason' => '合成合同激活',
        ], ['Idempotency-Key' => 'activate-1'])
            ->assertOk()
            ->assertJsonPath('data.contract.status', 'ACTIVE')
            ->assertJsonPath('data.version.change_type', 'ACTIVATION')
            ->assertJsonPath('data.version.agreed_salary_minor', 850000);

        $this->postJson("/v1/employment-contracts/{$contractId}/activate", [
            'effective_from' => '2026-01-01',
            'reason' => '不应重复激活',
        ], ['Idempotency-Key' => 'activate-2'])->assertStatus(409);

        $detail = $this->getJson("/v1/employments/{$employment->id}/contracts/{$contractId}")
            ->assertOk()
            ->assertJsonPath('data.state', 'ACTIVE')
            ->assertJsonPath('data.agreed_salary_cents', 850000)
            ->json('data');

        $this->assertContains('people.contract.detail.amend', $detail['allowed_actions']);
        $this->assertContains('people.contract.detail.terminate', $detail['allowed_actions']);
        $this->assertSame(64, strlen((string) $detail['source_version']));

        $versions = $this->getJson("/v1/employments/{$employment->id}/contract-versions?as_of=2026-01-01")
            ->assertOk()
            ->assertJsonPath('data.total', 2)
            ->json('data.items');

        $this->assertSame(['CREATION', 'ACTIVATION'], array_column($versions, 'change_type'));
    }

    public function test_overlapping_active_contract_for_the_same_employment_is_rejected(): void
    {
        $employment = $this->employment('OVERLAP-0001');

        $this->postJson("/v1/employments/{$employment->id}/contracts", [
            'contract_no' => 'OVERLAP-C-1',
            'contract_type' => 'FIXED_TERM',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'ACTIVE',
        ], ['Idempotency-Key' => 'overlap-1'])->assertCreated();

        $this->postJson("/v1/employments/{$employment->id}/contracts", [
            'contract_no' => 'OVERLAP-C-2',
            'contract_type' => 'FIXED_TERM',
            'start_date' => '2026-06-01',
            'end_date' => '2027-05-31',
            'status' => 'ACTIVE',
        ], ['Idempotency-Key' => 'overlap-2'])
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'CONTRACT_PERIOD_OVERLAP');

        $this->assertSame(1, EmploymentContract::query()->where('employment_id', $employment->id)->count());
    }

    private function employment(string $employeeNo): Employment
    {
        $scenario = $this->scenario();
        $employment = $scenario->employment($scenario->person(['legal_name' => "合成员工 {$employeeNo}"]), $employeeNo);
        $scenario->assignment($employment, $scenario->orgUnit('AGREE-ORG-'.$employeeNo));

        return $employment;
    }
}
