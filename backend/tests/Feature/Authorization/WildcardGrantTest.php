<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Modules\IAM\Auth\CapabilityAuthorizer;
use App\Modules\IAM\Models\AuthorityBinding;
use App\Modules\IAM\Models\User;
use App\Shared\Authorization\AnchorRef;
use App\Shared\Authorization\AnchorType;
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
        $this->organization->knownLegalEntities = [1];
        $this->grant($user, '*', AnchorType::LEGAL_ENTITY, 1, FieldProfile::P3_RESTRICTED);

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
        $this->organization->knownLegalEntities = [1];
        $this->grant($user, '*', AnchorType::LEGAL_ENTITY, 1, FieldProfile::P3_RESTRICTED);

        $this->assertTrue(app(CapabilityAuthorizer::class)->allows(
            $this->actingAsUser($user),
            'payroll.run.read',
            new AnchorRef(AnchorType::LEGAL_ENTITY, 1),
            '2026-11-24',
        ));
    }

    public function test_constrain_scopes_a_concrete_capability_query_to_the_wildcards_legal_entity(): void
    {
        $this->clock = new FrozenClock('2026-11-24T09:00:00Z');
        $this->app->instance(Clock::class, $this->clock);

        Schema::create('wildcard_scope_rows', function ($table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('employment_id');
        });

        DB::table('wildcard_scope_rows')->insert([
            ['tenant_id' => 1, 'employment_id' => 501],
            ['tenant_id' => 1, 'employment_id' => 502],
            ['tenant_id' => 2, 'employment_id' => 501],
        ]);

        $user = User::factory()->create(['auth_version' => 1]);
        $this->organization->knownLegalEntities = [1];
        $this->organization->unitsByLegalEntity[1] = [12];
        $this->people->employmentByOrgUnit[12] = [501];
        $this->grant($user, '*', AnchorType::LEGAL_ENTITY, 1, FieldProfile::P3_RESTRICTED);

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

        $this->assertSame([501], $query->pluck('employment_id')->all());
    }

    public function test_fields_projects_a_concrete_employee_for_a_wildcard_grant(): void
    {
        $this->clock = new FrozenClock('2026-11-24T09:00:00Z');
        $this->app->instance(Clock::class, $this->clock);

        $user = User::factory()->create(['auth_version' => 1]);
        $this->organization->knownLegalEntities = [1];
        $this->grant($user, '*', AnchorType::LEGAL_ENTITY, 1, FieldProfile::P3_RESTRICTED);

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
                tenantId: 1,
                employmentId: 501,
                orgUnitId: 12,
                legalEntityId: 1,
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
        $this->organization->knownLegalEntities = [1];
        AuthorityBinding::query()->create([
            'tenant_id' => 1,
            'principal_id' => (int) $user->id,
            'capability_code' => '*',
            'anchor_type' => 'LEGAL_ENTITY',
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
            ->forPrincipal((int) $user->tenant_id, (int) $user->id, (int) $user->auth_version)
            ->anchor($anchor, $anchorId, $profile)
            ->create(['capability_code' => $capability]);
    }
}
