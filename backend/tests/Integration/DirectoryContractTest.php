<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Modules\Organization\Contracts\OrganizationDirectory;
use App\Modules\People\Contracts\EmploymentSnapshot;
use App\Modules\People\Contracts\PeopleDirectory;
use App\Shared\Authorization\Authorizer;
use Tests\Support\DirectoryScopeAuthorizer;
use Tests\Support\PeopleTestCase;
use Tests\Support\TestPrincipal;

final class DirectoryContractTest extends PeopleTestCase
{
    public function test_contracts_are_bound_to_real_directory_implementations(): void
    {
        $this->assertInstanceOf(\App\Modules\People\Services\PeopleDirectoryService::class, app(PeopleDirectory::class));
        $this->assertInstanceOf(\App\Modules\Organization\Services\OrganizationDirectoryService::class, app(OrganizationDirectory::class));
    }

    public function test_employment_for_user_and_tenant_isolation(): void
    {
        $scenario = $this->scenario(1);
        $entity = $scenario->legalEntity();
        $employment = $scenario->employment($scenario->person(), $entity, 'DIR-1');
        $scenario->userLink(501, $employment);

        $directory = app(PeopleDirectory::class);

        $this->assertSame($employment->id, $directory->employmentForUser(1, 501));
        $this->assertNull($directory->employmentForUser(2, 501));
        $this->assertTrue($directory->employmentExistsInTenant(1, $employment->id));
        $this->assertFalse($directory->employmentExistsInTenant(2, $employment->id));
        $this->assertSame([$entity->id], $directory->legalEntityIdsForEmployment(1, $employment->id));
        $this->assertSame([], $directory->legalEntityIdsForEmployment(2, $employment->id));
    }

    public function test_employment_for_user_uses_tenant_business_date_not_utc_date(): void
    {
        $scenario = $this->scenario();
        $entity = $scenario->legalEntity();
        $employment = $scenario->employment($scenario->person(), $entity, 'DIR-TZ');
        $scenario->userLink(502, $employment, validFrom: '2026-11-24');

        $this->clock->set('2026-11-23T16:30:00Z');

        $this->assertSame(
            $employment->id,
            app(PeopleDirectory::class)->employmentForUser(1, 502),
        );
    }

    public function test_employment_snapshot_is_as_of_and_carries_manager(): void
    {
        $scenario = $this->scenario();
        $entity = $scenario->legalEntity();
        $org = $scenario->orgUnit($entity, 'DIR-ORG');
        $managerEmployment = $scenario->employment($scenario->person(), $entity, 'DIR-M');
        $managerAssignment = $scenario->assignment($managerEmployment, $org);
        $reportEmployment = $scenario->employment($scenario->person(), $entity, 'DIR-R');
        $reportAssignment = $scenario->assignment($reportEmployment, $org, ['manager_assignment_id' => $managerAssignment->id]);

        $snapshot = app(PeopleDirectory::class)->employmentSnapshot(1, $reportEmployment->id, '2026-11-23');

        $this->assertInstanceOf(EmploymentSnapshot::class, $snapshot);
        $this->assertSame($reportEmployment->id, $snapshot->employmentId);
        $this->assertSame($entity->id, $snapshot->legalEntityId);
        $this->assertSame($org->id, $snapshot->orgUnitId);
        $this->assertSame($managerEmployment->id, $snapshot->managerEmploymentId);
        $this->assertSame($reportAssignment->id, $snapshot->assignmentId);
        $this->assertNull(app(PeopleDirectory::class)->employmentSnapshot(1, $reportEmployment->id, '2025-12-31'));
    }

    public function test_org_directory_tree_and_legal_entity_lookup(): void
    {
        $scenario = $this->scenario();
        $entity = $scenario->legalEntity();
        $root = $scenario->orgUnit($entity, 'DIR-ROOT', null, 'Root', 'COMPANY');
        $child = $scenario->orgUnit($entity, 'DIR-CHILD', $root);

        $directory = app(OrganizationDirectory::class);
        $tree = $directory->tree(1, $entity->id, '2026-11-23');

        $this->assertCount(2, $tree);
        $this->assertSame($root->id, $tree[0]['id']);
        $this->assertSame($root->id, $tree[1]['parent_id']);
        $this->assertSame($entity->id, $directory->legalEntityOfOrg(1, $child->id, '2026-11-23'));
        $this->assertNull($directory->legalEntityOfOrg(1, $child->id, '2025-12-31'));
        $this->assertSame([], $directory->orgUnitIds(2, [$child->id], '2026-11-23'));
    }

    public function test_iam_authorizer_scope_via_real_directories_for_manager_and_hr_and_self(): void
    {
        $scenario = $this->scenario();
        $entity = $scenario->legalEntity();
        $division = $scenario->orgUnit($entity, 'INT-DIV', null, 'Division', 'DIVISION');
        $team = $scenario->orgUnit($entity, 'INT-TEAM', $division);

        $manager = $scenario->employment($scenario->person(), $entity, 'INT-MGR');
        $managerAssignment = $scenario->assignment($manager, $division);
        $report = $scenario->employment($scenario->person(), $entity, 'INT-REP');
        $scenario->assignment($report, $team, ['manager_assignment_id' => $managerAssignment->id]);
        $unrelated = $scenario->employment($scenario->person(), $entity, 'INT-UNRELATED');
        $scenario->assignment($unrelated, $scenario->orgUnit($entity, 'INT-OTHER'));

        $people = app(PeopleDirectory::class);
        $organization = app(OrganizationDirectory::class);

        $managerPrincipal = new TestPrincipal(1, 700, $manager->id);
        $managed = new DirectoryScopeAuthorizer($people, $organization, $managerPrincipal, 'MANAGED');
        $this->assertSame(
            [$report->id],
            $managed->constrain(\App\Modules\People\Models\Employment::query(), $managerPrincipal, 'people.employee.read', '2026-11-23')
                ->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
        );

        $hrPrincipal = new TestPrincipal(1, 701);
        $legalEntity = new DirectoryScopeAuthorizer($people, $organization, $hrPrincipal, 'LEGAL_ENTITY', $entity->id);
        $this->assertCount(
            3,
            $legalEntity->constrain(\App\Modules\People\Models\Employment::query(), $hrPrincipal, 'people.employee.read', '2026-11-23')->get(),
        );

        $selfPrincipal = new TestPrincipal(1, 702, $report->id);
        $self = new DirectoryScopeAuthorizer($people, $organization, $selfPrincipal, 'SELF');
        $this->assertSame(
            [$report->id],
            $self->constrain(\App\Modules\People\Models\Employment::query(), $selfPrincipal, 'people.employee.read', '2026-11-23')
                ->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
        );

        $this->assertInstanceOf(Authorizer::class, $managed);
    }
}
