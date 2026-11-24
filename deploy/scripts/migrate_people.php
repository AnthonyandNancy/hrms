#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Cutover wrapper for the "People and employments" migration domain (plan section 4.10).
 *
 * Defaults --domain=people and delegates to migrate_domain.php, so
 * validation, staging, target writes and reconciliation all run through the
 * single migrate:domain implementation rather than a parallel engine.
 *
 * Examples:
 *   php deploy/scripts/migrate_people.php                       # validate the known-good fixture
 *   php deploy/scripts/migrate_people.php --mode=apply --shadow  # rehearse into the shadow target
 *   php deploy/scripts/migrate_people.php --mode=apply --file=/path/to/prod.csv --tenant=1
 */

$delegate = __DIR__.'/migrate_domain.php';

if (! is_file($delegate)) {
    fwrite(STDERR, "ERROR: migrate_domain.php is missing next to this wrapper.\n");
    exit(2);
}

$arguments = [$delegate, '--domain=people'];

foreach (array_slice($argv, 1) as $argument) {
    // Reject an override that would silently migrate a different domain.
    if (str_starts_with($argument, '--domain=')) {
        fwrite(STDERR, "ERROR: migrate_people.php is fixed to --domain=people; use migrate_domain.php to pick another domain.\n");
        exit(2);
    }

    $arguments[] = $argument;
}

$command = escapeshellarg(PHP_BINARY);

foreach ($arguments as $argument) {
    $command .= ' '.escapeshellarg($argument);
}

passthru($command, $exitCode);
exit($exitCode);
