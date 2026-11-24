<?php

declare(strict_types=1);

namespace Tests\Feature\IAM;

use App\Modules\IAM\Models\AuthorityBinding;
use App\Modules\IAM\Models\User;
use App\Shared\Authorization\AnchorType;
use App\Shared\Authorization\FieldProfile;
use Tests\Feature\Authorization\AuthorizationTestSupport;

final class AccessReviewEndpointTest extends AuthorizationTestSupport
{
    public function test_it_returns_unused_high_risk_bindings_and_sod_risks(): void
    {
        $this->systemAdmin();

        $target = User::factory()->create(['auth_version' => 1]);
        $this->grant($target, 'iam.binding.manage', 'P1_INTERNAL');
        $this->grant($target, 'iam.audit.read', 'P2_SENSITIVE');
        $this->grant($target, '*', 'P3_RESTRICTED');

        $response = $this->getJson('/v1/access-review', ['X-Legal-Entity-Id' => '1', 'X-CSRF-Token' => 'test-session'])
            ->assertOk()
            ->assertJsonStructure(['data' => ['generated_at', 'unused_high_risk_bindings', 'sod_risks']]);

        $risks = $response->json('data.sod_risks');
        $this->assertNotEmpty($risks);
        $kinds = array_column($risks, 'kind');
        $this->assertContains('CUMULATIVE', $kinds);
        $this->assertContains('OBJECT_LEVEL', $kinds);
    }

    public function test_a_caller_without_audit_rights_is_denied(): void
    {
        $user = User::factory()->create(['auth_version' => 1]);
        $this->actingAsUser($user, 1, true, 'test-session');
        $this->app->instance(\App\Modules\IAM\Contracts\Principal::class, $this->app->make(\App\Modules\IAM\Auth\AuthenticationContext::class)->requirePrincipal());
        $this->grant($user, 'iam.user.read', 'P1_INTERNAL');

        $this->getJson('/v1/access-review', ['X-Legal-Entity-Id' => '1', 'X-CSRF-Token' => 'test-session'])
            ->assertForbidden();
    }

    private function grant(User $user, string $capability, string $profile): void
    {
        AuthorityBinding::factory()
            ->forPrincipal(1, (int) $user->id, (int) $user->auth_version)
            ->anchor(AnchorType::LEGAL_ENTITY, 1, FieldProfile::from($profile))
            ->create([
                'capability_code' => $capability,
                'valid_from' => '2025-01-01 00:00:00',
                'valid_to' => '2030-01-01 00:00:00',
            ]);
    }
}
