<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Modules\IAM\Models\AuthorityBinding;
use App\Modules\IAM\Models\User;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\FieldProfile;
use Tests\Support\PeopleTestCase;

final class MenuOverrideTest extends PeopleTestCase
{
    public function test_menu_override_cannot_reference_unknown_page(): void
    {
        $this->postJson('/v1/system/menus', [
            'page_code' => 'not-a-registered-page',
            'visible' => false,
        ], ['Idempotency-Key' => 'menu-unknown'])
            ->assertUnprocessable()
            ->assertJsonPath('data.reason', 'UNKNOWN_PAGE_CODE');

        $this->assertDatabaseCount('menu_overrides', 0);
    }

    public function test_menu_override_hides_presentation_but_capability_still_allows_the_api_route(): void
    {
        $user = User::factory()->create(['auth_version' => 1]);
        $this->actAs(userId: (int) $user->id, employmentId: 1);
        AuthorityBinding::factory()
            ->forPrincipal((int) $user->id, 1)
            ->anchor(AnchorType::COMPANY, null, FieldProfile::P1_INTERNAL)
            ->create([
                'capability_code' => 'people.employee.read',
                'valid_from' => '2025-01-01 00:00:00',
                'valid_to' => '2030-01-01 00:00:00',
            ]);

        $this->postJson('/v1/system/menus', [
            'page_code' => 'hr-employees',
            'visible' => false,
            'sort_order' => 10,
            'label' => 'Hidden employees',
        ], ['Idempotency-Key' => 'menu-hide'])->assertCreated();

        $permissions = $this->getJson('/v1/me/permissions')->assertOk()->json('data');
        $paths = array_column($permissions['pages'], 'path');

        $this->assertNotContains('/hr/employees', $paths, 'A menu override must hide the presentation row.');
        $this->assertContains('people.employee.read', $permissions['actions']);

        // Presentation is not an authorization decision: the API route still
        // requires and accepts the unchanged code-owned capability.
        $this->getJson('/v1/employees')->assertOk();
    }

    public function test_menu_override_list_and_update_are_projection_contracts(): void
    {
        $created = $this->postJson('/v1/system/menus', [
            'page_code' => 'system-roles',
            'visible' => false,
            'sort_order' => 7,
            'label' => 'Roles (hidden)',
        ], ['Idempotency-Key' => 'menu-create'])->assertCreated();

        $pageCode = (string) $created->json('data.page_code');

        $this->getJson('/v1/system/menus', ['page' => 1, 'page_size' => 100])
            ->assertOk()
            ->assertJsonStructure(['data' => ['items', 'page', 'page_size', 'total', 'as_of', 'freshness', 'source_hash']])
            ->assertJsonFragment(['page_code' => $pageCode, 'visible' => false, 'label' => 'Roles (hidden)']);

        $this->patchJson('/v1/system/menus/'.$pageCode, [
            'visible' => true,
        ], ['Idempotency-Key' => 'menu-update'])
            ->assertOk()
            ->assertJsonPath('data.visible', true);

        $this->deleteJson('/v1/system/menus/'.$pageCode, [], ['Idempotency-Key' => 'menu-delete'])
            ->assertOk()
            ->assertJsonPath('data.deleted', true);

        $this->assertDatabaseMissing('menu_overrides', ['page_code' => $pageCode]);
    }
}
