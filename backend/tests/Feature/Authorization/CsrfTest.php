<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Modules\IAM\Models\User;
use Tests\Support\AuthenticatesRequests;

final class CsrfTest extends AuthorizationTestSupport
{
    use AuthenticatesRequests;

    public function test_missing_csrf_header_on_mutation_returns_419_envelope(): void
    {
        [$user, $auth] = $this->userWithSessionAnchor();

        $response = $this->withHeader('X-CSRF-Token', '')->postJson('/v1/auth/logout-all');

        $response->assertStatus(419)
            ->assertJsonPath('code', 'CSRF_FAILED')
            ->assertJsonStructure(['code', 'message', 'data', 'request_id']);
    }

    public function test_invalid_csrf_header_on_mutation_returns_419(): void
    {
        [$user] = $this->userWithSessionAnchor();

        $response = $this->withHeader('X-CSRF-Token', 'not-the-right-token')->postJson('/v1/auth/logout-all');

        $response->assertStatus(419)->assertJsonPath('data.reason', 'CSRF_FAILED');
    }

    public function test_get_request_is_not_subject_to_csrf(): void
    {
        [$user] = $this->userWithSessionAnchor();

        $this->withHeader('X-CSRF-Token', '')->getJson('/v1/me')->assertOk();
    }

    /**
     * @return array{0: User, 1: array<string, mixed>}
     */
    private function userWithSessionAnchor(): array
    {
        $user = User::factory()->create();
        $this->people->employmentByUser[(int) $user->id] = [1];
        $auth = $this->loginAs($user);

        $this->withHeader('X-CSRF-Token', $auth['csrf']);

        return [$user, $auth];
    }
}
