#!/usr/bin/env bash
#
# Backup/restore smoke check for a SQLite database.
#
# Usage: deploy/scripts/backup_restore_smoke.sh [path-to-sqlite-db]
# Default: backend/database/database.sqlite relative to the repository root.
#
# The check copies the database, records its SHA-256, restores that copy to a
# second temporary file, recomputes the hash and prints PASS or FAIL. It does
# not touch the source database and always removes its temporary directory.
set -euo pipefail

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd -- "$script_dir/../.." && pwd)"

database_path="${1:-$repo_root/backend/database/database.sqlite}"

fail() {
    printf 'FAIL: %s\n' "$1" >&2
    exit 1
}

if [[ ! -f "$database_path" ]]; then
    fail "SQLite database not found: $database_path"
fi

if ! command -v sha256sum >/dev/null 2>&1; then
    fail "sha256sum is required for the backup/restore smoke check"
fi

workdir="$(mktemp -d "${TMPDIR:-/tmp}/hrms-backup-smoke.XXXXXX")"
cleanup() {
    rm -rf -- "$workdir"
}
trap cleanup EXIT

backup_path="$workdir/backup.sqlite"
restore_path="$workdir/restore.sqlite"

cp -- "$database_path" "$backup_path" || fail "could not copy source database"
source_hash="$(sha256sum -- "$backup_path" | awk '{print $1}')"

printf 'Source: %s\n' "$database_path"
printf 'sha256: %s\n' "$source_hash"

cp -- "$backup_path" "$restore_path" || fail "could not restore backup copy"
restore_hash="$(sha256sum -- "$restore_path" | awk '{print $1}')"
printf 'restored sha256: %s\n' "$restore_hash"

if [[ "$source_hash" != "$restore_hash" ]]; then
    fail "hashes do not match; restored copy differs from backup"
fi

printf 'PASS: backup/restore smoke check completed; hashes match\n'
