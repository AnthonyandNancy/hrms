<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\IAM\Models\Session;
use App\Modules\IAM\Models\User;
use App\Modules\IAM\Services\SessionManager;

/**
 * Authenticates a test request through the real login endpoint and returns
 * the session cookie value plus CSRF token for subsequent calls.
 */
trait AuthenticatesRequests
{
    /**
     * @return array{cookie: string, csrf: string, session: Session}
     */
    protected function loginAs(User $user, string $password = 'Correct-Horse-9!'): array
    {
        $response = $this->postJson('/v1/auth/login', [
            'username' => $user->username,
            'password' => $password,
        ]);

        $response->assertOk();

        $cookie = $response->getCookie(config('iam.session_cookie'));

        if ($cookie === null) {
            $this->fail('Login did not issue a session cookie.');
        }

        return [
            'cookie' => (string) $cookie->getValue(),
            'csrf' => (string) $response->json('data.csrf_token'),
            'session' => Session::query()->latest('created_at')->firstOrFail(),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function authHeaders(string $csrf): array
    {
        return ['X-CSRF-Token' => $csrf];
    }
}
