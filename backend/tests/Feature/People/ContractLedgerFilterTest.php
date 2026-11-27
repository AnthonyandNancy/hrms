<?php

declare(strict_types=1);

namespace Tests\Feature\People;

use App\Modules\Organization\Contracts\OrganizationDirectory;
use App\Modules\People\Contracts\PeopleDirectory;
use App\Modules\People\Models\EmploymentContract;
use App\Shared\Authorization\Authorizer;
use Tests\Support\DirectoryScopeAuthorizer;
use Tests\Support\PeopleTestCase;
use Tests\Support\TestPrincipal;

final class ContractLedgerFilterTest extends PeopleTestCase
{
    public function test_ledger_filters_by_employee_and_contract_no_on_the_server(): void
    {
        $scenario = $this->scenario();
        $org = $scenario->orgUnit('FILTER-ORG');
        $mine = $scenario->employment($scenario->person(['legal_name' => '合成筛选员工甲']), 'FILTER-0007');
        $other = $scenario->employment($scenario->person(['legal_name' => '合成筛选员工乙']), 'FILTER-0008');
        $scenario->assignment($mine, $org);
        $scenario->assignment($other, $org);

        $this->contract($mine->id, 'HT-0007');
        $this->contract($other->id, 'HT-0008');

        $this->getJson('/v1/employments/contracts?as_of=2026-11-27&employment_id='.$mine->id.'&q=HT-0007')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.employment_id', $mine->id)
            ->assertJsonPath('data.items.0.contract_no', 'HT-0007')
            ->assertJsonPath('data.items.0.employee_no', 'FILTER-0007')
            ->assertJsonPath('data.items.0.employee_name', '合成筛选员工甲');
    }

    public function test_keyword_searches_employee_name_and_employee_no_without_client_side_trimming(): void
    {
        $scenario = $this->scenario();
        $org = $scenario->orgUnit('KW-ORG');
        $mine = $scenario->employment($scenario->person(['legal_name' => '合成关键词员工']), 'KW-0001');
        $other = $scenario->employment($scenario->person(['legal_name' => '另一个合成员工']), 'KW-0002');
        $scenario->assignment($mine, $org);
        $scenario->assignment($other, $org);

        $this->contract($mine->id, 'KW-CONTRACT-1');
        $this->contract($other->id, 'KW-CONTRACT-2');

        $this->getJson('/v1/employments/contracts?as_of=2026-11-27&q='.urlencode('关键词员工'))
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.contract_no', 'KW-CONTRACT-1');

        $this->getJson('/v1/employments/contracts?as_of=2026-11-27&q=KW-0002')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.contract_no', 'KW-CONTRACT-2');
    }

    public function test_expiry_window_and_expired_filters_are_server_side_and_respect_business_date(): void
    {
        $scenario = $this->scenario();
        $employment = $scenario->employment($scenario->person(['legal_name' => '合成到期员工']), 'EXP-FILTER-1');
        $scenario->assignment($employment, $scenario->orgUnit('EXP-FILTER-ORG'));

        $this->contract($employment->id, 'EXP-IN-7', '2026-12-04');
        $this->contract($employment->id, 'EXP-IN-30', '2026-12-20');
        $this->contract($employment->id, 'EXP-IN-60', '2027-01-20');
        $this->contract($employment->id, 'EXP-PAST', '2026-11-01');

        $this->getJson('/v1/employments/contracts?as_of=2026-11-27&expiry_window=7')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.contract_no', 'EXP-IN-7');

        $this->getJson('/v1/employments/contracts?as_of=2026-11-27&expiry_window=30')
            ->assertOk()
            ->assertJsonPath('data.total', 2);

        $this->getJson('/v1/employments/contracts?as_of=2026-11-27&expiry_window=60')
            ->assertOk()
            ->assertJsonPath('data.total', 3);

        $this->getJson('/v1/employments/contracts?as_of=2026-11-27&expiry_window=EXPIRED')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.contract_no', 'EXP-PAST')
            ->assertJsonPath('data.items.0.display_status', 'EXPIRED');
    }

