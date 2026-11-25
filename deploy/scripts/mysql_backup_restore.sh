#!/usr/bin/env bash
#
# MySQL 8.4 backup/restore drill for the real Compose stack.
#
# Dumps the factoryhr database from the running mysql container, restores it
# into a temporary database in the same container, and compares table counts +
# a per-table row-count checksum. It never writes to the source database.
#
# Usage:
#   deploy/scripts/mysql_backup_restore.sh [--project factoryhr] [--service mysql] [--database factoryhr]
set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd -- "$SCRIPT_DIR/../.." && pwd)"
PROJECT="${COMPOSE_PROJECT_NAME:-factoryhr}"
SERVICE="${MYSQL_SERVICE:-mysql}"
DATABASE="${MYSQL_DATABASE:-factoryhr}"
ROOT_USER="${MYSQL_ROOT_USER:-root}"
ROOT_PASSWORD="${MYSQL_ROOT_PASSWORD:-change-me-root}"

usage() {
  cat <<'USAGE'
Usage: mysql_backup_restore.sh [--project NAME] [--service NAME] [--database NAME]

Requires a running Compose stack with a mysql service. Produces
evidence/mysql-backup-restore.txt and exits non-zero on any mismatch.
USAGE
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --project) PROJECT="${2:-}"; shift 2 ;;
    --service) SERVICE="${2:-}"; shift 2 ;;
    --database) DATABASE="${2:-}"; shift 2 ;;
    -h|--help) usage; exit 0 ;;
    *) echo "unknown argument: $1" >&2; exit 2 ;;
  esac
done

command -v docker >/dev/null 2>&1 || { echo "FAIL: docker is required" >&2; exit 1; }

EVIDENCE_DIR="$REPO_ROOT/evidence"
mkdir -p "$EVIDENCE_DIR"
LOG="$EVIDENCE_DIR/mysql-backup-restore.txt"
: >"$LOG"

container="$(docker compose -p "$PROJECT" ps -q "$SERVICE" 2>/dev/null || true)"
if [[ -z "$container" ]]; then
  echo "FAIL: mysql container for project=$PROJECT service=$SERVICE is not running" | tee -a "$LOG" >&2
  exit 1
fi

tmpdir="$(mktemp -d "${TMPDIR:-/tmp}/hrms-mysql-drill.XXXXXX")"
restore_db="${DATABASE}_restore_$$"
cleanup() {
  docker exec -e MYSQL_PWD="$ROOT_PASSWORD" "$container" mysql -u"$ROOT_USER" -e "DROP DATABASE IF EXISTS \`$restore_db\`;" >/dev/null 2>&1 || true
  rm -rf -- "$tmpdir"
}
trap cleanup EXIT

echo "mysql backup/restore drill" | tee -a "$LOG"
echo "project=$PROJECT container=$container database=$DATABASE" | tee -a "$LOG"

mysqldump_cmd="mysqldump -u$ROOT_USER --single-transaction --quick --routines --triggers --events --set-gtid-purged=OFF $DATABASE"
docker exec -e MYSQL_PWD="$ROOT_PASSWORD" "$container" sh -c "$mysqldump_cmd" > "$tmpdir/backup.sql"
dump_hash="$(sha256sum "$tmpdir/backup.sql" | awk '{print $1}')"
dump_bytes="$(wc -c < "$tmpdir/backup.sql")"
echo "dump_sha256=$dump_hash bytes=$dump_bytes" | tee -a "$LOG"

[[ "$dump_bytes" -gt 0 ]] || { echo "FAIL: empty mysqldump" | tee -a "$LOG" >&2; exit 1; }

docker exec -e MYSQL_PWD="$ROOT_PASSWORD" "$container" mysql -u"$ROOT_USER" -e "DROP DATABASE IF EXISTS \`$restore_db\`; CREATE DATABASE \`$restore_db\` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;"
docker exec -i -e MYSQL_PWD="$ROOT_PASSWORD" "$container" mysql -u"$ROOT_USER" "$restore_db" < "$tmpdir/backup.sql"

table_query="SELECT table_name FROM information_schema.tables WHERE table_schema='$DATABASE' AND table_type='BASE TABLE' ORDER BY table_name"
docker exec -e MYSQL_PWD="$ROOT_PASSWORD" "$container" mysql -N -B -u"$ROOT_USER" -e "$table_query" > "$tmpdir/tables.txt"

mismatch=0
checked=0
while IFS= read -r table; do
  [[ -n "$table" ]] || continue
  checked=$((checked + 1))
  source_count="$(docker exec -e MYSQL_PWD="$ROOT_PASSWORD" "$container" mysql -N -B -u"$ROOT_USER" -e "SELECT COUNT(*) FROM \`$DATABASE\`.\`$table\`;")"
  restore_count="$(docker exec -e MYSQL_PWD="$ROOT_PASSWORD" "$container" mysql -N -B -u"$ROOT_USER" -e "SELECT COUNT(*) FROM \`$restore_db\`.\`$table\`;")"
  if [[ "$source_count" != "$restore_count" ]]; then
    echo "MISMATCH $table source=$source_count restore=$restore_count" | tee -a "$LOG" >&2
    mismatch=$((mismatch + 1))
  fi
done < "$tmpdir/tables.txt"

echo "tables_checked=$checked mismatches=$mismatch" | tee -a "$LOG"
if [[ "$mismatch" -ne 0 ]]; then
  echo "FAIL: mysql restore differs from source" | tee -a "$LOG" >&2
  exit 1
fi

echo "PASS: mysql dump restored with identical table row counts" | tee -a "$LOG"
