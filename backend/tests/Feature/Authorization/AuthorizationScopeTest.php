<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Modules\Organization\Contracts\OrganizationDirectory;
use App\Modules\People\Contracts\PeopleDirectory;
use App\Shared\Authorization\Authorizer;
use Tests\Support\DirectoryScopeAuthorizer;
use Tests\Support\PeopleTestCase;
use Tests\Support\TestPrincipal;

final class AuthorizationScopeTest extends PeopleTestCase
{
    public function test_self_scope_resolves_only_the_principals_employment(): void
    {
        $scenario = $this->scenario();
        $org = $scenario->orgUnit('AUTH-SELF');
        $self = $scenario->employment($scenario->person(), 'SELF-1');
        $other = $scenario->employment($scenario->person(), 'SELF-2');
        $scenario->assignment($self, $org);
        $scenario->assignment($other, $org);
        $scenario->userLink(77, $self);

        $principal = new TestPrincipal(userId: 77, employmentId: $self->id);
        $this->app->instance(\App\Modules\IAM\Contracts\Principal::class, $principal);
        $authorizer = new DirectoryScopeAuthorizer(
            app(PeopleDirectory::class),
            app(OrganizationDirectory::class),
            'SELF',
        );
        $this->app->instance(Authorizer::class, $authorizer);

        $this->getJson('/v1/employees?as_of=2026-11-23')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.employment_id', $self->id);
    }

    public function test_managed_scope_resolves_direct_reports(): void
    {
        $scenario = $this->scenario();
        $org = $scenario->orgUnit('AUTH-MANAGED');
        $managerEmployment = $scenario->employment($scenario->person(), 'MGR-1');
        $managerAssignment = $scenario->assignment($managerEmployment, $org);
        $report = $scenario->employment($scenario->person(), 'REP-1');
        $scenario->assignment($report, $org, ['manager_assignment_id' => $managerAssignment->id]);
        $unrelated = $scenario->employment($scenario->person(), 'UNRELATED-1');
        $scenario->assignment($unrelated, $org);

        $principal = new TestPrincipal(userId: 90, employmentId: $managerEmployment->id);
        $this->app->instance(\App\Modules\IAM\Contracts\Principal::class, $principal);
        $this->app->instance(Authorizer::class, new DirectoryScopeAuthorizer(
            app(PeopleDirectory::class),
            app(OrganizationDirectory::class),
            'MANAGED',
        ));

        $this->getJson('/v1/employees?as_of=2026-11-23')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.employment_id', $report->id);
    }

    public function test_org_subtree_scope_resolves_descendant_org_members(): void
    {
        $scenario = $this->scenario();
        $division = $scenario->orgUnit('AUTH-DIV', null, 'Division', 'DIVISION');
        $team = $scenario->orgUnit('AUTH-TEAM', $division);
        $outside = $scenario->orgUnit('AUTH-OUTSIDE');
        $inTeam = $scenario->employment($scenario->person(), 'DEPT-1');
        $scenario->assignment($inTeam, $team);
        $out = $scenario->employment($scenario->person(), 'OUT-1');
        $scenario->assignment($out, $outside);

        $principal = new TestPrincipal(userId: 91);
        $this->app->instance(Authorizer::class, new DirectoryScopeAuthorizer(
            app(PeopleDirectory::class),
            app(OrganizationDirectory::class),
            'ORG_SUBTREE',
            $division->id,
        ));

        $this->getJson('/v1/employees?as_of=2026-11-23')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.employment_id', $inTeam->id);
    }

    public function test_company_scope_does_not_leak_into_department_scope(): void
    {
        $scenario = $this->scenario();
        $hr = $scenario->orgUnit('HR-SCOPE');
        $finance = $scenario->orgUnit('FIN-SCOPE');
        $hrEmployment = $scenario->employment($scenario->person(), 'HR-1');
        $financeEmployment = $scenario->employment($scenario->person(), 'FIN-1');
        $scenario->assignment($hrEmployment, $hr);
        $scenario->assignment($financeEmployment, $finance);

        $principal = new TestPrincipal(userId: 92);
        $this->app->instance(Authorizer::class, new DirectoryScopeAuthorizer(
            app(PeopleDirectory::class),
            app(OrganizationDirectory::class),
            'ORG_UNIT',
            $hr->id,
        ));

        $this->getJson('/v1/employees?as_of=2026-11-23')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.employment_id', $hrEmployment->id);
    }

    public function test_out_of_scope_employee_detail_and_unknown_department_are_hidden(): void
    {
        $scenario = $this->scenario();
        $org = $scenario->orgUnit('SCOPE-ORG');
        $employment = $scenario->employment($scenario->person(), 'SCOPE-1');
        $scenario->assignment($employment, $org);

        $principal = new TestPrincipal(userId: 99);
        $this->app->instance(\App\Modules\IAM\Contracts\Principal::class, $principal);
        $this->app->instance(Authorizer::class, new DirectoryScopeAuthorizer(
            app(PeopleDirectory::class),
            app(OrganizationDirectory::class),
            'ORG_UNIT',
            $org->id + 5000,
        ));

        $this->getJson("/v1/employees/{$employment->id}?as_of=2026-11-23")->assertNotFound();
    }

    public function test_unauthenticated_request_is_401(): void
    {
        $this->forgetPrincipal();

        $this->getJson('/v1/employees?as_of=2026-11-23')
            ->assertUnauthorized()
            ->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    public function test_missing_capability_is_403(): void
    {
        $this->authorizer->deniedCapabilities = ['people.employee.read'];

        $this->getJson('/v1/employees?as_of=2026-11-23')
            ->assertForbidden()
            ->assertJsonPath('code', 'FORBIDDEN');
    }
}