    public function test_employment_scope_applies_to_keyword_and_expiry_queries_before_pagination(): void
    {
        $scenario = $this->scenario();
        $visibleOrg = $scenario->orgUnit('LEDGER-VISIBLE');
        $hiddenOrg = $scenario->orgUnit('LEDGER-HIDDEN');

        $visible = $scenario->employment($scenario->person(['legal_name' => '合成可见员工']), 'LEDGER-VIS');
        $hidden = $scenario->employment($scenario->person(['legal_name' => '合成不可见员工']), 'LEDGER-HID');
        $scenario->assignment($visible, $visibleOrg);
        $scenario->assignment($hidden, $hiddenOrg);

        $this->contract($visible->id, 'LEDGER-VIS-1', '2026-12-01');
        $this->contract($hidden->id, 'LEDGER-HID-1', '2026-12-01');

        $this->app->instance(TestPrincipal::class, new TestPrincipal(801));
        $this->app->instance(Authorizer::class, new DirectoryScopeAuthorizer(
            app(PeopleDirectory::class),
            app(OrganizationDirectory::class),
            'ORG_UNIT',
            $visibleOrg->id,
        ));

        $this->getJson('/v1/employments/contracts?as_of=2026-11-27&q=LEDGER-&page=1&page_size=20')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.contract_no', 'LEDGER-VIS-1');

        $this->getJson('/v1/employments/contracts?as_of=2026-11-27&expiry_window=60&page=1&page_size=20')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.contract_no', 'LEDGER-VIS-1');
    }

    public function test_detail_reads_the_real_contract_even_beyond_the_first_ledger_page(): void
    {
        $scenario = $this->scenario();
        $deepOrg = $scenario->orgUnit('DEEP-ORG');
        $target = $scenario->employment($scenario->person(['legal_name' => '合成深链员工']), 'DEEP-0001');
        $scenario->assignment($target, $deepOrg);

        $targetContract = $this->contract($target->id, 'DEEP-CONTRACT-1', '2027-12-31');

        for ($index = 0; $index < 25; $index++) {
            $other = $scenario->employment($scenario->person(['legal_name' => "合成分页员工{$index}"]), sprintf('DEEP-%04d', $index + 2));
            $scenario->assignment($other, $deepOrg);
            $this->contract($other->id, sprintf('DEEP-OTHER-%02d', $index), '2028-12-31');
        }

        $firstPage = $this->getJson('/v1/employments/contracts?as_of=2026-11-27&page=1&page_size=20')->assertOk();
        $this->assertNotContains(
            $targetContract->id,
            array_map(static fn (array $row): int => (int) $row['id'], $firstPage->json('data.items')),
        );

        $this->getJson("/v1/employments/{$target->id}/contracts/{$targetContract->id}?as_of=2026-11-27")
            ->assertOk()
            ->assertJsonPath('data.id', $targetContract->id)
            ->assertJsonPath('data.employment_id', $target->id)
            ->assertJsonPath('data.contract_no', 'DEEP-CONTRACT-1')
            ->assertJsonPath('data.employee_no', 'DEEP-0001')
            ->assertJsonPath('data.employee_name', '合成深链员工')
            ->assertJsonPath('data.display_status_label', '生效中');
    }

    public function test_detail_rejects_a_contract_from_a_different_employment_and_unknown_ids(): void
    {
        $scenario = $this->scenario();
        $first = $scenario->employment($scenario->person(['legal_name' => '合成详情员工甲']), 'DETAIL-0001');
        $second = $scenario->employment($scenario->person(['legal_name' => '合成详情员工乙']), 'DETAIL-0002');
        $detailOrg = $scenario->orgUnit('DETAIL-ORG');
        $scenario->assignment($first, $detailOrg);
        $scenario->assignment($second, $detailOrg);

        $contract = $this->contract($second->id, 'DETAIL-CONTRACT-2');

        $this->getJson("/v1/employments/{$first->id}/contracts/{$contract->id}")
            ->assertNotFound();

        $this->getJson("/v1/employments/{$first->id}/contracts/999999")
            ->assertNotFound();
    }

    private function contract(int $employmentId, string $contractNo, string $endDate = '2026-12-31'): EmploymentContract
    {
        /** @var EmploymentContract $contract */
        $contract = EmploymentContract::query()->create([
            'employment_id' => $employmentId,
            'contract_no' => $contractNo,
            'contract_type' => 'FIXED_TERM',
            'start_date' => '2026-01-01',
            'end_date' => $endDate,
            'signed_on' => '2025-12-20',
            'status' => 'ACTIVE',
            'version' => 1,
            'recorded_at' => $this->clock->now(),
            'source_type' => 'TEST',
        ]);

        return $contract;
    }
}
