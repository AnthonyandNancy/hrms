#!/usr/bin/env bash
#
# Local SQLite rollback drill.
#
# Usage:
#   deploy/scripts/rollback_drill.sh [--budget-seconds=N] [--database=PATH] [PATH]
#
# The drill takes a consistent SQLite snapshot through the SQLite online-backup
# API with the source opened read-only, restores the snapshot to a second
# temporary file, proves the SHA-256 values match, and verifies the restored
# copy with PRAGMA integrity_check, PRAGMA foreign_key_check and expected-table
# existence/count queries. The source database is never opened for writing.
#
# The local drill cannot prove MySQL PITR, feature-flag rollback or production
# route switching. Those steps are printed as NOT_EXECUTED lines; see
# docs/runbooks/rollback.md for the operational procedure.
set -euo pipefail

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd -- "$script_dir/../.." && pwd)"

budget_seconds="${ROLLBACK_BUDGET_SECONDS:-3600}"
database_option=""
database_option_set=0
positional_database=""

usage() {
    cat <<'USAGE'
Usage: rollback_drill.sh [--budget-seconds=N] [--database=PATH] [PATH]

Runs a local SQLite backup/restore rollback drill and reports elapsed wall-clock
time against a configurable budget.

Options:
  --budget-seconds=N  PASS/FAIL budget in seconds (default: 3600 = 60 minutes)
  --database=PATH     SQLite database to drill (default:
                      <repo>/backend/database/database.sqlite)
  PATH                Positional form of --database
  -h, --help          Show this help

Environment:
  ROLLBACK_BUDGET_SECONDS  Default budget when --budget-seconds is not supplied.
  PHP_BIN                  PHP executable; otherwise php on PATH or
                           <repo>/.tools/php/php is used.

Exit status:
  0  Restore and integrity checks passed and elapsed time is within budget.
  1  A check failed or the elapsed time exceeded the budget.
  2  Invalid command-line usage.
USAGE
}

usage_error() {
    printf 'FAIL: %s\n\n' "$1" >&2
    usage >&2
    exit 2
}

fail() {
    printf 'FAIL: %s\n' "$1" >&2
    exit 1
}

require_cmd() {
    command -v "$1" >/dev/null 2>&1 || fail "required command not found: $1"
}

