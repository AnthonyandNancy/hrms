<?php

declare(strict_types=1);

namespace Tests\Feature\People;

use App\Modules\Organization\Contracts\OrganizationDirectory;
use App\Modules\People\Contracts\PeopleDirectory;
use App\Shared\Authorization\Authorizer;
use Tests\Support\DirectoryScopeAuthorizer;
use Tests\Support\PeopleTestCase;
use Tests\Support\TestPrincipal;

final class PaginationScopeTest extends PeopleTestCase
{
    public function test_total_counts_are_computed_after_row_scope(): void
    {
        $scenario = $this->scenario();
        $entity = $scenario->legalEntity();
        $mine = $scenario->orgUnit($entity, 'PAGE-MINE');
        $other = $scenario->orgUnit($entity, 'PAGE-OTHER');

        for ($i = 0; $i < 3; $i++) {
            $employment = $scenario->employment($scenario->person(), $entity, "PAGE-MINE-{$i}");
            $scenario->assignment($employment, $mine);
        }

        for ($i = 0; $i < 7; $i++) {
            $employment = $scenario->employment($scenario->person(), $entity, "PAGE-OTHER-{$i}");
            $scenario->assignment($employment, $other);
        }

        $principal = new TestPrincipal(1, 800);
        $this->app->instance(TestPrincipal::class, $principal);
        $this->app->instance(Authorizer::class, new DirectoryScopeAuthorizer(
            app(PeopleDirectory::class),
            app(OrganizationDirectory::class),
            $principal,
            'ORG_UNIT',
            $mine->id,
        ));

        $response = $this->getJson('/v1/employees?as_of=2026-11-23&page=2&page_size=2')->assertOk();

        $this->assertSame(3, $response->json('data.total'));
        $this->assertCount(1, $response->json('data.items'));
        $this->assertSame(2, $response->json('data.page'));
        $this->assertSame(2, $response->json('data.page_size'));
    }

    public function test_last_page_returns_remaining_rows_in_stable_order(): void
    {
        $scenario = $this->scenario();
        $entity = $scenario->legalEntity();
        $org = $scenario->orgUnit($entity, 'PAGE-STABLE');

        for ($i = 0; $i < 5; $i++) {
            $employment = $scenario->employment($scenario->person(), $entity, "PAGE-STABLE-{$i}");
            $scenario->assignment($employment, $org);
        }

        $response = $this->getJson('/v1/employees?as_of=2026-11-23&page=3&page_size=2')->assertOk();

        $this->assertSame(5, $response->json('data.total'));
        $this->assertCount(1, $response->json('data.items'));
    }
}
