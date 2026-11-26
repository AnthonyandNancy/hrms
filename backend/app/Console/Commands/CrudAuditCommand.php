<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\People\Models\Employment;
use App\Modules\People\Models\EmploymentContract;
use Illuminate\Console\Command;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route as RouteFacade;

/**
 * CRUD or archival-policy gate for every domain module.
 *
 * A module with mutable business records must expose a read route and at
 * least one governed creation/update path; where physical deletion is
 * prohibited it must expose archive/soft-delete and restore instead. This
 * audit is intentionally route-driven so a controller cannot claim a policy
 * it does not enforce.
 */
final class CrudAuditCommand extends Command
{
    protected $signature = 'crud:audit';

    protected $description = 'Verify every business module has CRUD or an explicit archive/immutable policy';

    /** @var array<string, string> */
    private const MODULE_CAPABILITY_PREFIXES = [
        'people' => 'people.',
        'organization' => 'org.',
        'time' => 'time.',
        'payroll' => 'payroll.',
        'talent' => 'training.',
        'reporting' => 'reporting.',
        'engagement' => 'engagement.',
        'iam' => 'iam.',
    ];

    public function handle(): int
    {
        $routes = $this->v1Routes();
        $failures = [];

        // Employee archive/restore is the explicit non-physical-delete policy.
        foreach ([
            'DELETE v1/employees/{employment_id}' => Employment::class,
            'POST v1/employees/{employment_id}/restore' => Employment::class,
            'DELETE v1/employment-contracts/{id}' => EmploymentContract::class,
            'POST v1/employment-contracts/{id}/restore' => EmploymentContract::class,
        ] as $route => $_) {
            if (! isset($routes[$route])) {
                $failures[] = "missing archival route {$route}";
            }
        }

        $this->assertNonPhysicalDeletes('employees', 'employment_id', $failures);
        $this->assertNonPhysicalDeletes('employment-contracts', 'id', $failures);
        $this->assertSoftDeleteColumns('employment_contracts', $failures);
        $this->assertArchiveColumns('employments', $failures);

        foreach (self::MODULE_CAPABILITY_PREFIXES as $module => $prefix) {
            $moduleRoutes = array_filter(
                $routes,
                static fn (array $route): bool => str_starts_with((string) $route['capability'], $prefix),
            );

            if ($moduleRoutes === []) {
                $failures[] = "{$module}: no capability-guarded v1 routes";
                continue;
            }

            $hasRead = false;
            $hasWrite = false;
            foreach ($moduleRoutes as $route) {
                $hasRead = $hasRead || in_array($route['method'], ['GET', 'HEAD'], true);
                $hasWrite = $hasWrite || in_array($route['method'], ['POST', 'PUT', 'PATCH', 'DELETE'], true);
            }

            if (! $hasRead) {
                $failures[] = "{$module}: no read route";
            }
            if (! $hasWrite) {
                $failures[] = "{$module}: no create/update/archive route";
            }
        }

        if ($failures !== []) {
            $this->error('CRUD audit failed:');
            foreach ($failures as $failure) {
                $this->line('  - '.$failure);
            }

            return self::FAILURE;
        }

        $this->info(sprintf(
            'CRUD audit OK: %d guarded business routes, employees archive/restore, contracts soft-delete/restore.',
            count($routes),
        ));

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $failures
     */
    private function assertNonPhysicalDeletes(string $prefix, string $parameter, array &$failures): void
    {
        $physical = [];
        foreach ($this->v1Routes() as $route => $details) {
            if ($details['method'] !== 'DELETE') {
                continue;
            }

            if (str_contains($route, 'v1/'.$prefix.'/') && str_contains($route, '{'.$parameter.'}')) {
                $physical[] = $route;
            }
        }

        if ($physical === []) {
            $failures[] = "{$prefix}: no archive/soft-delete DELETE route found";
        }
    }

    /**
     * @param  list<string>  $failures
     */
    private function assertSoftDeleteColumns(string $table, array &$failures): void
    {
        foreach (['deleted_at', 'deleted_by', 'delete_reason'] as $column) {
            if (! \Illuminate\Support\Facades\Schema::hasColumn($table, $column)) {
                $failures[] = "{$table}.{$column} is required for soft delete";
            }
        }
    }

    /**
     * @param  list<string>  $failures
     */
    private function assertArchiveColumns(string $table, array &$failures): void
    {
        foreach (['archived_at', 'archived_by', 'archive_reason'] as $column) {
            if (! \Illuminate\Support\Facades\Schema::hasColumn($table, $column)) {
                $failures[] = "{$table}.{$column} is required for employee archive";
            }
        }
    }

    /**
     * @return array<string, array{method: string, capability: string}>
     */
    private function v1Routes(): array
    {
        $registered = RouteFacade::getRoutes();

        if (! $registered instanceof RouteCollection) {
            return [];
        }

        $rows = [];
        foreach ($registered->getRoutes() as $route) {
            if (! $route instanceof Route || ! str_starts_with($route->uri(), 'v1/')) {
                continue;
            }

            $method = $this->primaryMethod($route);
            $capability = null;
            foreach ($route->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && str_starts_with($middleware, 'capability:')) {
                    $parts = explode(',', substr($middleware, strlen('capability:')));
                    $capability = $parts[0] !== '' ? $parts[0] : null;
                }
            }

            $rows[$method.' '.$route->uri()] = [
                'method' => $method,
                'capability' => $capability ?? '',
            ];
        }

        return $rows;
    }

    private function primaryMethod(Route $route): string
    {
        $methods = array_values(array_diff($route->methods(), ['HEAD', 'OPTIONS']));

        return $methods[0] ?? 'GET';
    }
}
