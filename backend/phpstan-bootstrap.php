<?php

declare(strict_types=1);

/*
 * PHPStan loads the full Laravel container plus Larastan's reflection data.
 * The bundled CLI PHP defaults to a 128M memory limit, which is too small for
 * a cold analysis of the app and test suites. Raise it only for static
 * analysis; this file is not part of the application runtime.
 */
ini_set('memory_limit', '1G');

/*
 * Larastan reads LARAVEL_VERSION when selecting version-specific stubs. Its
 * own bootstrap defines it from the resolved application; when PHPStan is run
 * from a merged worktree that bootstrap can be skipped, so define it here from
 * the vendored framework constant to keep analysis deterministic.
 */
if (! defined('LARAVEL_VERSION')) {
    define('LARAVEL_VERSION', Illuminate\Foundation\Application::VERSION);
}
