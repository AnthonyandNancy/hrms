<?php

declare(strict_types=1);

/**
 * Emits the registered /v1 route surface as JSON so the legacy ledger and the
 * cluster coverage audit can be regenerated instead of hand-maintained.
 *
 * Usage: php scripts/dump_routes.php > .superpowers/sdd/v11-gap/routes.json
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Routing\Route;

require dirname(__DIR__).'/backend/vendor/autoload.php';

$app = require dirname(__DIR__).'/backend/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$rows = [];

foreach (app('router')->getRoutes()->getRoutes() as $route) {
    if (! $route instanceof Route) {
        continue;
    }

    if (! str_starts_with($route->uri(), 'v1/')) {
        continue;
    }

    $capability = null;
    $anchor = null;

    foreach ($route->gatherMiddleware() as $middleware) {
        if (! is_string($middleware) || ! str_starts_with($middleware, 'capability:')) {
            continue;
        }

        $parts = explode(',', substr($middleware, strlen('capability:')));
        $capability = trim($parts[0]);
        $anchor = isset($parts[1]) ? trim($parts[1]) : null;
    }

    foreach ($route->methods() as $method) {
        if ($method === 'HEAD') {
            continue;
        }

        $rows[] = [
            'method' => $method,
            'uri' => '/'.$route->uri(),
            'capability' => $capability,
            'anchor' => $anchor,
        ];
    }
}

usort($rows, static fn (array $a, array $b): int => [$a['uri'], $a['method']] <=> [$b['uri'], $b['method']]);

echo json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
