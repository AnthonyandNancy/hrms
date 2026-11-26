<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Modules\IAM\Auth\CapabilityAuthorizer;
use App\Modules\IAM\Models\AuthorityBinding;
use App\Modules\IAM\Models\User;
use App\Shared\Authorization\AnchorRef;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\FieldProfile;

/**
 * Exercises all seven frozen anchors through the real binding repository and
 * explicit People/Organization/Workflow/Payroll fakes.
 */
final class AnchorScopeTest extends AuthorizationTestSupport
{
    public function test_self_anchor(): void
    {
        $user = User::factory()->create();
        $this->grant($user, 'time.leave.request', AnchorType::SELF, null, FieldProfile::P0_PUBLIC);
        $this->people->employmentByUser[(int) $user->id] = [10];

        $this->assertTrue($this->authorizer()->allows($this->actingAsUser($user, 10), 'time.leave.request', AnchorRef::self(), $this->date()));
    }

    public function test_managed_anchor(): void
    {
        $user = User::factory()->create();
        $this->grant($user, 'workflow.case.decide', AnchorType::MANAGED, null, FieldProfile::P1_INTERNAL);
        $this->people->managedByUser[(int) $user->id] = [20, 21];

        $this->assertTrue($this->authorizer()->allows($this->actingAsUser($user, 99), 'workflow.case.decide', AnchorRef::managed(), $this->date()));
    }

    public function test_org_unit_anchor(): void
    {
        $user = User::factory()->create();
        $this->grant($user, 'people.employee.read', AnchorType::ORG_UNIT, 30, FieldProfile::P1_INTERNAL);
        $this->organization->knownUnits[30] = true;

        $this->assertTrue($this->authorizer()->allows($this->actingAsUser($user), 'people.employee.read', AnchorRef::orgUnit(30), $this->date()));
        $this->assertFalse($this->authorizer()->allows($this->actingAsUser($user), 'people.employee.read', AnchorRef::orgUnit(31), $this->date()));
    }

    public function test_org_subtree_anchor(): void
    {
        $user = User::factory()->create();
        $this->grant($user, 'people.employee.read', AnchorType::ORG_SUBTREE, 40, FieldProfile::P1_INTERNAL);
        $this->organization->knownUnits[40] = true;
        $this->organization->knownUnits[41] = true;
        $this->organization->descendants[40] = [40, 41, 42];

        $this->assertTrue($this->authorizer()->allows($this->actingAsUser($user), 'people.employee.read', AnchorRef::orgSubtree(41), $this->date()));
        $this->assertFalse($this->authorizer()->allows($this->actingAsUser($user), 'people.employee.read', AnchorRef::orgSubtree(50), $this->date()));
    }

    public function test_case_anchor(): void
    {
        $user = User::factory()->create();
        $this->grant($user, 'workflow.case.decide', AnchorType::CASE, 60, FieldProfile::P1_INTERNAL);
        $this->cases->casesByUser[(int) $user->id] = [60];
        $this->cases->memberships[60] = ['workflow.case.decide'];

        $this->assertTrue($this->authorizer()->allows($this->actingAsUser($user), 'workflow.case.decide', AnchorRef::case(60), $this->date()));
        $this->assertFalse($this->authorizer()->allows($this->actingAsUser($user), 'workflow.case.decide', AnchorRef::case(61), $this->date()));
    }

    public function test_payroll_run_anchor(): void
    {
        $user = User::factory()->create();
        $this->grant($user, 'payroll.run.review', AnchorType::PAYROLL_RUN, 70, FieldProfile::P2_SENSITIVE);
        $this->payroll->runsByUser[(int) $user->id] = [70];
        $this->payroll->memberships[70] = ['payroll.run.review'];

        $this->assertTrue($this->authorizer()->allows($this->actingAsUser($user), 'payroll.run.review', AnchorRef::payrollRun(70), $this->date()));
        $this->assertFalse($this->authorizer()->allows($this->actingAsUser($user), 'payroll.run.review', AnchorRef::payrollRun(71), $this->date()));
    }

    public function test_company_anchor_is_instance_wide_and_does_not_require_an_id(): void
    {
        $user = User::factory()->create();
        $this->grant($user, 'people.employee.read', AnchorType::COMPANY, null, FieldProfile::P1_INTERNAL);

        $this->assertTrue($this->authorizer()->allows($this->actingAsUser($user), 'people.employee.read', AnchorRef::company(), $this->date()));
        $this->assertFalse(AnchorType::COMPANY->requiresAnchorId());
    }

    public function test_company_anchor_is_not_implied_by_another_principals_department_binding(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        AuthorityBinding::factory()->forPrincipal((int) $other->id)->anchor(AnchorType::ORG_UNIT, 30)->create([
            'capability_code' => 'people.employee.read',
        ]);
        $this->organization->knownUnits[30] = true;

        $principal = $this->actingAsUser($user);

        $this->assertFalse($this->authorizer()->allows($principal, 'people.employee.read', AnchorRef::company(), $this->date()));
        $this->assertFalse($this->authorizer()->allows($principal, 'people.employee.read', AnchorRef::orgUnit(30), $this->date()));
    }

    public function test_every_binding_carries_a_known_anchor_type(): void
    {
        foreach (AnchorType::cases() as $anchor) {
            $this->assertNotNull(AnchorType::tryFrom($anchor->value));
        }

        $this->assertNull(AnchorType::tryFrom('CUSTOM'));
    }

    private function grant(User $user, string $capability, AnchorType $anchor, ?int $anchorId, FieldProfile $profile): AuthorityBinding
    {
        /** @var AuthorityBinding $binding */
        $binding = AuthorityBinding::factory()
            ->forPrincipal((int) $user->id, (int) $user->auth_version)
            ->anchor($anchor, $anchorId, $profile)
            ->create(['capability_code' => $capability]);

        return $binding;
    }

    private function authorizer(): CapabilityAuthorizer
    {
        return app(CapabilityAuthorizer::class);
    }

    private function date(): string
    {
        return '2026-11-23';
    }
}
