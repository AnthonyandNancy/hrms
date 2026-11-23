<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The Vue SPA is served from a different origin in local and container
 * deployments. Browser credential mode requires an explicit origin (never `*`)
 * plus `Access-Control-Allow-Credentials`.
 */
class CorsPreflightTest extends TestCase
{
    public function test_preflight_from_configured_origin_allows_credentials_and_csrf_header(): void
    {
        config(['cors.allowed_origins' => ['https://hrms.example.test', 'https://hrms-admin.example.test']]);

        $response = $this->call('OPTIONS', '/v1/auth/login', [], [], [], [
            'HTTP_ORIGIN' => 'https://hrms.example.test',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type,x-csrf-token',
        ]);

        $response->assertNoContent();
        $response->assertHeader('Access-Control-Allow-Origin', 'https://hrms.example.test');
        $response->assertHeader('Access-Control-Allow-Credentials', 'true');
        $this->assertStringContainsStringIgnoringCase(
            'x-csrf-token',
            (string) $response->headers->get('Access-Control-Allow-Headers'),
        );
    }

    public function test_preflight_from_unlisted_origin_is_not_allowed(): void
    {
        config(['cors.allowed_origins' => ['https://hrms.example.test', 'https://hrms-admin.example.test']]);

        $response = $this->call('OPTIONS', '/v1/auth/login', [], [], [], [
            'HTTP_ORIGIN' => 'https://evil.example.test',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);

        $response->assertNoContent();
        $response->assertHeaderMissing('Access-Control-Allow-Origin');
    }
}
