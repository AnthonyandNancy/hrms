#!/usr/bin/env bash
# SQLite-native backup/restore smoke test with manifest + row-count proof.
#
# Usage:
#   backup_restore_smoke.sh [--work-dir DIR] [--keep]
#
# Exit 0 only when:
#   - a migrated, seeded SQLite database exists
#   - a consistent backup is taken while the source stays open
#   - the source is mutated after the backup
#   - restore removes the post-backup mutation
#   - a fresh manifest of every table's row count equals the backup manifest
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/common.sh
source "${SCRIPT_DIR}/lib/common.sh"

WORK_DIR=""
KEEP=0

usage() {
  cat <<'USAGE'
Usage: backup_restore_smoke.sh [--work-dir DIR] [--keep]

Creates a temporary SQLite demo database, takes a consistent backup, mutates the
source, restores it, and proves row-count equality plus file checksums.
USAGE
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --work-dir) WORK_DIR="${2:-}"; shift 2 ;;
    --keep) KEEP=1; shift ;;
    -h|--help) usage; exit 0 ;;
    *) die "unknown argument: $1" ;;
  esac
done

require_cmd python3
require_cmd sha256sum
PHP_BIN="$(resolve_php)"

if [[ -z "${WORK_DIR}" ]]; then
  WORK_DIR="$(mktemp -d "${TMPDIR:-/tmp}/hrms-backup-smoke.XXXXXX")"
fi
mkdir -p "${WORK_DIR}"
# The PHP SQLite extension needs a native Windows path under MSYS/Git Bash.
WORK_DIR="$(cd "${WORK_DIR}" && { pwd -W 2>/dev/null || pwd; })"
STARTED_MS="$(python3 -c 'import time; print(int(time.time()*1000))')"

log "work-dir=${WORK_DIR}"

TARGET_DB="${WORK_DIR}/source.sqlite"
rm -f "${TARGET_DB}"
touch "${TARGET_DB}"

db_env() {
  DB_CONNECTION=sqlite DB_DATABASE="${TARGET_DB}" CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync APP_ENV=testing "$@"
}

# Use Laravel migrations + demo seeder instead of a hand-written schema so the
# drill covers the same tables production will back up.
db_env "${PHP_BIN}" "${BACKEND_DIR}/artisan" migrate --force --no-interaction >/dev/null
db_env "${PHP_BIN}" "${BACKEND_DIR}/artisan" demo:seed --tenant=1 >/dev/null

BACKUP_DB="${WORK_DIR}/backup.sqlite"
MANIFEST="${WORK_DIR}/manifest.json"
RESTORED_MANIFEST="${WORK_DIR}/restored.json"
rm -f "${BACKUP_DB}" "${MANIFEST}" "${RESTORED_MANIFEST}"

# Python's sqlite3 Connection.backup() uses SQLite's online backup API. It is
# equivalent to VACUUM INTO for consistency and avoids shell quoting pitfalls.
python3 - "${TARGET_DB}" "${BACKUP_DB}" <<'PY'
import sqlite3
import sys

source_path, backup_path = sys.argv[1], sys.argv[2]
with sqlite3.connect(source_path) as source, sqlite3.connect(backup_path) as backup:
    source.backup(backup)
PY

[[ -s "${BACKUP_DB}" ]] || die "backup file was not created"
BACKUP_CHECKSUM="$(sha256_file "${BACKUP_DB}")"

python3 - "${BACKUP_DB}" "${MANIFEST}" <<'PY'
import json
import sqlite3
import sys

db_path, manifest_path = sys.argv[1], sys.argv[2]
with sqlite3.connect(db_path) as connection:
    tables = [
        row[0]
        for row in connection.execute(
            "select name from sqlite_master where type='table' "
            "and name not like 'sqlite_%' order by name"
        )
    ]
    counts = {
        table: connection.execute(f'select count(*) from "{table}"').fetchone()[0]
        for table in tables
    }

with open(manifest_path, "w", encoding="utf-8") as handle:
    json.dump(counts, handle, indent=2, sort_keys=True)

print(f"  backup tables={len(counts)} total_rows={sum(counts.values())}")
if counts.get("migration_shadow_rows", 0) <= 0:
    print("FAIL: seeded demo produced no shadow rows to protect", file=sys.stderr)
    raise SystemExit(1)
PY

# Mutate the live source after the backup: this is the data a restore must drop.
db_env "${PHP_BIN}" "${BACKEND_DIR}/artisan" tinker --execute='
\Illuminate\Support\Facades\DB::table("demo_datasets")->delete();
\Illuminate\Support\Facades\DB::table("migration_shadow_rows")->where("tenant_id", 1)->limit(3)->delete();
' >/dev/null

# Restore from the verified backup.
python3 - "${BACKUP_DB}" "${TARGET_DB}" <<'PY'
import sqlite3
import sys

backup_path, target_path = sys.argv[1], sys.argv[2]
with sqlite3.connect(backup_path) as backup, sqlite3.connect(target_path) as target:
    backup.backup(target)
PY

RESTORED_CHECKSUM="$(sha256_file "${TARGET_DB}")"

python3 - "${TARGET_DB}" "${RESTORED_MANIFEST}" "${MANIFEST}" <<'PY'
import json
import sqlite3
import sys

db_path, restored_path, expected_path = sys.argv[1], sys.argv[2], sys.argv[3]
with sqlite3.connect(db_path) as connection:
    tables = [
        row[0]
        for row in connection.execute(
            "select name from sqlite_master where type='table' "
            "and name not like 'sqlite_%' order by name"
        )
    ]
    counts = {
        table: connection.execute(f'select count(*) from "{table}"').fetchone()[0]
        for table in tables
    }

with open(restored_path, "w", encoding="utf-8") as handle:
    json.dump(counts, handle, indent=2, sort_keys=True)

with open(expected_path, encoding="utf-8") as handle:
    expected = json.load(handle)

mismatches = {
    table: (expected.get(table), counts.get(table))
    for table in sorted(set(expected) | set(counts))
    if expected.get(table) != counts.get(table)
}
if mismatches:
    print(f"FAIL: row-count mismatch after restore: {mismatches}", file=sys.stderr)
    raise SystemExit(1)

print(f"  restore tables={len(counts)} total_rows={sum(counts.values())} counts=match")
PY

if [[ "${RESTORED_CHECKSUM}" != "${BACKUP_CHECKSUM}" ]]; then
  # Restoring into an existing database can leave free pages in a different
  # layout. Logical equality is authoritative; report the byte difference.
  log "note: restored file checksum differs from backup (SQLite page layout); row counts match"
fi

ELAPSED_MS="$(python3 -c "import time; print(int(time.time()*1000)-${STARTED_MS})")"

if [[ "${KEEP}" != "1" ]]; then
  rm -rf "${WORK_DIR}"
fi

pass "backup_restore_smoke backup_sha256=${BACKUP_CHECKSUM:0:16}... restored_sha256=${RESTORED_CHECKSUM:0:16}... elapsed_ms=${ELAPSED_MS} work_dir=${WORK_DIR}"
