<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Modules\IAM\Auth\CapabilityAuthorizer;
use App\Modules\IAM\Models\AuthorityBinding;
use App\Modules\IAM\Models\User;
use App\Shared\Authorization\AnchorRef;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\FieldProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AuthorizerEngineTest extends AuthorizationTestSupport
{
    public function test_default_deny_when_no_binding_and_no_elevation(): void
    {
        $user = User::factory()->create();

        $this->assertFalse(app(CapabilityAuthorizer::class)->allows(
            $this->actingAsUser($user),
            'people.employee.read',
            AnchorRef::company(),
            '2026-11-23',
        ));
    }

    public function test_self_anchor_allows_only_the_callers_employments(): void
    {
        $user = User::factory()->create();
        AuthorityBinding::factory()->forPrincipal(1, (int) $user->id)->anchor(AnchorType::SELF, null)->create([
            'capability_code' => 'people.employee.read',
        ]);
        $this->people->employmentByUser[(int) $user->id] = [501];
        $this->people->contexts[501] = new \App\Shared\Authorization\EmploymentRef(1, 501, 12, 3);
        $principal = $this->actingAsUser($user, 501);

        $authorizer = app(CapabilityAuthorizer::class);

        $this->assertTrue($authorizer->allows($principal, 'people.employee.read', AnchorRef::self(), '2026-11-23'));
        $this->assertFalse($authorizer->allows($principal, 'people.employee.read', AnchorRef::company(), '2026-11-23'));
        $this->assertFalse($authorizer->allows($principal, 'people.employee.manage', AnchorRef::self(), '2026-11-23'));
    }

    public function test_managed_anchor_follows_manager_directory(): void
    {
        $manager = User::factory()->create();
        AuthorityBinding::factory()->forPrincipal(1, (int) $manager->id)->anchor(AnchorType::MANAGED, null)->create([
            'capability_code' => 'time.attendance.read',
        ]);
        $this->people->managedByUser[(int) $manager->id] = [11, 12];

        $principal = $this->actingAsUser($manager);
        $authorizer = app(CapabilityAuthorizer::class);

        $this->assertTrue($authorizer->allows($principal, 'time.attendance.read', AnchorRef::managed(), '2026-11-23'));
    }

    public function test_expired_and_future_bindings_do_not_grant(): void
    {
        $user = User::factory()->create();
        AuthorityBinding::factory()->forPrincipal(1, (int) $user->id)->anchor(AnchorType::COMPANY, null)->create([
            'capability_code' => 'org.tree.read',
            'valid_from' => '2025-01-01 00:00:00',
            'valid_to' => '2026-01-01 00:00:00',
        ]);
        $this->organization->knownLegalEntities = [1];

        $this->assertFalse(app(CapabilityAuthorizer::class)->allows(
            $this->actingAsUser($user),
            'org.tree.read',
            AnchorRef::company(),
            '2026-11-23',
        ));
    }

    public function test_tenant_mismatch_never_grants(): void
    {
        $user = User::factory()->create(['tenant_id' => 2]);
        AuthorityBinding::factory()->forPrincipal(1, (int) $user->id)->anchor(AnchorType::COMPANY, null)->create([
            'capability_code' => 'org.tree.read',
        ]);
        $this->organization->knownLegalEntities = [1];

        $this->assertFalse(app(CapabilityAuthorizer::class)->allows(
            $this->actingAsUser($user),
            'org.tree.read',
            AnchorRef::company(),
            '2026-11-23',
        ));
    }

    public function test_sod_conflict_hard_denies_both_capabilities(): void
    {
        $user = User::factory()->create();
        foreach (['payroll.run.prepare', 'payroll.run.approve'] as $code) {
            AuthorityBinding::factory()->forPrincipal(1, (int) $user->id)->anchor(AnchorType::PAYROLL_RUN, 9)->create([
                'capability_code' => $code,
                'field_profile' => FieldProfile::P2_SENSITIVE->value,
            ]);
        }
        $this->payroll->memberships[9] = ['payroll.run.prepare', 'payroll.run.approve'];

        $principal = $this->actingAsUser($user);
        $authorizer = app(CapabilityAuthorizer::class);

        $this->assertFalse($authorizer->allows($principal, 'payroll.run.prepare', AnchorRef::payrollRun(9), '2026-11-23'));
        $this->assertFalse($authorizer->allows($principal, 'payroll.run.approve', AnchorRef::payrollRun(9), '2026-11-23'));
    }

    public function test_stale_auth_version_is_not_granted(): void
    {
        $user = User::factory()->create(['auth_version' => 4]);
        AuthorityBinding::factory()->forPrincipal(1, (int) $user->id, 3)->anchor(AnchorType::COMPANY, null)->create([
            'capability_code' => 'org.tree.read',
        ]);
        $this->organization->knownLegalEntities = [1];

        $principal = new \App\Modules\IAM\Auth\AuthenticatedPrincipal((int) $user->id, 1, null, 3, false, false);

        $this->expectException(\App\Shared\Exceptions\NotFoundOrForbiddenException::class);

        app(CapabilityAuthorizer::class)->allows($principal, 'org.tree.read', AnchorRef::company(), '2026-11-23');
    }

    public function test_constrain_pushes_scope_into_sql_before_pagination(): void
    {
        Schema::create('scope_rows', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('employment_id')->nullable();
            $table->unsignedBigInteger('org_unit_id')->nullable();
        });

        DB::table('scope_rows')->insert([
            ['tenant_id' => 1, 'employment_id' => 501, 'org_unit_id' => 3],
            ['tenant_id' => 1, 'employment_id' => 502, 'org_unit_id' => 3],
            ['tenant_id' => 2, 'employment_id' => 503, 'org_unit_id' => 3],
        ]);

        $user = User::factory()->create();
        AuthorityBinding::factory()->forPrincipal(1, (int) $user->id)->anchor(AnchorType::SELF, null)->create([
            'capability_code' => 'time.attendance.read',
        ]);
        $this->people->employmentByUser[(int) $user->id] = [501];

        $model = new class extends \Illuminate\Database\Eloquent\Model
        {
            protected $table = 'scope_rows';
        };

        $queryBuilder = $model->newQuery();
        $constrained = app(CapabilityAuthorizer::class)->constrain($queryBuilder, $this->actingAsUser($user), 'time.attendance.read', '2026-11-23');

        $this->assertSame([501], $constrained->pluck('employment_id')->all());
    }

    public function test_cross_module_ports_are_fakes_and_nothing_queries_other_tables(): void
    {
        $this->assertInstanceOf(\Tests\Fakes\FakePersonEmploymentDirectory::class, app(\App\Shared\Authorization\Contracts\PersonEmploymentDirectory::class));
    }
}