while (( $# > 0 )); do
    case "$1" in
        --budget-seconds=*)
            budget_seconds="${1#*=}"
            shift
            ;;
        --budget-seconds)
            (( $# >= 2 )) || usage_error "missing value for --budget-seconds"
            budget_seconds="$2"
            shift 2
            ;;
        --database=*)
            database_option="${1#*=}"
            database_option_set=1
            shift
            ;;
        --database)
            (( $# >= 2 )) || usage_error "missing value for --database"
            database_option="$2"
            database_option_set=1
            shift 2
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        --)
            shift
            (( $# == 1 )) || usage_error "expected exactly one database path after --"
            positional_database="$1"
            shift
            ;;
        -*)
            usage_error "unknown option: $1"
            ;;
        *)
            [[ -z "$positional_database" ]] || usage_error "multiple database paths supplied"
            positional_database="$1"
            shift
            ;;
    esac
done

if (( database_option_set == 1 )) && [[ -n "$positional_database" ]]; then
    usage_error "use either --database=PATH or a positional PATH, not both"
fi

if (( database_option_set == 1 )) && [[ -z "$database_option" ]]; then
    usage_error "--database requires a non-empty path"
fi

if [[ ! "$budget_seconds" =~ ^[1-9][0-9]*$ ]]; then
    usage_error "budget seconds must be a positive integer"
fi

if (( database_option_set == 1 )); then
    database_input="$database_option"
elif [[ -n "$positional_database" ]]; then
    database_input="$positional_database"
else
    database_input="$repo_root/backend/database/database.sqlite"
fi

[[ -f "$database_input" ]] || fail "SQLite database not found: $database_input"

database_dir="$(cd -- "$(dirname -- "$database_input")" && pwd -P)" \
    || fail "could not resolve database directory: $database_input"
database_path="${database_dir}/$(basename -- "$database_input")"

require_cmd sha256sum
require_cmd awk
require_cmd cp
require_cmd mktemp

php_bin="${PHP_BIN:-}"
if [[ -z "$php_bin" ]]; then
    if command -v php >/dev/null 2>&1; then
        php_bin="$(command -v php)"
    elif [[ -x "$repo_root/.tools/php/php" ]]; then
        php_bin="$repo_root/.tools/php/php"
    else
        fail "php not found (set PHP_BIN, add php to PATH, or use .tools/php/php)"
    fi
fi
[[ -x "$php_bin" ]] || fail "PHP executable is not executable: $php_bin"
"$php_bin" -r 'exit(extension_loaded("sqlite3") ? 0 : 1);' >/dev/null 2>&1 \
    || fail "PHP sqlite3 extension is required: $php_bin"

workdir="$(mktemp -d "${TMPDIR:-/tmp}/hrms-rollback-drill.XXXXXX")" \
    || fail "could not create temporary directory"
cleanup() {
    if [[ -n "${workdir:-}" && -d "${workdir}" ]]; then
        rm -rf -- "$workdir"
    fi
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

helper_path="$workdir/sqlite_drill.php"
snapshot_path="$workdir/snapshot.sqlite"
restore_path="$workdir/restore.sqlite"

cat > "$helper_path" <<'PHP'
<?php

declare(strict_types=1);

/**
 * Small, read-only SQLite helper for deploy/scripts/rollback_drill.sh.
 *
 * Modes:
 *   snapshot <source> <destination>  Consistent online backup, source READONLY.
 *   verify <restored>                Read-only integrity/schema checks.
 */

function abort_with(string $message): never
{
    fwrite(STDERR, 'FAIL: '.$message."\n");
    exit(1);
}

function open_readonly(string $path): SQLite3
{
    if (! is_file($path)) {
        abort_with("SQLite file not found: {$path}");
    }

    try {
        $database = new SQLite3($path, SQLITE3_OPEN_READONLY);
        $database->enableExceptions(true);
        $database->busyTimeout(5000);
        $database->exec('PRAGMA query_only = ON');
    } catch (Throwable $exception) {
        abort_with("cannot open SQLite database read-only: {$exception->getMessage()}");
    }

    return $database;
}

$mode = $argv[1] ?? '';

if ($mode === 'snapshot') {
    if ($argc !== 4) {
        abort_with('usage: sqlite_drill.php snapshot <source> <destination>');
    }

    $sourcePath = $argv[2];
    $destinationPath = $argv[3];
    $source = null;
    $destination = null;

    try {
        $source = open_readonly($sourcePath);
        $destination = new SQLite3(
            $destinationPath,
            SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE,
        );
        $destination->enableExceptions(true);
        $destination->busyTimeout(5000);

        if (! $source->backup($destination)) {
            throw new RuntimeException('SQLite online backup returned false');
        }
    } catch (Throwable $exception) {
        abort_with("snapshot failed: {$exception->getMessage()}");
    } finally {
        if ($destination instanceof SQLite3) {
            $destination->close();
        }

        if ($source instanceof SQLite3) {
            $source->close();
        }
    }

    echo "Snapshot created with SQLite online backup (source opened read-only).\n";
    exit(0);
}

if ($mode === 'verify') {
    if ($argc !== 3) {
        abort_with('usage: sqlite_drill.php verify <restored>');
    }

    $restoredPath = $argv[2];
    $expectedTables = [
        'migrations',
        'users',
        'audit_events',
        'feature_flags',
        'org_units',
        'employments',
        'employment_terms',
        'leave_ledger_entries',
        'payroll_runs',
        'payslips',
    ];

    $database = open_readonly($restoredPath);

    try {
        $integrityRows = [];
        $integrity = $database->query('PRAGMA integrity_check');

        while (($row = $integrity->fetchArray(SQLITE3_NUM)) !== false) {
            $integrityRows[] = (string) $row[0];
        }

        $integrity->finalize();

        if ($integrityRows !== ['ok']) {
            abort_with('PRAGMA integrity_check did not return ok: '.implode('; ', $integrityRows));
        }

        echo "integrity_check: ok\n";

        $foreignKeyViolations = 0;
        $foreignKeys = $database->query('PRAGMA foreign_key_check');

        while ($foreignKeys->fetchArray(SQLITE3_ASSOC) !== false) {
            $foreignKeyViolations++;
        }

        $foreignKeys->finalize();

        if ($foreignKeyViolations !== 0) {
            abort_with("PRAGMA foreign_key_check found {$foreignKeyViolations} violation(s)");
        }

        echo "foreign_key_violations: 0\n";

        $tableCount = (int) $database->querySingle(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'",
        );
        $migrationsCount = null;

        foreach ($expectedTables as $table) {
            $escapedTable = $database->escapeString($table);
            $exists = (int) $database->querySingle(
                "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = '{$escapedTable}'",
            );

            if ($exists !== 1) {
                abort_with("expected table is missing from restored database: {$table}");
            }

            $count = (int) $database->querySingle("SELECT COUNT(*) FROM \"{$table}\"");
            printf("table:%-24s count=%d\n", $table, $count);

            if ($table === 'migrations') {
                $migrationsCount = $count;
            }
        }

        if ($migrationsCount === null || $migrationsCount < 1) {
            abort_with('restored database has no migration history');
        }

        printf("table_count: %d\n", $tableCount);
    } finally {
        $database->close();
    }

    echo "Restored database passed read-only integrity and schema checks.\n";
    exit(0);
}

abort_with("unknown mode: {$mode}");
PHP

hash_file() {
    sha256sum -- "$1" | awk '{print $1}'
}

now_ns() {
    local value
    value="$(date +%s%N)"
    if [[ "$value" =~ ^[0-9]+$ ]]; then
        printf '%s\n' "$value"
    else
        printf '%s000000000\n' "$(date +%s)"
    fi
}

printf 'HRMS rollback drill (local SQLite)\n'
printf 'Source: %s\n' "$database_path"
printf 'Budget: %s s\n' "$budget_seconds"
printf 'Snapshot helper: PHP SQLite3 online backup, source read-only\n'
printf 'Source access:   opened with SQLITE3_OPEN_READONLY + PRAGMA query_only=ON\n\n'

start_ns="$(now_ns)"
source_hash_before="$(hash_file "$database_path")" \
    || fail "could not hash source database"
printf 'Source sha256 (before):   %s\n' "$source_hash_before"

"$php_bin" "$helper_path" snapshot "$database_path" "$snapshot_path" \
    || fail "could not take SQLite snapshot"
snapshot_hash="$(hash_file "$snapshot_path")" \
    || fail "could not hash snapshot"
printf 'Snapshot sha256:          %s\n' "$snapshot_hash"

cp -- "$snapshot_path" "$restore_path" \
    || fail "could not restore snapshot to temporary path"
restore_hash="$(hash_file "$restore_path")" \
    || fail "could not hash restored copy"
printf 'Restored sha256:          %s\n' "$restore_hash"

if [[ "$snapshot_hash" != "$restore_hash" ]]; then
    fail "restored copy differs from snapshot (sha256 mismatch)"
fi
printf 'Hash equality:            PASS (snapshot == restored)\n\n'

printf 'Verifying restored copy only (read-only connection):\n'
"$php_bin" "$helper_path" verify "$restore_path" \
    || fail "restored database failed integrity/schema checks"

restored_hash_after_checks="$(hash_file "$restore_path")" \
    || fail "could not re-hash restored copy after checks"
if [[ "$restore_hash" != "$restored_hash_after_checks" ]]; then
    fail "restored copy changed during read-only checks"
fi
printf 'Restored copy unchanged after checks: PASS\n'

source_hash_after="$(hash_file "$database_path")" \
    || fail "could not re-hash source database"
if [[ "$source_hash_before" == "$source_hash_after" ]]; then
    printf 'Source unchanged during drill:      PASS\n\n'
else
    printf 'Source opened read-only:            PASS (SQLITE3_OPEN_READONLY + query_only)\n'
    printf 'WARN: source sha256 changed during the drill; another process wrote to the live database.\n'
    printf '      The snapshot was taken with the SQLite online-backup API and the restored copy passed checks.\n'
    printf '      Stop writes for an authoritative cutover drill; this script never opens the source writable.\n\n'
fi

printf 'NOT_EXECUTED: MySQL PITR - restore the full backup plus binlogs to an isolated MySQL 8.4 instance and measure RPO/RTO (see docs/runbooks/rollback.md).\n'
printf 'NOT_EXECUTED: feature-flag rollback - php artisan feature:set <flag> off --tenant=<id> --reason="<reason>" then verify the audit row and route; this changes state and is not run locally.\n'
printf 'NOT_EXECUTED: route switch - move traffic back to the legacy system at the load balancer/DNS and verify user-facing smoke tests; this requires the real cutover environment.\n\n'

end_ns="$(now_ns)"
if (( end_ns < start_ns )); then
    fail "system clock moved backwards while measuring elapsed time"
fi

elapsed_ns=$((end_ns - start_ns))
elapsed_seconds="$(awk -v ns="$elapsed_ns" 'BEGIN { printf "%.3f", ns / 1000000000 }')"
budget_ns=$((budget_seconds * 1000000000))

printf 'Elapsed: %s s\n' "$elapsed_seconds"
printf 'Budget:  %s s\n' "$budget_seconds"

if (( elapsed_ns <= budget_ns )); then
    printf 'PASS: rollback drill completed within budget; restored database passed integrity and schema checks (elapsed=%ss budget=%ss)\n' \
        "$elapsed_seconds" "$budget_seconds"
    exit 0
fi

printf 'FAIL: rollback drill exceeded budget; elapsed=%ss budget=%ss (restore checks passed, budget gate failed)\n' \
    "$elapsed_seconds" "$budget_seconds" >&2
exit 1
