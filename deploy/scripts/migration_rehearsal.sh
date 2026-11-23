#!/usr/bin/env bash
# Validate, apply (shadow), reconcile and summarise a full demo migration rehearsal.
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/common.sh
source "${SCRIPT_DIR}/lib/common.sh"

ROUND=""
DATASET="${DATASET:-demo}"
TENANT="${TENANT:-1}"
KEEP_DB="${KEEP_DB:-0}"
MAPPING="${MAPPING:-${BACKEND_DIR}/database/migration-fixtures/mapping/demo.csv}"

usage() {
  cat <<'USAGE'
Usage: migration_rehearsal.sh [--round N] [--dataset demo|known-good|DIR] [--tenant N] [--keep-db]

Runs every migration domain against an isolated SQLite rehearsal database using
the SHADOW target, then reconciles source counts/sums against shadow metrics.
Exit 0 only when all domains validate, apply and reconcile cleanly.
USAGE
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --round) ROUND="${2:-}"; shift 2 ;;
    --dataset) DATASET="${2:-}"; shift 2 ;;
    --tenant) TENANT="${2:-}"; shift 2 ;;
    --keep-db) KEEP_DB=1; shift ;;
    -h|--help) usage; exit 0 ;;
    *) die "unknown argument: $1" ;;
  esac
done

require_cmd sha256sum
PHP_BIN="$(resolve_php)"
ensure_artifact_dir
if [[ -z "${ROUND}" ]]; then
  ROUND="$(date -u +%Y%m%d%H%M%S)"
fi
DB_PATH="${ARTIFACT_DIR}/rehearsal-${ROUND}.sqlite"
REPORT="${ARTIFACT_DIR}/rehearsal-${ROUND}.json"
rm -f "${DB_PATH}"

cleanup() {
  if [[ "${KEEP_DB}" != "1" ]]; then
    rm -f "${DB_PATH}"
  fi
}
trap cleanup EXIT

log "rehearsal round=${ROUND} dataset=${DATASET} db=${DB_PATH}"

db_env() {
  DB_CONNECTION=sqlite DB_DATABASE="${DB_PATH}" CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync "$@"
}

db_env "${PHP_BIN}" "${BACKEND_DIR}/artisan" migrate --force --no-interaction >/dev/null

if ! db_env "${PHP_BIN}" "${BACKEND_DIR}/artisan" migrate:rehearsal \
  --round="${ROUND}" \
  --dataset="${DATASET}" \
  --tenant="${TENANT}" \
  --mapping="${MAPPING}" \
  --output="${REPORT}"; then
  fail "migration rehearsal failed; report (if written): ${REPORT}"
  exit 1
fi

python3 - "${REPORT}" <<'PY'
import json
import sys

path = sys.argv[1]
with open(path, encoding="utf-8") as handle:
    report = json.load(handle)

if not report.get("passed"):
    print("FAIL: rehearsal report did not pass", file=sys.stderr)
    raise SystemExit(1)

for domain, row in report["domains"].items():
    recon = row.get("reconciliation") or {}
    if not recon.get("passed"):
        print(f"FAIL: {domain} reconciliation did not pass", file=sys.stderr)
        raise SystemExit(1)
    print(
        f"  {domain}: status={row['status']} read={row['rows_read']} "
        f"created={row['rows_created']} skipped={row['rows_skipped']} reconcile=PASS"
    )

print(f"  target={report['target']} domains={len(report['domains'])}")
PY

CHECKSUM="$(sha256_file "${REPORT}")"
pass "migration_rehearsal round=${ROUND} domains=6 target=SHADOW report=${REPORT} sha256=${CHECKSUM}"
