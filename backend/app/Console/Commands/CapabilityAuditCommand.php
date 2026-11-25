<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route as RouteFacade;

/**
 * Default-deny gate for the /v1 API surface.
 *
 * Every route must either be in the small explicit public allowlist or carry
 * a capability:<code> middleware / route default. The negative-test manifest
 * is intentionally a warning-only check until every module has landed its
 * authorization fixtures.
 */
final class CapabilityAuditCommand extends Command
{
    /**
     * Exact URIs that are allowed to be unguarded. Keep this list minimal and
     * justify additions in docs/01-architecture.md.
     *
     * @var list<string>
     */
    public const PUBLIC_ALLOWLIST = [
        'v1/health',
        'v1/health/live',
        'v1/health/ready',
        'v1/meta',
        'v1/auth/login',
        'v1/auth/mfa/challenge',
    ];

    protected $signature = 'capability:audit
        {--manifest= : Override the negative-test manifest path}';

    protected $description = 'Fail when a /v1 route is neither public nor capability-guarded';

    public function handle(): int
    {
        $rows = [];
        $unguarded = [];
        $missingManifest = [];
        $violations = [];
        $manifest = $this->manifest();

        foreach ($this->v1Routes() as $route) {
            $uri = trim($route->uri(), '/');
            $method = $this->primaryMethod($route);
            $capability = $this->capability($route);
            $public = in_array($uri, self::PUBLIC_ALLOWLIST, true);

            if ($public) {
                $status = 'PUBLIC';
                $capability = null;
            } elseif ($capability !== null) {
                $status = 'GUARDED';
            } else {
                $status = 'UNGUARDED';
                $unguarded[] = "{$method} {$uri}";
            }

            $rows[] = [
                "{$method} {$uri}",
                $capability ?? '-',
                $status,
            ];

            if (! $public && $capability !== null && ! isset($manifest["{$method} /{$uri}"])) {
                $missingManifest[] = "{$method} /{$uri}";
            }
        }

        $this->table(['Route', 'Capability', 'Status'], $rows);

        // Plain per-route lines keep CI logs and test assertions reliable even
        // when a console renderer reflows wide tables.
        foreach ($rows as $row) {
            $this->line(sprintf('%s %s %s', $row[2], $row[0], $row[1]));
        }

        if ($public = count(array_filter($rows, static fn (array $row): bool => $row[2] === 'PUBLIC'))) {
            $this->line("Public routes (explicit allowlist): {$public}");
        }

        $catalog = app(\App\Modules\IAM\Authorization\CapabilityCatalog::class);
        $templates = \App\Modules\IAM\Authorization\RoleTemplateCatalog::templates();
        $issued = [];

        foreach ($templates as $template) {
            foreach ($template->capabilityCodes() as $code) {
                $issued[] = $code;

                if (! \App\Shared\Authorization\CapabilityWildcard::is($code) && ! $catalog->has($code)) {
                    $violations[] = "G-COV-2: template {$template->code} grants unknown capability {$code}";
                }
            }
        }

        $intrinsic = ['iam.session.manage'];
        $wildcardIssued = in_array(\App\Shared\Authorization\CapabilityWildcard::WILDCARD, $issued, true);

        foreach ($catalog->codes() as $code) {
            if (in_array($code, $intrinsic, true)) {
                continue;
            }

            if (! $wildcardIssued && ! in_array($code, $issued, true)) {
                $violations[] = "G-COV-1: no role template issues {$code}";
            }
        }

        foreach ($this->v1Routes() as $route) {
            $code = $this->capability($route);

            if ($code === null || in_array($code, $intrinsic, true)) {
                continue;
            }

            // A wildcard makes a code REACHABLE but does not make it a real
            // catalogued capability. A route that names an unregistered code is
            // a typo risk (it silently falls back to deny for every non-super
            // user), so this check must not be satisfied by the wildcard.
            if (! $catalog->has($code)) {
                $violations[] = "G-COV-3: route {$route->uri()} requires unknown capability {$code}";

                continue;
            }

            if (! $wildcardIssued && ! in_array($code, $issued, true)) {
                $violations[] = "G-COV-3: route {$route->uri()} requires unissued capability {$code}";
            }
        }

        if ($violations !== []) {
            $this->error('Capability coverage audit failed with '.count($violations).' violation(s):');

            foreach ($violations as $violation) {
                $this->line('  - '.$violation);
            }

            $this->line('Fix: add the capability to CapabilityCatalog and to at least one role template, then re-run.');

            return self::FAILURE;
        }

        if ($unguarded !== []) {
            $this->error('Capability audit failed: unguarded /v1 routes:');
            foreach ($unguarded as $route) {
                $this->line("  - {$route}");
            }

            return self::FAILURE;
        }

        if ($missingManifest !== []) {
            $this->warn('Warnings: no negative-test manifest entry for '.count($missingManifest).' route(s).');
            foreach ($missingManifest as $route) {
                $this->warn("  - No negative-test manifest entry: {$route}");
            }
        }

        $this->info('Capability audit OK.');

        return self::SUCCESS;
    }

    /**
     * @return list<Route>
     */
    private function v1Routes(): array
    {
        $routes = [];

        $registered = RouteFacade::getRoutes();

        if (! $registered instanceof RouteCollection) {
            return [];
        }

        foreach ($registered->getRoutes() as $route) {
            $uri = trim($route->uri(), '/');

            if (! str_starts_with($uri, 'v1/')) {
                continue;
            }

            $routes[] = $route;
        }

        usort(
            $routes,
            static fn (Route $a, Route $b): int => strcmp($a->uri(), $b->uri()),
        );

        return $routes;
    }

    private function primaryMethod(Route $route): string
    {
        foreach ($route->methods() as $method) {
            if ($method !== 'HEAD') {
                return $method;
            }
        }

        return 'GET';
    }

    private function capability(Route $route): ?string
    {
        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'capability:')) {
                // Middleware may carry an anchor suffix:
                //   capability:people.employee.read,LEGAL_ENTITY,legal_entity_id
                // Only the first segment is the capability code.
                $code = trim(explode(',', substr($middleware, strlen('capability:')))[0]);

                return $code !== '' ? $code : null;
            }
        }

        $default = $route->defaults['capability'] ?? null;

        return is_string($default) && trim($default) !== '' ? trim($default) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function manifest(): array
    {
        $option = $this->option('manifest');
        $path = is_string($option) && $option !== ''
            ? $option
            : base_path('tests/Authorization/endpoint_matrix.php');

        if (! is_file($path)) {
            $this->warn("Negative-test manifest not found: {$path}");

            return [];
        }

        $manifest = require $path;

        return is_array($manifest) ? $manifest : [];
    }
}
