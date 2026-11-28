<?php

declare(strict_types=1);

namespace Tests\Integration\Payroll;

use App\Modules\Lifecycle\Models\EmploymentContractVersion;
use App\Modules\Payroll\Models\EmployeeCompensation;
use App\Modules\People\Models\Employment;
use App\Modules\People\Models\EmploymentContract;
use Tests\Support\PayrollTestCase;

final class ContractCompensationChainTest extends PayrollTestCase
{
    public function test_execution_compensation_links_contract_version_scheme_version_and_parameters(): void
    {
        $base = $this->seedPayrollBase();
        $employmentId = (int) $base['employment_id'];
        $scheme = $base['scheme'];
        $contractVersion = $this->contractVersion($this->scenario->employment('E0001', 1_000_000), 'CC-CHAIN-1');

        $response = $this->postJson('/v1/payroll/employee-plans', [
            'employment_id' => $employmentId,
            'contract_version_id' => (int) $contractVersion->id,
            'payroll_scheme_id' => (int) $scheme->id,
            // Client's stale version is ignored in favour of the published row.
            'payroll_scheme_version' => 99,
            'parameter_overrides' => [
                'meal_per_day_cents' => 2000,
                'attendance_bonus' => '0.100000',
                'optional_insurance' => false,
            ],
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-12-31',
            'base_salary_cents' => 800000,
            'currency' => 'CNY',
        ], $this->headers('chain-1'))->assertCreated()
            ->assertJsonPath('data.contract_version_id', (int) $contractVersion->id)
            ->assertJsonPath('data.payroll_scheme_id', (int) $scheme->id)
            ->assertJsonPath('data.payroll_scheme_version', (int) $scheme->version)
            ->assertJsonPath('data.parameter_overrides.meal_per_day_cents', 2000);

        $this->assertSame(64, strlen((string) $response->json('data.content_hash')));
        $row = EmployeeCompensation::query()->firstOrFail();
        $this->assertSame((int) $contractVersion->id, (int) $row->contract_version_id);
        $this->assertSame((int) $scheme->id, (int) $row->payroll_scheme_id);
        $this->assertSame((int) $scheme->version, (int) $row->payroll_scheme_version);
        $this->assertSame('ACTIVE', (string) $row->status);
        $this->assertSame(2000, $row->parameter_overrides_json['meal_per_day_cents']);
    }

    public function test_contract_version_must_belong_to_the_same_employment_and_cover_the_effective_date(): void
    {
        $base = $this->seedPayrollBase();
        $employmentId = (int) $base['employment_id'];
        $scheme = $base['scheme'];
        $otherEmployment = $this->scenario->employment('E0002', 900000);
        $otherVersion = $this->contractVersion($otherEmployment, 'CC-OTHER-1');

        $this->postJson('/v1/payroll/employee-plans', [
            'employment_id' => $employmentId,
            'contract_version_id' => (int) $otherVersion->id,
            'payroll_scheme_id' => (int) $scheme->id,
            'effective_from' => '2026-01-01',
            'base_salary_cents' => 800000,
            'currency' => 'CNY',
        ], $this->headers('chain-mismatch-1'))
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'COMPENSATION_CONTRACT_EMPLOYMENT_MISMATCH');

        $ownVersion = $this->contractVersion($this->scenario->employment('E0001', 1_000_000), 'CC-OWN-1', '2026-06-01', '2026-12-31');
        $this->postJson('/v1/payroll/employee-plans', [
            'employment_id' => $employmentId,
            'contract_version_id' => (int) $ownVersion->id,
            'payroll_scheme_id' => (int) $scheme->id,
            'effective_from' => '2026-01-01',
            'base_salary_cents' => 800000,
            'currency' => 'CNY',
        ], $this->headers('chain-not-effective-1'))
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'COMPENSATION_CONTRACT_NOT_EFFECTIVE');

        $this->assertSame(0, EmployeeCompensation::query()->count());
    }

    public function test_parameter_overrides_reject_nested_structures_and_invalid_ranges(): void
    {
        $base = $this->seedPayrollBase();
        $employmentId = (int) $base['employment_id'];
        $scheme = $base['scheme'];
        $version = $this->contractVersion($this->scenario->employment('E0001', 1_000_000), 'CC-PARAM-1');

        $this->postJson('/v1/payroll/employee-plans', [
            'employment_id' => $employmentId,
            'contract_version_id' => (int) $version->id,
            'payroll_scheme_id' => (int) $scheme->id,
            'parameter_overrides' => ['nested' => ['not' => 'allowed']],
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-01-01',
            'base_salary_cents' => 800000,
            'currency' => 'CNY',
        ], $this->headers('chain-param-1'))->assertStatus(422);

        $this->assertSame(0, EmployeeCompensation::query()->count());
    }

    /**
     * @return array<string, string>
     */
    private function headers(string $idempotencyKey = ''): array
    {
        $headers = ['X-CSRF-Token' => 'test-csrf-token'];

        if ($idempotencyKey !== '') {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        return $headers;
    }

    private function contractVersion(
        Employment $employment,
        string $contractNo,
        string $from = '2026-01-01',
        string $to = '2026-12-31',
    ): EmploymentContractVersion {
        $contract = EmploymentContract::query()->create([
            'employment_id' => (int) $employment->id,
            'contract_no' => $contractNo,
            'contract_type' => 'FIXED_TERM',
            'start_date' => $from,
            'end_date' => $to,
            'status' => 'ACTIVE',
            'version' => 1,
            'recorded_at' => $this->clock->now(),
            'source_type' => 'TEST',
        ]);

        return EmploymentContractVersion::query()->create([
            'employment_id' => (int) $employment->id,
            'contract_id' => (int) $contract->id,
            'change_type' => 'CREATION',
            'terms_version' => 1,
            'document_version' => 1,
            'effective_from' => $from,
            'effective_to' => $to,
            'agreed_salary_minor' => 800000,
            'agreed_currency' => 'CNY',
            'terms_hash' => hash('sha256', $contractNo),
            'status' => 'RECORDED',
            'snapshot_json' => ['agreed_salary_cents' => 800000, 'agreed_currency' => 'CNY'],
            'recorded_at' => $this->clock->now(),
        ]);
    }
}
