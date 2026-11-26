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
     * The legacy set is frozen: these are the endpoints that existed when the
     * kernel was introduced. A new route may not be born legacy — it must use
     * CommandRunner/ProjectionResponder and be classified `kernel`.
     *
     * @var list<string>|null
     */
    private static ?array $frozenLegacySet = null;

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
                $key = "{$method} {$uriKey}";

                if (array_key_exists($key, $rows)) {
                    $this->fail("Duplicate ledger row for {$key}.");
                }

                $rows[$key] = [
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

    public function test_the_ledger_covers_every_registered_operation(): void
    {
        $this->assertCount(320, $this->ledger(), 'Every registered /v1 operation must have exactly one ledger row.');
    }

    public function test_the_unguarded_routes_are_exactly_the_public_allowlist(): void
    {
        // Single source of truth: the audit command's own allowlist. Adding a
        // signed or public route must be an explicit decision there, not a
        // silent extra entry here.
        $allowlist = \App\Console\Commands\CapabilityAuditCommand::PUBLIC_ALLOWLIST;

        $unguarded = [];

        foreach ($this->v1Routes() as $route) {
            $hasCapability = false;

            foreach ($route->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && str_starts_with($middleware, 'capability:')) {
                    $hasCapability = true;
                }
            }

            if (! $hasCapability) {
                $unguarded[] = $route->uri();
            }
        }

        sort($unguarded);
        sort($allowlist);

        $this->assertSame($allowlist, $unguarded, 'Exactly the allowlisted routes may be unguarded.');
    }

    public function test_every_public_allowlist_route_is_classified_kernel(): void
    {
        $ledger = $this->ledger();

        foreach (\App\Console\Commands\CapabilityAuditCommand::PUBLIC_ALLOWLIST as $uri) {
            $entries = array_filter(
                $ledger,
                static fn (array $entry, string $key): bool => str_ends_with($key, " /{$uri}"),
                ARRAY_FILTER_USE_BOTH,
            );

            $this->assertNotEmpty($entries, "Public route /{$uri} is missing from the ledger.");

            foreach ($entries as $key => $entry) {
                $this->assertSame('kernel', $entry['kind'], "{$key} must be classified kernel.");
            }
        }
    }

    public function test_a_new_route_may_not_be_born_legacy(): void
    {
        $legacy = [];

        foreach ($this->ledger() as $route => $entry) {
            if ($entry['kind'] === 'legacy') {
                $legacy[] = $route;
            }
        }

        sort($legacy);
        $frozen = self::frozenLegacySet();

        $added = array_values(array_diff($legacy, $frozen));

        $this->assertSame(
            [],
            $added,
            'New routes must use CommandRunner/ProjectionResponder and be classified kernel. New legacy routes: '.implode(', ', $added),
        );
    }

    /**
     * Frozen baseline captured at commit 3274d7b. Regenerate only when a task
     * deliberately adds a legacy endpoint (never for a new feature route).
     *
     * @return list<string>
     */
    private static function frozenLegacySet(): array
    {
        if (self::$frozenLegacySet !== null) {
            return self::$frozenLegacySet;
        }

        $fixture = base_path('tests/Contract/fixtures/legacy-routes.frozen.txt');
        $contents = (string) file_get_contents($fixture);

        self::$frozenLegacySet = array_values(array_filter(array_map('trim', explode('
', $contents))));

        return self::$frozenLegacySet;
    }
}
