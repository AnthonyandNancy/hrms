<?php

declare(strict_types=1);

namespace Tests\Feature\IAM;

use App\Modules\IAM\Models\AuthorityBinding;
use App\Modules\IAM\Models\User;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\FieldProfile;
use Tests\Feature\Authorization\AuthorizationTestSupport;
use Tests\Support\AuthenticatesRequests;

final class PageCatalogApiTest extends AuthorizationTestSupport
{
    use AuthenticatesRequests;

    public function test_catalog_returns_all_160_pages_to_a_role_manager(): void
    {
        [, $headers] = $this->viewer(true);

        $data = $this->getJson('/v1/pages/catalog', $headers)->assertOk()->json('data');

        $this->assertCount(160, $data['pages']);
        $this->assertTrue($data['read_only']);
        $codes = array_column($data['pages'], 'page_code');
        $this->assertContains('hr-employees', $codes);
        $this->assertContains('system-roles', $codes);
    }

    public function test_catalog_is_capability_guarded(): void
    {
        [, $headers] = $this->viewer(false);

        $this->getJson('/v1/pages/catalog', $headers)->assertForbidden();
    }

    /**
     * @return array{0: User, 1: array<string, string>}
     */
    private function viewer(bool $grant): array
    {
        $actor = User::factory()->create(['auth_version' => 1]);
        if ($grant) {
            AuthorityBinding::factory()
                ->forPrincipal(1, (int) $actor->id, 1)
                ->anchor(AnchorType::COMPANY, null, FieldProfile::P1_INTERNAL)
                ->create([
                    'capability_code' => 'iam.role.manage',
                    'valid_from' => '2025-01-01 00:00:00',
                    'valid_to' => '2030-01-01 00:00:00',
                ]);
        }
        $this->people->employmentByUser[(int) $actor->id] = [1];
        $this->organization->knownLegalEntities = [1];
        $this->enableMfa($actor);
        $auth = $this->loginAs($actor);
        $this->stepUp();

        return [$actor, ['X-CSRF-Token' => $auth['csrf']]];
    }
}
