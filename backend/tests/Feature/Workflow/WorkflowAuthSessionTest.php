<?php

declare(strict_types=1);

namespace Tests\Feature\Workflow;

use App\Modules\IAM\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Regression guard for a real authentication hole.
 *
 * The workflow route groups mounted only `WorkflowPrincipalContext`, which
 * populates a request attribute but performs no authentication. The existing
 * workflow tests fake `WorkflowPrincipalResolver`, so they never noticed: every
 * case endpoint returned 401 to a genuinely logged-in user even though
 * `/v1/me` and the rest of the API worked.
 *
 * These tests log in for real and assert the endpoints are reachable with a
 * session and rejected without one.
 */
final class WorkflowAuthSessionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return list<array{0: string}>
     */
    public static function protectedEndpoints(): array
    {
        return [
            ['/v1/cases'],
            ['/v1/workflow/templates'],
            ['/v1/tasks/my'],
        ];
    }

    #[DataProvider('protectedEndpoints')]
    public function test_workflow_endpoints_reject_anonymous_requests(string $uri): void
    {
        $this->getJson($uri)->assertUnauthorized();
    }

    #[DataProvider('protectedEndpoints')]
    public function test_workflow_endpoints_accept_an_authenticated_session(string $uri): void
    {
        $this->login($this->makeUser());

        $status = $this->getJson($uri)->getStatusCode();

        // Reaching authorization means the session middleware ran. A 401 here
        // is the bug this test exists to catch; 403 (missing capability) and
        // 200 (allowed) are both acceptable outcomes of authentication.
        $this->assertNotSame(401, $status, "{$uri} did not authenticate a valid session");
    }

    private function makeUser(): User
    {
        return User::query()->create([
            'username' => 'workflow.auth.'.bin2hex(random_bytes(4)),
            'email' => 'workflow.auth@example.test',
            'password_hash' => password_hash('Correct-Horse-9!', PASSWORD_ARGON2ID),
            'status' => User::STATUS_ACTIVE,
            'is_privileged' => false,
            'auth_version' => 1,
        ]);
    }

    private function login(User $user): void
    {
        $response = $this->postJson('/v1/auth/login', [
            'username' => $user->username,
            'password' => 'Correct-Horse-9!',
        ]);
        $response->assertOk();

        $cookie = $response->getCookie((string) config('iam.session.cookie'), false);
        $this->withCredentials()->withUnencryptedCookie(
            (string) config('iam.session.cookie'),
            (string) $cookie?->getValue(),
        );
        $this->defaultHeaders['X-CSRF-Token'] = (string) $response->json('data.csrf_token');
    }
}
