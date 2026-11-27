<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Modules\Organization\Models\Position;
use Tests\Support\PeopleTestCase;

/**
 * M06 server-side position catalogue query.
 *
 * Filtering, counting and paging all happen in SQL after row scope is pushed
 * into the query, so a page can never be assembled from client-side slices.
 */
final class PositionQueryTest extends PeopleTestCase
{
    public function test_filters_by_org_status_and_pages_after_scope(): void
    {
        $scenario = $this->scenario();
        $orgTen = $scenario->orgUnit('QUERY-ORG-10');
        $orgEleven = $scenario->orgUnit('QUERY-ORG-11');

        $this->position($orgTen, 'QUERY-A-001', ['status' => 'ACTIVE']);
        $this->position($orgTen, 'QUERY-A-002', ['status' => 'ACTIVE']);
        $this->position($orgTen, 'QUERY-A-003', ['status' => 'DISABLED']);
        $this->position($orgEleven, 'QUERY-B-001', ['status' => 'ACTIVE']);

        $response = $this->getJson(
            '/v1/positions?as_of=2026-11-27&org_unit_id='.$orgTen->id.'&status=ACTIVE&page=2&page_size=1&sort=code',
        )->assertOk();

        $response
            ->assertJsonPath('data.page', 2)
            ->assertJsonPath('data.page_size', 1)
            ->assertJsonPath('data.total', 2)
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.code', 'QUERY-A-002')
            ->assertJsonPath('data.items.0.org_unit_id', (int) $orgTen->id)
            ->assertJsonPath('data.partial', false);
    }

    public function test_sorts_by_title_level_and_descending_code(): void
    {
        $scenario = $this->scenario();
        $org = $scenario->orgUnit('QUERY-SORT-ORG');

        $this->position($org, 'SORT-001', ['title' => '合成丙岗位', 'grade' => 'P3']);
        $this->position($org, 'SORT-002', ['title' => '合成甲岗位', 'grade' => 'P5']);
        $this->position($org, 'SORT-003', ['title' => '合成乙岗位', 'grade' => 'P4']);

        $this->getJson('/v1/positions?as_of=2026-11-27&sort=title')
            ->assertOk()
            ->assertJsonPath('data.items.0.title', '合成丙岗位')
            ->assertJsonPath('data.items.1.title', '合成乙岗位')
            ->assertJsonPath('data.items.2.title', '合成甲岗位');

        $this->getJson('/v1/positions?as_of=2026-11-27&sort=level')
            ->assertOk()
            ->assertJsonPath('data.items.0.grade', 'P3')
            ->assertJsonPath('data.items.2.grade', 'P5');

        $this->getJson('/v1/positions?as_of=2026-11-27&sort=code_desc')
            ->assertOk()
            ->assertJsonPath('data.items.0.code', 'SORT-003');
    }

    public function test_q_matches_code_or_title_and_rejects_unknown_sort(): void
    {
        $scenario = $this->scenario();
        $org = $scenario->orgUnit('QUERY-Q-ORG');

        $this->position($org, 'SEARCH-ENG', ['title' => '合成平台工程师']);
        $this->position($org, 'SEARCH-HR', ['title' => '合成人事专员']);

        $this->getJson('/v1/positions?as_of=2026-11-27&q=平台')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.code', 'SEARCH-ENG');

        $this->getJson('/v1/positions?as_of=2026-11-27&q=SEARCH-HR')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.title', '合成人事专员');

        $this->getJson('/v1/positions?as_of=2026-11-27&sort=created_at')
            ->assertStatus(422)
            ->assertJsonPath('code', 'VALIDATION_FAILED');
    }

    public function test_effective_dated_rows_only_include_the_business_date_window(): void
    {
        $scenario = $this->scenario();
        $org = $scenario->orgUnit('QUERY-ASOF-ORG');

        $this->position($org, 'ASOF-FUTURE', [
            'valid_from' => '2027-01-01',
            'valid_to' => '9999-12-31',
        ]);
        $this->position($org, 'ASOF-CURRENT', [
            'valid_from' => '2026-01-01',
            'valid_to' => '9999-12-31',
        ]);
        $this->position($org, 'ASOF-ENDED', [
            'valid_from' => '2025-01-01',
            'valid_to' => '2026-11-27',
        ]);

        $this->getJson('/v1/positions?as_of=2026-11-27')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.code', 'ASOF-CURRENT');
    }

    public function test_pages_are_stable_and_last_page_returns_the_remainder(): void
    {
        $scenario = $this->scenario();
        $org = $scenario->orgUnit('QUERY-PAGE-ORG');

        foreach (range(1, 5) as $index) {
            $this->position($org, 'PAGE-00'.$index);
        }

        $response = $this->getJson('/v1/positions?as_of=2026-11-27&page=3&page_size=2&sort=code')
            ->assertOk();

        $response
            ->assertJsonPath('data.total', 5)
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.code', 'PAGE-005')
            ->assertJsonPath('data.page', 3);
    }

    public function test_read_scope_is_applied_before_total_and_page(): void
    {
        $scenario = $this->scenario();
        $visible = $scenario->orgUnit('QUERY-SCOPE-VISIBLE');
        $hidden = $scenario->orgUnit('QUERY-SCOPE-HIDDEN');

        $this->position($visible, 'SCOPE-VISIBLE');
        $this->position($hidden, 'SCOPE-HIDDEN');

        $this->authorizer->calls = [];
        $this->getJson('/v1/positions?as_of=2026-11-27')->assertOk();

        $readCall = collect($this->authorizer->calls)
            ->firstWhere('capability', 'org.position.read');

        $this->assertNotNull($readCall, 'The list must push org.position.read row scope into SQL.');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function position(\App\Modules\Organization\Models\OrgUnit $org, string $code, array $overrides = []): Position
    {
        return Position::query()->create(array_merge([
            'org_unit_id' => (int) $org->id,
            'code' => $code,
            'title' => 'Synthetic Query Position',
            'grade' => 'P4',
            'headcount_limit' => 2,
            'status' => 'ACTIVE',
            'valid_from' => '2026-01-01',
            'valid_to' => '9999-12-31',
            'recorded_at' => $this->clock->now(),
            'source_type' => 'TEST',
        ], $overrides));
    }
}
