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
        $entity = $scenario->legalEntity();
        $org = $scenario->orgUnit($entity, 'AUTH-SELF');
        $self = $scenario->employment($scenario->person(), $entity, 'SELF-1');
        $other = $scenario->employment($scenario->person(), $entity, 'SELF-2');
        $scenario->assignment($self, $org);
        $scenario->assignment($other, $org);
        $scenario->userLink(77, $self);

        $principal = new TestPrincipal(1, 77, $self->id);
        $this->app->instance(\App\Modules\IAM\Contracts\Principal::class, $principal);
        $authorizer = new DirectoryScopeAuthorizer(
            app(PeopleDirectory::class),
            app(OrganizationDirectory::class),
            $principal,
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
        $entity = $scenario->legalEntity();
        $org = $scenario->orgUnit($entity, 'AUTH-MANAGED');
        $managerEmployment = $scenario->employment($scenario->person(), $entity, 'MGR-1');
        $managerAssignment = $scenario->assignment($managerEmployment, $org);
        $report = $scenario->employment($scenario->person(), $entity, 'REP-1');
        $scenario->assignment($report, $org, ['manager_assignment_id' => $managerAssignment->id]);
        $unrelated = $scenario->employment($scenario->person(), $entity, 'UNRELATED-1');
        $scenario->assignment($unrelated, $org);

        $principal = new TestPrincipal(1, 90, $managerEmployment->id);
        $this->app->instance(\App\Modules\IAM\Contracts\Principal::class, $principal);
        $this->app->instance(Authorizer::class, new DirectoryScopeAuthorizer(
            app(PeopleDirectory::class),
            app(OrganizationDirectory::class),
            $principal,
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
        $entity = $scenario->legalEntity();
        $division = $scenario->orgUnit($entity, 'AUTH-DIV', null, 'Division', 'DIVISION');
        $team = $scenario->orgUnit($entity, 'AUTH-TEAM', $division);
        $outside = $scenario->orgUnit($entity, 'AUTH-OUTSIDE');
        $inTeam = $scenario->employment($scenario->person(), $entity, 'DEPT-1');
        $scenario->assignment($inTeam, $team);
        $out = $scenario->employment($scenario->person(), $entity, 'OUT-1');
        $scenario->assignment($out, $outside);

        $principal = new TestPrincipal(1, 91);
        $this->app->instance(Authorizer::class, new DirectoryScopeAuthorizer(
            app(PeopleDirectory::class),
            app(OrganizationDirectory::class),
            $principal,
            'ORG_SUBTREE',
            $division->id,
        ));

        $this->getJson('/v1/employees?as_of=2026-11-23')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.employment_id', $inTeam->id);
    }

    public function test_legal_entity_scope_is_isolated(): void
    {
        $scenario = $this->scenario();
        $cn = $scenario->legalEntity('CN');
        $sg = $scenario->legalEntity('SG', 'Singapore', 'Asia/Singapore', 'SGD');
        $cnOrg = $scenario->orgUnit($cn, 'CN-ORG');
        $sgOrg = $scenario->orgUnit($sg, 'SG-ORG');
        $cnEmployment = $scenario->employment($scenario->person(), $cn, 'CN-1');
        $sgEmployment = $scenario->employment($scenario->person(), $sg, 'SG-1');
        $scenario->assignment($cnEmployment, $cnOrg);
        $scenario->assignment($sgEmployment, $sgOrg);

        $principal = new TestPrincipal(1, 92);
        $this->app->instance(Authorizer::class, new DirectoryScopeAuthorizer(
            app(PeopleDirectory::class),
            app(OrganizationDirectory::class),
            $principal,
            'LEGAL_ENTITY',
            $cn->id,
        ));

        $this->getJson('/v1/employees?as_of=2026-11-23')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.items.0.employment_id', $cnEmployment->id);
    }

    public function test_cross_tenant_detail_is_404(): void
    {
        $scenario = $this->scenario(1);
        $entity = $scenario->legalEntity();
        $org = $scenario->orgUnit($entity, 'TENANT-ORG');
        $employment = $scenario->employment($scenario->person(), $entity, 'TENANT-1');
        $scenario->assignment($employment, $org);

        $this->actAs(tenantId: 2, userId: 99);

        $this->getJson("/v1/employees/{$employment->id}?as_of=2026-11-23")->assertNotFound();
        $this->getJson("/v1/org-units/tree?as_of=2026-11-23&legal_entity_id={$entity->id}")->assertNotFound();
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
