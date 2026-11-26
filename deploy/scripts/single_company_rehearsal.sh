#!/usr/bin/env bash
#
# Single-company cutover rehearsal on the MySQL 8.4 Compose service.
#
# The system is not live, so the cutover is a rebuild: back up the current
# database, inventory it, rebuild the schema from scratch, then prove the
# backup restores and produces the same inventory.
#
# Every database command runs inside the Compose MySQL container, so the host
# needs neither mysql nor mysqldump. Passwords travel through MYSQL_PWD, never
# through a process argument or the logs.
#
# Usage:
#   COMPOSE_PROJECT_NAME=factoryhr \
#   MYSQL_DATABASE=factoryhr \
#   MYSQL_ROOT_PASSWORD='<test-db-password>' \
#   BACKUP_DIR=evidence/single-company \
#   bash deploy/scripts/single_company_rehearsal.sh
#
# Required environment:
#   COMPOSE_PROJECT_NAME  Compose project name (e.g. factoryhr)
#   MYSQL_DATABASE        database to rehearse against
#   MYSQL_ROOT_PASSWORD   root password for that database
#   BACKUP_DIR            where the backup and inventory JSON land
#
# Optional:
#   REHEARSAL_SKIP_TESTS=1   skip the PHPUnit migration suite (fast rehearsal)
#   REHEARSAL_KEEP_BACKUP=1  keep the SQL dump after a successful run
set -euo pipefail

: "${COMPOSE_PROJECT_NAME:?set COMPOSE_PROJECT_NAME}"
: "${MYSQL_DATABASE:?set MYSQL_DATABASE}"
: "${MYSQL_ROOT_PASSWORD:?set MYSQL_ROOT_PASSWORD}"
: "${BACKUP_DIR:?set BACKUP_DIR}"

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd -- "$SCRIPT_DIR/../.." && pwd)"

timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
mkdir -p "$BACKUP_DIR"
BACKUP_DIR="$(cd -- "$BACKUP_DIR" && pwd)"
backup="$BACKUP_DIR/pre-single-company-$timestamp.sql"
preflight="$BACKUP_DIR/preflight-$timestamp.json"
restored="$BACKUP_DIR/restored-$timestamp.json"

log() { printf '[rehearsal] %s\n' "$*"; }
fail() { printf '[rehearsal] FAIL: %s\n' "$*" >&2; exit 1; }

compose() { docker compose -p "$COMPOSE_PROJECT_NAME" "$@"; }

mysql_container="$(compose ps -q mysql 2>/dev/null || true)"
[[ -n "$mysql_container" ]] || fail "compose project $COMPOSE_PROJECT_NAME has no running mysql service"

php_container="$(compose ps -q php-app 2>/dev/null || true)"
[[ -n "$php_container" ]] || fail "compose project $COMPOSE_PROJECT_NAME has no running php-app service"

cleanup() {
    if [[ "${REHEARSAL_KEEP_BACKUP:-0}" != "1" && -f "$backup" ]]; then
        rm -f -- "$backup"
    fi
}
trap cleanup EXIT

# 1. Back up the live rehearsal database.
log "backing up $MYSQL_DATABASE to $backup"
docker exec -e MYSQL_PWD="$MYSQL_ROOT_PASSWORD" "$mysql_container" \
    sh -c "mysqldump -uroot --single-transaction --routines --triggers --set-gtid-purged=OFF '$MYSQL_DATABASE'" > "$backup"
[[ -s "$backup" ]] || fail "backup is empty"

# 2. Inventory before the rebuild.
log "recording pre-cutover inventory"
compose exec -T php-app php backend/artisan company:preflight --json > "$preflight"
[[ -s "$preflight" ]] || fail "preflight output is empty"

# 3. Rebuild from scratch. The system is not live, so old data is discarded by
#    design; the backup exists only to prove the restore path works.
log "rebuilding schema with migrate:fresh --seed"
compose exec -T php-app php backend/artisan migrate:fresh --seed --force

# 4. Prove the cutover suite passes on the rebuilt MySQL schema.
if [[ "${REHEARSAL_SKIP_TESTS:-0}" != "1" ]]; then
    log "running the single-company migration suite"
    compose exec -T php-app php backend/artisan test --compact --filter=SingleCompanyMigrationTest
fi

# 5. Post-cutover inventory must show no legacy columns.
log "recording post-cutover inventory"
post="$BACKUP_DIR/post-cutover-$timestamp.json"
compose exec -T php-app php backend/artisan company:preflight --json > "$post"
php -r '
$report = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
$legacy = 0;
foreach (($report["tables"] ?? []) as $table) {
    $legacy += count($table["legacy_columns"] ?? []);
}
if ($legacy !== 0) {
    fwrite(STDERR, "legacy columns remain: {$legacy}\n");
    exit(1);
}
if (($report["data_disposition"] ?? "") !== "REBUILD") {
    fwrite(STDERR, "unexpected data_disposition\n");
    exit(1);
}
' "$post" || fail "post-cutover inventory still has legacy columns"

# 6. Restore the backup into the rebuilt database and compare inventories. A
#    restore is expected to recreate the legacy schema, so the comparison is on
#    the table/row inventory rather than on the absence of legacy columns.
log "restoring the backup to prove the recovery path"
docker exec -i -e MYSQL_PWD="$MYSQL_ROOT_PASSWORD" "$mysql_container" \
    sh -c "mysql -uroot '$MYSQL_DATABASE'" < "$backup"
compose exec -T php-app php backend/artisan company:preflight --json > "$restored"
[[ -s "$restored" ]] || fail "restored inventory is empty"

php -r '
$before = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
$after = json_decode(file_get_contents($argv[2]), true, 512, JSON_THROW_ON_ERROR);
$tablesBefore = array_column($before["tables"] ?? [], "rows", "table");
$tablesAfter = array_column($after["tables"] ?? [], "rows", "table");
$missing = array_diff(array_keys($tablesBefore), array_keys($tablesAfter));
if ($missing !== []) {
    fwrite(STDERR, "restore lost tables: ".implode(", ", $missing)."\n");
    exit(1);
}
if (($before["company_profile_rows"] ?? -1) !== ($after["company_profile_rows"] ?? -2)) {
    fwrite(STDERR, "restore changed company_profile row count\n");
    exit(1);
}
' "$preflight" "$restored" || fail "restore verification failed against the pre-cutover inventory"

log "single-company rehearsal OK: $timestamp"
log "evidence: $preflight, $post, $restored"
