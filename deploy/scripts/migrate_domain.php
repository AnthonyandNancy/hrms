#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Shared implementation for the per-domain cutover migration wrappers named in
 * plan section 4.10:
 *
 *   migrate_organizations.php  migrate_people.php
 *   migrate_leave_opening.php  migrate_payroll_ytd.php
 *
 * Each thin wrapper supplies a default domain so an operator can run one domain
 * during a wave cutover without remembering the full `migrate:domain` flags.
 *
 * This is intentionally a shell around the existing artisan command rather than
 * a second migration engine: there is exactly one implementation of CSV
 * validation, staging, target writes and reconciliation.
 *
 * Usage:
 *   php deploy/scripts/migrate_domain.php --domain=people \
 *       [--file=PATH] [--mode=validate|apply] [--tenant=1] [--shadow] [--json]
 *
 * Exit codes: 0 success, 2 invalid invocation, otherwise the artisan exit code.
 */

use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../backend/vendor/autoload.php';

$app = require __DIR__.'/../../backend/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

/**
 * @param  list<string>  $arguments
 * @return array{options: array<string, string|bool>, errors: list<string>}
 */
function parseArguments(array $arguments): array
{
    $options = [];
    $errors = [];
    $known = ['domain', 'file', 'mode', 'mapping', 'tenant'];
    $flags = ['shadow', 'json', 'help'];

    foreach ($arguments as $argument) {
        if (! str_starts_with($argument, '--')) {
            $errors[] = "Unexpected argument: {$argument}";

            continue;
        }

        $body = substr($argument, 2);

        if (in_array($body, $flags, true)) {
            $options[$body] = true;

            continue;
        }

        if (! str_contains($body, '=')) {
            $errors[] = "Option --{$body} requires a value.";

            continue;
        }

        [$key, $value] = explode('=', $body, 2);

        if (! in_array($key, $known, true)) {
            $errors[] = "Unknown option: --{$key}";

            continue;
        }

        if ($value === '') {
            $errors[] = "Option --{$key} must not be empty.";

            continue;
        }

        // --mapping is repeatable.
        if ($key === 'mapping' && isset($options['mapping'])) {
            $options['mapping'] = (string) $options['mapping'].','.$value;

            continue;
        }

        $options[$key] = $value;
    }

    return ['options' => $options, 'errors' => $errors];
}

/**
 * @param  array<string, string|bool>  $options
 */
function usage(array $options): int
{
    $domain = (string) ($options['domain'] ?? 'people');

    fwrite(STDOUT, <<<TEXT
Cutover migration wrapper (plan E10).

Usage:
  php deploy/scripts/migrate_domain.php --domain={$domain} [options]

Options:
  --domain=DOMAIN   organizations|people|assignments|terms|leave_openings|payroll_ytd
  --file=PATH       Source CSV (default: the known-good fixture for the domain)
  --mode=MODE       validate|apply (default: validate)
  --tenant=N        Tenant id (default: 1)
  --mapping=PATH    Crosswalk CSV (repeatable; defaults to the demo mapping)
  --shadow          Apply to the isolated rehearsal target instead of business tables
  --json            Machine-readable summary
  --help            Show this text

Safety note: without --shadow the command writes business tables. Validate first.

TEXT);

    return 0;
}

$parsed = parseArguments(array_slice($argv, 1));
$options = $parsed['options'];
$errors = $parsed['errors'];

if (isset($options['help'])) {
    exit(usage($options));
}

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, "ERROR: {$error}\n");
    }

    exit(2);
}

$domain = (string) ($options['domain'] ?? '');

$allowed = ['organizations', 'people', 'assignments', 'terms', 'leave_openings', 'payroll_ytd'];

if (! in_array($domain, $allowed, true)) {
    fwrite(STDERR, "ERROR: --domain must be one of: ".implode(', ', $allowed)."\n");

    exit(2);
}

$tenant = (string) ($options['tenant'] ?? '1');

if (preg_match('/^[1-9]\d*$/', $tenant) !== 1) {
    fwrite(STDERR, "ERROR: --tenant must be a positive integer.\n");

    exit(2);
}

$mode = (string) ($options['mode'] ?? 'validate');

if (! in_array($mode, ['validate', 'apply'], true)) {
    fwrite(STDERR, "ERROR: --mode must be validate or apply.\n");

    exit(2);
}

$fixtures = dirname(__DIR__, 2).'/backend/database/migration-fixtures';
$file = (string) ($options['file'] ?? $fixtures.'/known-good/'.$domain.'.csv');

if (! is_file($file)) {
    fwrite(STDERR, "ERROR: source CSV not found: {$file}\n");

    exit(2);
}

$mapping = (string) ($options['mapping'] ?? $fixtures.'/mapping/demo.csv');

if (! is_file($mapping)) {
    fwrite(STDERR, "ERROR: crosswalk CSV not found: {$mapping}\n");

    exit(2);
}

// Route through Artisan's console output so the operator actually sees the
// validation/apply summary; Kernel::call() would buffer it.
$parameters = array_filter(
    [
        '--domain' => $domain,
        '--file' => $file,
        '--mode' => $mode,
        '--tenant' => $tenant,
        '--mapping' => [$mapping],
        '--shadow' => isset($options['shadow']),
        '--json' => isset($options['json']),
    ],
    static fn (mixed $value): bool => $value !== false && $value !== null,
);

$exitCode = $app->make(Kernel::class)->call('migrate:domain', $parameters);

$output = $app->make(Kernel::class)->output();
fwrite(STDOUT, $output);

exit(is_int($exitCode) ? $exitCode : 1);
