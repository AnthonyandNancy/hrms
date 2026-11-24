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

    public function test_iam_grant_endpoints_document_sod_acknowledgement(): void
    {
        $yaml = str_replace("\r\n", "\n", (string) file_get_contents(base_path('../docs/openapi/iam.yaml')));

        foreach ([
            'assignRole' => 'SodRiskRoleAssignmentEnvelope',
            'createUserBinding' => 'SodRiskBindingEnvelope',
        ] as $operationId => $successSchema) {
            $operation = $this->operationBlock($yaml, $operationId);

            $this->assertStringContainsString('acknowledge_sod_risk:', $operation, "{$operationId} must accept acknowledge_sod_risk.");
            $this->assertMatchesRegularExpression(
                '/acknowledge_sod_risk:\s*\n\s+type: boolean/',
                $operation,
                "{$operationId} must document acknowledge_sod_risk as a boolean.",
            );
            $this->assertStringContainsString($successSchema, $operation, "{$operationId} must document the success warnings shape.");
            $this->assertStringContainsString('SodRiskNotAcknowledged', $operation, "{$operationId} must document the 422 SOD reason.");
            $this->assertStringNotContainsString("'409'", $operation, "{$operationId} must not claim a hard-blocking SOD 409.");
        }

        $this->assertStringContainsString('SodRiskWarning:', $yaml);
        $this->assertStringContainsString('SOD_RISK_NOT_ACKNOWLEDGED', $yaml);
        $this->assertStringContainsString('enum: [CUMULATIVE, OBJECT_LEVEL]', $yaml);
    }

    private function operationBlock(string $yaml, string $operationId): string
    {
        $start = strpos($yaml, 'operationId: '.$operationId);
        $this->assertNotFalse($start, "Missing operation {$operationId}");

        $end = strpos($yaml, "\n  /", $start);
        $this->assertNotFalse($end, "Could not isolate operation {$operationId}");

        return substr($yaml, $start, $end - $start);
    }

    public function test_every_iam_route_has_capability_metadata_except_public_allowlist(): void
    {
        $public = ['v1/health', 'v1/health/live', 'v1/health/ready', 'v1/meta', 'v1/auth/login', 'v1/auth/mfa/challenge'];

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
