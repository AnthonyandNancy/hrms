<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Modules\IAM\Models\AuthorityBinding;
use App\Modules\IAM\Models\User;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\FieldProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\PeopleScenario;
use Tests\Support\TestPrincipal;
use Tests\TestCase;

/**
 * Regression: COMPANY/SELF/MANAGED scopes must constrain the employee
 * query on `employments.id`, not on a non-existent `employment_id` column.
 * The previous predicate silently returned zero rows.
 */
final class EmploymentScopeColumnTest extends TestCase
{
    use RefreshDatabase;

    public function test_legal_entity_scope_returns_employments_for_the_entity(): void
    {
        $clock = new \App\Shared\Time\FrozenClock('2026-11-23T01:00:00Z');
        $this->app->instance(\App\Shared\Time\Clock::class, $clock);
        $scenario = new PeopleScenario(1, $clock);
        $entity = $scenario->legalEntity();
        $org = $scenario->orgUnit($entity, 'ORG-SCOPE', null, 'Scope Org');

        $employmentA = $scenario->employment($scenario->person(['legal_name' => 'Scope A']), $entity, 'SCOPE-1');
        $employmentB = $scenario->employment($scenario->person(['legal_name' => 'Scope B']), $entity, 'SCOPE-2');
        $scenario->assignment($employmentA, $org);
        $scenario->assignment($employmentB, $org);

        $user = User::factory()->create(['tenant_id' => 1]);
        AuthorityBinding::factory()
            ->forPrincipal(1, (int) $user->id)
            ->anchor(AnchorType::COMPANY, (int) $entity->id, FieldProfile::P1_INTERNAL)
            ->create(['capability_code' => 'people.employee.read']);

        // This regression targets the real Eloquent adapter and the generated
        // SQL, so use the production directory rather than the in-memory fake.
        $this->app->bind(
            \App\Shared\Authorization\Contracts\PersonEmploymentDirectory::class,
            \App\Modules\People\Authorization\EloquentPersonEmploymentDirectory::class,
        );

        $this->app->bind(
            \App\Shared\Authorization\Contracts\OrganizationScopeDirectory::class,
            \App\Modules\Organization\Authorization\EloquentOrganizationScopeDirectory::class,
        );

        $principal = new TestPrincipal(1, (int) $user->id);
        $authorizer = app(\App\Shared\Authorization\Authorizer::class);

        $count = $authorizer
            ->constrain(\App\Modules\People\Models\Employment::query(), $principal, 'people.employee.read', '2026-11-23')
            ->count();

        $this->assertSame(2, $count);
    }
}
