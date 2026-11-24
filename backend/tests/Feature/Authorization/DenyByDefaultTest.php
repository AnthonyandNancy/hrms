<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Modules\IAM\Models\User;

class DenyByDefaultTest extends AuthorizationTestSupport
{
    public function test_unauthenticated_request_gets_401_envelope(): void
    {
        $response = $this->getJson('/v1/me');

        $response->assertUnauthorized()
            ->assertJsonPath('code', 'UNAUTHENTICATED')
            ->assertJsonStructure(['code', 'message', 'data', 'request_id'])
            ->assertJsonPath('data.reason', 'UNAUTHENTICATED');
    }

    public function test_authenticated_user_without_capability_gets_403(): void
    {
        $user = User::factory()->create();
        $this->login($user);

        $response = $this->getJson('/v1/users');
        $response->assertForbidden()
            ->assertJsonPath('code', 'FORBIDDEN')
            ->assertJsonPath('data.reason', 'CAPABILITY_DENIED')
            ->assertJsonPath('data.capability', 'iam.user.read');
    }

    public function test_capability_catalog_has_no_editable_surface(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('capabilities.store'));
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('capabilities.update'));
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('capabilities.destroy'));
    }

    public function test_all_protected_routes_carry_capability_metadata(): void
    {
        $public = ['v1/health', 'v1/health/live', 'v1/health/ready', 'v1/meta', 'v1/auth/login', 'v1/auth/mfa/challenge'];

        foreach (app('router')->getRoutes()->getRoutes() as $route) {
            $uri = $route->uri();
            if (! str_starts_with($uri, 'v1/') || in_array($uri, $public, true)) {
                continue;
            }

            $hasCapability = false;
            foreach ($route->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && str_starts_with($middleware, 'capability:')) {
                    $hasCapability = true;
                    break;
                }
            }

            $this->assertTrue($hasCapability, "Route {$route->methods()[0]} {$uri} lacks capability metadata.");
        }
    }

    private function login(User $user): void
    {
        $response = $this->postJson('/v1/auth/login', [
            'username' => $user->username,
            'password' => 'Correct-Horse-9!',
        ]);
        $response->assertOk();

        $cookie = $response->getCookie((string) config('iam.session.cookie'), false);
        $this->withCredentials()->withUnencryptedCookie((string) config('iam.session.cookie'), (string) $cookie?->getValue());
        $this->defaultHeaders['X-CSRF-Token'] = (string) $response->json('data.csrf_token');
    }
}
