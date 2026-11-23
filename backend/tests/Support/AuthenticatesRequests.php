<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\IAM\Models\Session;
use App\Modules\IAM\Models\User;

/**
 * Authenticates test requests through the real login endpoint, preserving the
 * session cookie and CSRF token for subsequent JSON calls.
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

        $cookie = $response->getCookie((string) config('iam.session.cookie'), false);

        if ($cookie === null) {
            $this->fail('Login did not issue a session cookie.');
        }

        $this->withCredentials()->withUnencryptedCookie(
            (string) config('iam.session.cookie'),
            (string) $cookie->getValue(),
        );

        $csrf = (string) $response->json('data.csrf_token');
        $this->withHeader('X-CSRF-Token', $csrf);

        return [
            'cookie' => (string) $cookie->getValue(),
            'csrf' => $csrf,
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
