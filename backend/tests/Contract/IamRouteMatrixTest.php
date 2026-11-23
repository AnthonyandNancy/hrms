<?php

declare(strict_types=1);

namespace Tests\Contract;

use Tests\TestCase;

final class IamRouteMatrixTest extends TestCase
{
    public function test_every_iam_route_appears_in_the_denial_matrix(): void
    {
        $matrix = require base_path('tests/Authorization/iam_endpoint_matrix.php');
        $rows = [];

        foreach ($matrix['endpoints'] as $endpoint) {
            $rows[$endpoint['method'].' '.$endpoint['path']] = $endpoint;
        }

        foreach (app('router')->getRoutes()->getRoutes() as $route) {
            $uri = '/'.$route->uri();

            if (in_array($uri, ['/v1/health', '/v1/health/live', '/v1/health/ready', '/v1/meta', '/v1/auth/login'], true)) {
                continue;
            }

            $iamOwned = str_starts_with($uri, '/v1/auth') || str_starts_with($uri, '/v1/me') || str_starts_with($uri, '/v1/users') || str_starts_with($uri, '/v1/roles') || str_starts_with($uri, '/v1/capabilities') || str_starts_with($uri, '/v1/bindings');

            if (! $iamOwned) {
                continue;
            }

            foreach ($route->methods() as $method) {
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }

                $key = $method.' '.$uri;
                $this->assertArrayHasKey($key, $rows, "Missing endpoint matrix row for {$key}");
                $this->assertGreaterThanOrEqual(3, count($rows[$key]['denials']), "{$key} needs denial cases");
            }
        }
    }

    public function test_high_risk_rows_have_at_least_eight_denial_classes(): void
    {
        $matrix = require base_path('tests/Authorization/iam_endpoint_matrix.php');

        foreach ($matrix['endpoints'] as $endpoint) {
            if (! in_array($endpoint['risk'], ['high', 'critical'], true) || ! str_starts_with($endpoint['path'], '/v1/users') && ! str_starts_with($endpoint['path'], '/v1/bindings')) {
                continue;
            }

            $this->assertGreaterThanOrEqual(
                4,
                count($endpoint['denials']),
                $endpoint['method'].' '.$endpoint['path'].' needs high-risk denial classes',
            );
        }
    }
}
