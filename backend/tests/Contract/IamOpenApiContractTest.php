<?php

declare(strict_types=1);

namespace Tests\Contract;

use Tests\TestCase;

/**
 * Contract check for the IAM fragment. The canonical docs/openapi/openapi.yaml
 * is owned by Task 1B and absent from this branch, so the fragment is validated
 * directly; the merge coordinator moves these paths into the canonical file.
 */
final class IamOpenApiContractTest extends TestCase
{
    public function test_iam_fragment_declares_every_route_with_security(): void
    {
        $path = base_path('../docs/openapi/iam.yaml');
        $this->assertFileExists($path);
        $yaml = (string) file_get_contents($path);

        $this->assertStringContainsString('openapi: 3.1.0', $yaml);

        foreach ([
            '/auth/login',
            '/auth/logout',
            '/auth/logout-all',
            '/auth/password',
            '/me',
            '/me/capabilities',
            '/auth/mfa/totp/enroll',
            '/auth/mfa/totp/confirm',
            '/auth/mfa/step-up',
            '/auth/webauthn/register/options',
            '/auth/webauthn/assert/options',
            '/auth/break-glass',
            '/auth/break-glass/{id}/revoke',
            '/users',
            '/users/{id}',
            '/users/{id}/roles',
            '/users/{id}/bindings',
            '/bindings/{id}',
            '/roles',
            '/capabilities',
            '/auth/events',
        ] as $pathName) {
            $this->assertStringContainsString('  '.$pathName.':', $yaml, "Missing {$pathName}");
        }

        $this->assertStringContainsString('sessionCookie:', $yaml);
        $this->assertStringContainsString('csrfHeader:', $yaml);
        $this->assertStringContainsString('X-CSRF-Token', $yaml);
    }

    public function test_every_iam_route_has_capability_metadata_except_public_allowlist(): void
    {
        $public = ['v1/health', 'v1/health/live', 'v1/health/ready', 'v1/meta', 'v1/auth/login'];

        foreach (app('router')->getRoutes()->getRoutes() as $route) {
            $uri = $route->uri();

            if (! str_starts_with($uri, 'v1/') || in_array($uri, $public, true)) {
                continue;
            }

            $found = false;
            foreach ($route->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && str_starts_with($middleware, 'capability:')) {
                    $found = true;
                    break;
                }
            }

            $this->assertTrue($found, "Missing capability metadata on {$uri}");
        }
    }
}
