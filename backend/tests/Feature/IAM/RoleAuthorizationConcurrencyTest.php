<?php

declare(strict_types=1);

namespace Tests\Feature\IAM;

use App\Modules\IAM\Authorization\PageActionCatalog;
use App\Modules\IAM\Models\AuthorityBinding;
use App\Modules\IAM\Models\Role;
use App\Modules\IAM\Models\RoleCapability;
use App\Modules\IAM\Models\RolePage;
use App\Modules\IAM\Models\User;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\CapabilityWildcard;
use App\Shared\Authorization\FieldProfile;
use Tests\Feature\Authorization\AuthorizationTestSupport;
use Tests\Support\AuthenticatesRequests;

final class RoleAuthorizationConcurrencyTest extends AuthorizationTestSupport
{
    use AuthenticatesRequests;

    public function test_stale_expected_version_is_rejected_and_writes_are_locked(): void
    {
        $headers = $this->wildcardRoleManagerHeaders();
        $this->customRole('TEMP_VERSIONED');

        $first = $this->putJson('/v1/roles/TEMP_VERSIONED/authorization', [
            'pages' => ['workspace'],
            'capabilities' => [],
            'expected_version' => 1,
            'reason' => 'First versioned authorization save.',
        ], [...$headers, 'Idempotency-Key' => 'role-version-first']);

        $first->assertOk()->assertJsonPath('data.role.version', 2);
        $this->assertDatabaseHas('roles', ['code' => 'TEMP_VERSIONED', 'version' => 2]);

        $this->putJson('/v1/roles/TEMP_VERSIONED/authorization', [
            'pages' => ['account'],
            'capabilities' => [],
            'expected_version' => 1,
            'reason' => 'Stale tab must not overwrite the first save.',
        ], [...$headers, 'Idempotency-Key' => 'role-version-stale'])
            ->assertStatus(409)
            ->assertJsonPath('data.reason', 'ROLE_AUTHORIZATION_VERSION_CONFLICT');

        $this->assertSame(['workspace'], RolePage::query()
            ->where('role_code', 'TEMP_VERSIONED')
            ->pluck('page_code')
            ->all());
        $this->assertDatabaseHas('roles', ['code' => 'TEMP_VERSIONED', 'version' => 2]);
    }

    public function test_atomic_role_authorization_accepts_more_than_the_former_64_grant_limit(): void
    {
        $headers = $this->wildcardRoleManagerHeaders();
        $this->customRole('TEMP_LARGE_AUTHORIZATION');

        /** @var PageActionCatalog $catalog */
        $catalog = app(PageActionCatalog::class);
        $pages = array_keys($catalog->all());
        $grants = [];
        $seen = [];

        foreach ($catalog->all() as $actions) {
            foreach ($actions as $action) {
                foreach ((array) $action['anchorTypes'] as $anchorValue) {
                    $anchor = AnchorType::from((string) $anchorValue);
                    $key = $action['capability'].'|'.$anchor->value;

                    if (isset($seen[$key])) {
                        continue;
                    }

                    $seen[$key] = true;
                    $grants[] = [
                        'capability' => (string) $action['capability'],
                        'anchor_type' => $anchor->value,
                        'field_profile' => (string) $action['fieldProfileMax'],
                        ...($anchor->requiresAnchorId() ? ['anchor_id' => 1] : []),
                    ];

                    if (count($grants) === 65) {
                        break 3;
                    }
                }
            }
        }

        $this->assertCount(65, $grants);

        $this->putJson('/v1/roles/TEMP_LARGE_AUTHORIZATION/authorization', [
            'pages' => $pages,
            'capabilities' => $grants,
            'expected_version' => 1,
            'reason' => 'Large atomic action matrix.',
        ], [...$headers, 'Idempotency-Key' => 'role-large-authorization'])
            ->assertOk()
            ->assertJsonPath('data.role.version', 2);

        $this->assertSame(65, RoleCapability::query()
            ->where('role_code', 'TEMP_LARGE_AUTHORIZATION')
            ->count());
    }

    /**
     * @return array<string, string>
     */
    private function wildcardRoleManagerHeaders(): array
    {
        $actor = User::factory()->create(['auth_version' => 1]);
        $this->people->employmentByUser[(int) $actor->id] = [1];

        AuthorityBinding::factory()
            ->forPrincipal((int) $actor->id, (int) $actor->auth_version)
            ->anchor(AnchorType::COMPANY, null, FieldProfile::P3_RESTRICTED)
            ->create([
                'capability_code' => CapabilityWildcard::WILDCARD,
                'valid_from' => '2025-01-01 00:00:00',
                'valid_to' => '2030-01-01 00:00:00',
            ]);

        $this->enableMfa($actor);
        $auth = $this->loginAs($actor);
        $this->stepUp();

        return ['X-CSRF-Token' => $auth['csrf']];
    }

    private function customRole(string $code): Role
    {
        return Role::query()->create([
            'code' => $code,
            'name' => '合成并发授权角色',
            'is_template' => false,
            'version' => 1,
            'owner' => 1,
            'purpose' => 'Synthetic role for authorization concurrency and size tests.',
            'expires_at' => '2027-12-31 00:00:00',
        ]);
    }
}
