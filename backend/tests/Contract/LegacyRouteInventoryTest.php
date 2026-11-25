<?php

declare(strict_types=1);

namespace Tests\Contract;

use Illuminate\Routing\Route as LaravelRoute;
use Tests\TestCase;

/**
 * Guards the legacy route ledger.
 *
 * The ledger is the only place where "this endpoint is not on the new kernel
 * yet" may be recorded. Without this test a new controller could quietly ship a
 * fourth response shape and never appear in the migration plan.
 */
final class LegacyRouteInventoryTest extends TestCase
{
    private const ALLOWED_MIGRATION_TASKS = ['B1', 'B2', 'B3', 'B4', 'B5', 'C1', 'C2', 'C3', 'C4', 'D1', 'D2', 'D3', 'D4', 'E3'];

    /**
     * @return array<string, array{kind: string, task: string}>
     */
    private function ledger(): array
    {
        $path = base_path('../docs/audit/2026-11-25-v11-legacy-routes.md');
        $this->assertFileExists($path);

        $inventory = (string) file_get_contents($path);
        $rows = [];

        foreach (preg_split('/\R/', $inventory) ?: [] as $line) {
            if (preg_match('/^\|\s*\d+\s*\|\s*`([A-Z\/]+)`\s*\|\s*`([^`]+)`\s*\|\s*`?([^`|]*)`?\s*\|\s*`?([^`|]*)`?\s*\|\s*(kernel|legacy)\s*\|\s*([^|]+?)\s*\|$/u', $line, $matches) !== 1) {
                continue;
            }

            $uriKey = $matches[2];

            foreach (explode('/', $matches[1]) as $method) {
                $rows["{$method} {$uriKey}"] = [
                    'kind' => $matches[5],
                    'task' => trim($matches[6]),
                ];
            }
        }

        return $rows;
    }

    /**
     * @return list<LaravelRoute>
     */
    private function v1Routes(): array
    {
        $routes = [];

        /** @var iterable<LaravelRoute> $registered */
        $registered = app('router')->getRoutes()->getRoutes();

        foreach ($registered as $route) {
            if (! $route instanceof LaravelRoute) {
                continue;
            }

            if (! str_starts_with($route->uri(), 'v1/')) {
                continue;
            }

            $routes[] = $route;
        }

        return $routes;
    }

    public function test_every_route_is_classified_as_kernel_or_legacy(): void
    {
        $ledger = $this->ledger();
        $this->assertNotEmpty($ledger, 'The legacy route ledger has no parsable rows.');

        $missing = [];

        foreach ($this->v1Routes() as $route) {
            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') {
                    continue;
                }

                $key = "{$method} /{$route->uri()}";

                if (! array_key_exists($key, $ledger)) {
                    $missing[] = $key;
                }
            }
        }

        $this->assertSame([], $missing, 'Routes missing from the legacy ledger: '.implode(', ', $missing));
    }

    public function test_every_legacy_route_names_a_real_migration_task(): void
    {
        $invalid = [];

        foreach ($this->ledger() as $route => $entry) {
            if ($entry['kind'] !== 'legacy') {
                continue;
            }

            if (! in_array($entry['task'], self::ALLOWED_MIGRATION_TASKS, true)) {
                $invalid[$route] = $entry['task'];
            }
        }

        $this->assertSame([], $invalid, 'Legacy routes without a valid migration task: '.json_encode($invalid, JSON_THROW_ON_ERROR));
    }

    public function test_every_kernel_route_does_not_claim_a_migration_task(): void
    {
        $invalid = [];

        foreach ($this->ledger() as $route => $entry) {
            if ($entry['kind'] === 'kernel' && $entry['task'] !== '—') {
                $invalid[$route] = $entry['task'];
            }
        }

        $this->assertSame([], $invalid, 'Kernel routes must not carry a migration task: '.json_encode($invalid, JSON_THROW_ON_ERROR));
    }

    public function test_the_ledger_does_not_invent_routes_that_do_not_exist(): void
    {
        $registered = [];

        foreach ($this->v1Routes() as $route) {
            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') {
                    continue;
                }

                $registered["{$method} /{$route->uri()}"] = true;
            }
        }

        $stale = array_values(array_filter(
            array_keys($this->ledger()),
            static fn (string $route): bool => ! isset($registered[$route]),
        ));

        $this->assertSame([], $stale, 'Ledger lists routes that are not registered: '.implode(', ', $stale));
    }

    public function test_the_ledger_covers_all_hundred_ninety_three_operations(): void
    {
        $this->assertCount(193, $this->ledger(), 'Every registered /v1 operation must have exactly one ledger row.');
    }

    public function test_public_routes_never_claim_a_capability(): void
    {
        $allowed = [
            'v1/health',
            'v1/health/live',
            'v1/health/ready',
            'v1/meta',
            'v1/auth/login',
            'v1/auth/mfa/challenge',
        ];

        foreach ($this->v1Routes() as $route) {
            if (! in_array($route->uri(), $allowed, true)) {
                continue;
            }

            $hasCapability = false;

            foreach ($route->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && str_starts_with($middleware, 'capability:')) {
                    $hasCapability = true;
                }
            }

            $this->assertFalse($hasCapability, "Public route /{$route->uri()} must stay on the explicit allowlist.");
        }
    }
}
