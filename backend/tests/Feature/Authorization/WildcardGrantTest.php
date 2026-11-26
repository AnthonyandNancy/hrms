<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Modules\IAM\Auth\CapabilityAuthorizer;
use App\Modules\IAM\Models\AuthorityBinding;
use App\Modules\IAM\Models\User;
use App\Shared\Authorization\AnchorRef;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\Authorizer;
use App\Shared\Authorization\CapabilityWildcard;
use App\Shared\Authorization\FieldProfile;
use App\Shared\Authorization\FieldSet;
use App\Shared\Authorization\ResourceRef;
use App\Shared\Time\Clock;
use App\Shared\Time\FrozenClock;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class WildcardGrantTest extends AuthorizationTestSupport
{
    public function test_a_wildcard_binding_permits_every_catalogued_capability(): void
    {
        $user = User::factory()->create(['auth_version' => 1]);
        $this->grant($user, '*', AnchorType::COMPANY, null, FieldProfile::P3_RESTRICTED);

        $authorizer = app(CapabilityAuthorizer::class);
        $principal = $this->actingAsUser($user);
        $date = '2026-11-24';

        foreach (['iam.user.read', 'payroll.run.approve', 'workflow.case.admin', 'talent.training.manage'] as $code) {
            $this->assertTrue(
                $authorizer->permits($principal, $code, $date),
                "A wildcard binding must permit {$code}.",
            );
        }
    }

    public function test_a_wildcard_binding_covers_an_anchored_resource(): void
    {
        $user = User::factory()->create(['auth_version' => 1]);
        $this->grant($user, '*', AnchorType::COMPANY, null, FieldProfile::P3_RESTRICTED);

        $this->assertTrue(app(CapabilityAuthorizer::class)->allows(
            $this->actingAsUser($user),
            'payroll.run.read',
            new AnchorRef(AnchorType::COMPANY, null),
            '2026-11-24',
        ));
    }

    public function test_constrain_scopes_a_company_wildcard_to_all_rows_of_the_single_company(): void
    {
        $this->clock = new FrozenClock('2026-11-24T09:00:00Z');
        $this->app->instance(Clock::class, $this->clock);

        Schema::create('wildcard_scope_rows', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('employment_id');
        });

        DB::table('wildcard_scope_rows')->insert([
            ['employment_id' => 501],
            ['employment_id' => 502],
            ['employment_id' => 503],
        ]);

        $user = User::factory()->create(['auth_version' => 1]);
        $this->people->employmentByOrgUnit[12] = [501];
        $this->grant($user, '*', AnchorType::COMPANY, null, FieldProfile::P3_RESTRICTED);

        $model = new class extends Model
        {
            protected $table = 'wildcard_scope_rows';
        };

        $query = app(CapabilityAuthorizer::class)->constrain(
            $model->newQuery(),
            $this->actingAsUser($user),
            'payroll.run.read',
            '2026-11-24',
        );

        $this->assertSame([501, 502, 503], $query->pluck('employment_id')->all());
    }

    public function test_fields_projects_a_concrete_employee_for_a_wildcard_grant(): void
    {
        $this->clock = new FrozenClock('2026-11-24T09:00:00Z');
        $this->app->instance(Clock::class, $this->clock);

        $user = User::factory()->create(['auth_version' => 1]);
        $this->grant($user, '*', AnchorType::COMPANY, null, FieldProfile::P3_RESTRICTED);

        $set = app(CapabilityAuthorizer::class)->fields(
            $this->actingAsUser($user),
            'people.employee.read',
            new ResourceRef(
                resourceType: 'employee',
                id: 17,
                row: [
                    'display_name' => 'Ada Lovelace',
                    'salary' => '999999',
                ],
                employmentId: 501,
                orgUnitId: 12,
            ),
        );

        $this->assertInstanceOf(FieldSet::class, $set);
        $this->assertTrue($set->isVisible('display_name'));
        $this->assertTrue($set->isVisible('salary'), 'A wildcard grant carries P3 and may reveal restricted values.');
        $this->assertFalse($set->isExportable('salary'));
    }

    public function test_an_expired_wildcard_binding_permits_nothing(): void
    {
        $user = User::factory()->create(['auth_version' => 1]);
        AuthorityBinding::query()->create([
            'principal_id' => (int) $user->id,
            'capability_code' => '*',
            'anchor_type' => 'COMPANY',
            'anchor_id' => 1,
            'anchor_key' => 1,
            'field_profile' => 'P3_RESTRICTED',
            'purpose_code' => 'SYSTEM_ADMIN',
            'valid_from' => '2020-01-01 00:00:00',
            'valid_to' => '2021-01-01 00:00:00',
            'source_type' => 'ROLE',
            'auth_version' => 1,
            'created_at' => '2020-01-01 00:00:00',
        ]);

        $this->assertFalse(app(CapabilityAuthorizer::class)->permits(
            $this->actingAsUser($user),
            'iam.user.read',
            '2026-11-24',
        ));
    }

    private function grant(User $user, string $capability, AnchorType $anchor, ?int $anchorId, FieldProfile $profile): void
    {
        AuthorityBinding::factory()
            ->forPrincipal((int) $user->id, (int) $user->auth_version)
            ->anchor($anchor, $anchorId, $profile)
            ->create(['capability_code' => $capability]);
    }

    public function test_a_company_wildcard_covers_company_rows_but_not_unrelated_employment_rows(): void
    {
        $user = User::factory()->create(['auth_version' => 1]);
        $this->grant($user, CapabilityWildcard::WILDCARD, AnchorType::COMPANY, null, FieldProfile::P3_RESTRICTED);

        $principal = $this->actingAsUser($user);
        $authorizer = app(Authorizer::class);
        $date = '2026-11-24';

        $this->assertTrue($authorizer->allows($principal, 'payroll.run.read', new AnchorRef(AnchorType::COMPANY, null), $date));

        $sameCompany = new ResourceRef(
            resourceType: 'employee',
            id: 17,
            row: ['display_name' => 'Ada', 'salary' => '1'],
            employmentId: 501,
        );
        $unrelatedEmployment = new ResourceRef(
            resourceType: 'employee',
            id: 18,
            row: ['display_name' => 'Grace', 'salary' => '1'],
            employmentId: 502,
        );

        $this->assertTrue($authorizer->fields($principal, 'people.employee.read', $sameCompany)->isVisible('display_name'));
        // COMPANY is the whole single company: every row is in scope, so a
        // fabricated different-employee ResourceRef cannot narrow the grant.
        $this->assertTrue($authorizer->fields($principal, 'people.employee.read', $unrelatedEmployment)->isVisible('display_name'));
    }
}
