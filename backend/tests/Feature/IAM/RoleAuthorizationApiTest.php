<?php

declare(strict_types=1);

namespace Tests\Feature\IAM;

use App\Modules\IAM\Models\AuthorityBinding;
use App\Modules\IAM\Models\Role;
use App\Modules\IAM\Models\RoleCapability;
use App\Modules\IAM\Models\RolePage;
use App\Modules\IAM\Models\User;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\FieldProfile;
use App\Shared\Time\Clock;
use App\Shared\Time\FrozenClock;
use Tests\Feature\Authorization\AuthorizationTestSupport;
use Tests\Support\AuthenticatesRequests;

final class RoleAuthorizationApiTest extends AuthorizationTestSupport
{
    use AuthenticatesRequests;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(Clock::class, new FrozenClock('2026-11-26T09:00:00Z'));
    }

    public function test_atomic_authorization_replaces_pages_and_capabilities_with_anchor_id(): void
    {
        $this->artisan('iam:sync-capabilities')->assertSuccessful();
        [$headers] = $this->roleManager();
        $headers['Idempotency-Key'] = 'role-authorization-'.substr(hash('sha256', $this->name()), 0, 16);
        $this->customRole('TEMP_ATOMIC');
        RolePage::query()->create(['role_code' => 'TEMP_ATOMIC', 'page_code' => 'hr-employees', 'created_at' => now()]);

        $this->putJson('/v1/roles/TEMP_ATOMIC/authorization', [
            'pages' => ['hr-departments', 'hr-employees', 'system-roles', 'system-pages'],
            'capabilities' => [
                [
                    'capability' => 'people.employee.manage',
                    'anchor_type' => 'COMPANY',
                    'anchor_id' => null,
                    'field_profile' => 'P1_INTERNAL',
                ],
                [
                    'capability' => 'org.unit.manage',
                    'anchor_type' => 'ORG_UNIT',
                    'anchor_id' => 7,
                    'field_profile' => 'P1_INTERNAL',
                ],
            ],
            'reason' => 'Synthetic atomic authorization',
        ], $headers)->assertOk();

        $this->assertSame(
            ['hr-departments', 'hr-employees', 'system-pages', 'system-roles'],
            RolePage::query()->where('role_code', 'TEMP_ATOMIC')->orderBy('page_code')->pluck('page_code')->all(),
        );
        $this->assertDatabaseHas('role_capabilities', [
            'role_code' => 'TEMP_ATOMIC',
            'capability_code' => 'org.unit.manage',
            'anchor_type' => 'ORG_UNIT',
            'anchor_id' => 7,
        ]);
        $this->assertSame(
            ['org.unit.manage', 'people.employee.manage'],
            RoleCapability::query()
                ->where('role_code', 'TEMP_ATOMIC')
                ->orderBy('capability_code')
                ->pluck('capability_code')
                ->all(),
        );
    }

    public function test_org_anchor_without_anchor_id_is_rejected_without_partial_writes(): void
    {
        $this->artisan('iam:sync-capabilities')->assertSuccessful();
        [$headers] = $this->roleManager();
        $headers['Idempotency-Key'] = 'role-authorization-'.substr(hash('sha256', $this->name()), 0, 16);
        $this->customRole('TEMP_ANCHORLESS');
        RolePage::query()->create(['role_code' => 'TEMP_ANCHORLESS', 'page_code' => 'hr-employees', 'created_at' => now()]);

        $this->putJson('/v1/roles/TEMP_ANCHORLESS/authorization', [
            'pages' => ['hr-departments', 'hr-employees', 'system-roles'],
            'capabilities' => [
                [
                    'capability' => 'org.unit.manage',
                    'anchor_type' => 'ORG_UNIT',
                    'field_profile' => 'P1_INTERNAL',
                ],
            ],
            'reason' => 'Synthetic rejected authorization',
        ], $headers)
            ->assertStatus(422)
            ->assertJsonPath('data.reason', 'CAPABILITY_ANCHOR_ID_REQUIRED');

        $this->assertDatabaseHas('role_pages', ['role_code' => 'TEMP_ANCHORLESS', 'page_code' => 'hr-employees']);
        $this->assertDatabaseMissing('role_pages', ['role_code' => 'TEMP_ANCHORLESS', 'page_code' => 'system-roles']);
        $this->assertSame(0, RoleCapability::query()->where('role_code', 'TEMP_ANCHORLESS')->count());
    }

    public function test_action_capability_requires_its_page_and_the_whole_request_rolls_back(): void
    {
        $this->artisan('iam:sync-capabilities')->assertSuccessful();
        [$headers] = $this->roleManager();
        $headers['Idempotency-Key'] = 'role-authorization-'.substr(hash('sha256', $this->name()), 0, 16);
        $this->customRole('TEMP_PAGE_ONLY');
        RolePage::query()->create(['role_code' => 'TEMP_PAGE_ONLY', 'page_code' => 'hr-employees', 'created_at' => now()]);

        $this->putJson('/v1/roles/TEMP_PAGE_ONLY/authorization', [
            'pages' => ['system-roles'],
            'capabilities' => [
                [
                    'capability' => 'people.employee.manage',
                    'anchor_type' => 'COMPANY',
                    'anchor_id' => null,
                    'field_profile' => 'P1_INTERNAL',
                    'page_code' => 'hr-employees',
                ],
            ],
            'reason' => 'Synthetic missing action page',
        ], $headers)
            ->assertStatus(422)
            ->assertJsonPath('data.reason', 'PAGE_REQUIRED_FOR_ACTION');

        $this->assertSame(['hr-employees'], RolePage::query()
            ->where('role_code', 'TEMP_PAGE_ONLY')->pluck('page_code')->all());
        $this->assertSame(0, RoleCapability::query()->where('role_code', 'TEMP_PAGE_ONLY')->count());
    }

    public function test_template_role_authorization_is_read_only_but_copy_keeps_actions_with_empty_pages(): void
    {
        $this->artisan('iam:sync-capabilities')->assertSuccessful();
        [$headers] = $this->roleManager();
        $headers['Idempotency-Key'] = 'role-authorization-'.substr(hash('sha256', $this->name()), 0, 16);

        $this->putJson('/v1/roles/HR_ADMIN/authorization', [
            'pages' => ['system-roles'],
            'capabilities' => [],
            'reason' => 'Synthetic template mutation',
        ], $headers)
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'TEMPLATE_ROLE_IMMUTABLE');

        $sourceCapabilities = RoleCapability::query()
            ->where('role_code', 'HR_ADMIN')
            ->orderBy('capability_code')
            ->pluck('capability_code')
            ->all();

        $this->postJson('/v1/roles/HR_ADMIN/copy', [
            'code' => 'TEMP_HR_ADMIN_COPY',
            'name' => '人力资源管理员副本',
            'purpose' => 'Synthetic copied role for authorization tests.',
            'owner' => 1,
        ], [...$headers, 'Idempotency-Key' => 'role-copy-'.substr(hash('sha256', $this->name()), 0, 16)])->assertCreated();

        $this->assertSame([], RolePage::query()->where('role_code', 'TEMP_HR_ADMIN_COPY')->pluck('page_code')->all());
        $this->assertSame(
            $sourceCapabilities,
            RoleCapability::query()->where('role_code', 'TEMP_HR_ADMIN_COPY')->orderBy('capability_code')->pluck('capability_code')->all(),
        );
    }

    /**
     * @return array{0: array<string, string>}
     */
    private function roleManager(): array
    {
        $actor = User::factory()->create(['auth_version' => 1]);
        $this->people->employmentByUser[(int) $actor->id] = [1];
        foreach ([
            'iam.role.manage',
            'iam.permission.read',
            'org.unit.manage',
            'people.employee.manage',
        ] as $capability) {
            AuthorityBinding::factory()
                ->forPrincipal((int) $actor->id, 1)
                ->anchor(AnchorType::COMPANY, null, FieldProfile::P2_SENSITIVE)
                ->create([
                    'capability_code' => $capability,
                    'valid_from' => '2025-01-01 00:00:00',
                    'valid_to' => '2030-01-01 00:00:00',
                ]);
        }
        $this->enableMfa($actor);
        $auth = $this->loginAs($actor);
        $this->stepUp();

        return [['X-CSRF-Token' => $auth['csrf']]];
    }

    private function customRole(string $code): Role
    {
        return Role::query()->create([
            'code' => $code,
            'name' => '合成授权测试角色',
            'is_template' => false,
            'owner' => 1,
            'purpose' => 'Synthetic role for atomic authorization tests.',
            'expires_at' => '2027-12-31 00:00:00',
        ]);
    }
}
