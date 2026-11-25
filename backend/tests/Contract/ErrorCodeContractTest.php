<?php

declare(strict_types=1);

namespace Tests\Contract;

use App\Shared\ErrorCode;
use Tests\TestCase;

/**
 * The SPA decodes this finite set of envelope codes. Any backend code outside
 * ErrorCode plus the explicitly documented business conflicts is a contract
 * drift that silently falls into the wrong copy/retry path.
 */
final class ErrorCodeContractTest extends TestCase
{
    public function test_frontend_error_codes_cover_backend_enum_and_business_conflicts(): void
    {
        $frontend = (string) file_get_contents(base_path('../frontend/src/api/contracts.ts'));
        $codes = array_map(static fn (ErrorCode $code): string => $code->value, ErrorCode::cases());
        $codes[] = 'NO_ACTIVE_EMPLOYMENT';

        foreach (array_values(array_unique($codes)) as $code) {
            $this->assertStringContainsString("'{$code}'", $frontend, "Frontend ApiErrorCode is missing {$code}");
        }
    }

    public function test_no_active_employment_is_a_top_level_stable_code(): void
    {
        $renderer = new \App\Shared\Exceptions\ExceptionRenderer;
        $response = $renderer->render(new \App\Shared\Exceptions\NoActiveEmploymentException('SELF'));
        $payload = $response->getData(true);

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('NO_ACTIVE_EMPLOYMENT', $payload['code']);
        $this->assertSame('NO_ACTIVE_EMPLOYMENT', $payload['data']['reason']);
    }

    public function test_every_route_is_capability_guarded_or_public_allowlisted(): void
    {
        $allowed = ['v1/health', 'v1/health/live', 'v1/health/ready', 'v1/meta', 'v1/auth/login', 'v1/auth/mfa/challenge'];
        $violations = [];

        foreach (app('router')->getRoutes()->getRoutes() as $route) {
            $uri = $route->uri();

            if (! str_starts_with($uri, 'v1/') || in_array($uri, $allowed, true)) {
                continue;
            }

            $middleware = implode('|', $route->gatherMiddleware());

            if (! str_contains($middleware, 'capability:')) {
                $violations[] = $uri.' ['.implode(',', $route->methods()).']';
            }
        }

        $this->assertSame([], $violations, 'Routes without capability middleware: '.implode(', ', $violations));
    }
}
