#!/usr/bin/env bash
# Post-deploy smoke: liveness, readiness, legacy health, unauthenticated denial,
# feature-gate denial, and an idempotent read. Non-zero on any failure.
#
# Usage: smoke.sh [--base-url URL] [--expect-tenant N] [--insecure]
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/common.sh
source "${SCRIPT_DIR}/lib/common.sh"

BASE_URL="${SMOKE_BASE_URL:-http://127.0.0.1:8080}"
EXPECT_COMMIT="${SMOKE_EXPECT_COMMIT:-}"
INSECURE=0

usage() {
  cat <<'USAGE'
Usage: smoke.sh [--base-url URL] [--insecure]

Checks:
  1. GET /v1/health/live   => 200 code=OK (no dependencies)
  2. GET /v1/health/ready  => 200 code=OK (db/cache/storage)
  3. GET /v1/health        => 200 code=OK
  4. GET /v1/meta          => 200 code=OK twice (idempotent read)
  5. GET /v1/platform/features without credentials => 403 FORBIDDEN
  6. GET /v1/meta?password=... must not echo the secret
  7. /v1/meta exposes commit/build_time/spa_build_sha; optional --expect-commit must match
USAGE
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --base-url) BASE_URL="${2:-}"; shift 2 ;;
    --expect-commit) EXPECT_COMMIT="${2:-}"; shift 2 ;;
    --insecure) INSECURE=1; shift ;;
    -h|--help) usage; exit 0 ;;
    *) die "unknown argument: $1" ;;
  esac
done

require_cmd curl
BASE_URL="${BASE_URL%/}"
CURL=(curl --silent --show-error --max-time 15)
[[ "${INSECURE}" == "1" ]] && CURL+=(--insecure)
TMP_DIR="$(mktemp -d "${TMPDIR:-/tmp}/hrms-smoke.XXXXXX")"
trap 'rm -rf "${TMP_DIR}"' EXIT

request() {
  local name="$1" path="$2" expected_status="$3"
  local body_file="${TMP_DIR}/${name}.json"
  local status
  status="$("${CURL[@]}" --output "${body_file}" --write-out '%{http_code}' "${BASE_URL}${path}")"

  if [[ "${status}" != "${expected_status}" ]]; then
    fail "${name}: expected HTTP ${expected_status}, got ${status} (${path})"
    cat "${body_file}" >&2 || true
    return 1
  fi

  if ! python3 - "${name}" "${body_file}" <<'PY'
import json
import sys

name, path = sys.argv[1], sys.argv[2]
with open(path, encoding="utf-8") as handle:
    payload = json.load(handle)
if not isinstance(payload.get("code"), str) or not payload.get("request_id"):
    print(f"FAIL: {name}: response is missing code/request_id envelope", file=sys.stderr)
    raise SystemExit(1)
print(f"  {name}: code={payload['code']} request_id={payload['request_id']}", file=sys.stderr)
PY
  then
    return 1
  fi

  printf '%s' "${body_file}"
}

log "base_url=${BASE_URL}"
LIVE="$(request live /v1/health/live 200)" || exit 1
READY="$(request ready /v1/health/ready 200)" || exit 1
HEALTH="$(request health /v1/health 200)" || exit 1

META_A="$(request meta-a /v1/meta 200)" || exit 1
META_B="$(request meta-b /v1/meta 200)" || exit 1
python3 - "${META_A}" "${META_B}" <<'PY'
import json
import sys

with open(sys.argv[1], encoding="utf-8") as first, open(sys.argv[2], encoding="utf-8") as second:
    a, b = json.load(first)["data"], json.load(second)["data"]
volatile = {"server_time"}
if {k: v for k, v in a.items() if k not in volatile} != {k: v for k, v in b.items() if k not in volatile}:
    print("FAIL: repeated /v1/meta reads returned different stable data", file=sys.stderr)
    raise SystemExit(1)
for field in ("commit", "build_time", "spa_build_sha"):
    if not isinstance(a.get(field), str) or not a.get(field):
        print(f"FAIL: /v1/meta missing {field}", file=sys.stderr)
        raise SystemExit(1)
print("  idempotent read: /v1/meta stable fields identical on repeat")
PY

if [[ -n "${EXPECT_COMMIT}" ]]; then
  META_COMMIT="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1], encoding="utf-8"))["data"]["commit"])' "${META_A}")"
  if [[ "${META_COMMIT}" != "${EXPECT_COMMIT}" ]]; then
    fail "release commit mismatch: meta=${META_COMMIT} expected=${EXPECT_COMMIT}"
    exit 1
  fi
  pass "release commit matches ${EXPECT_COMMIT}"
fi

DENIED_STATUS="$("${CURL[@]}" --output "${TMP_DIR}/denied.json" --write-out '%{http_code}' "${BASE_URL}/v1/platform/features")"
if [[ "${DENIED_STATUS}" != "401" && "${DENIED_STATUS}" != "403" ]]; then
  fail "denied read: expected 401/403, got ${DENIED_STATUS}"
  cat "${TMP_DIR}/denied.json" >&2 || true
  exit 1
fi
python3 - "${TMP_DIR}/denied.json" "${DENIED_STATUS}" <<'PY'
import json
import sys

with open(sys.argv[1], encoding="utf-8") as handle:
    payload = json.load(handle)
if payload.get("code") not in {"FORBIDDEN", "UNAUTHENTICATED"}:
    print("FAIL: denied read did not return a stable auth code", file=sys.stderr)
    raise SystemExit(1)
if payload["data"].get("password") or payload["data"].get("token"):
    print("FAIL: denied response echoes credentials", file=sys.stderr)
    raise SystemExit(1)
print(f"  authorized denial: code={payload['code']} status={sys.argv[2]}")
PY

SECRET="SuperSecret-$(date -u +%s)"
SECRET_BODY="$(request secret /v1/meta?password=${SECRET} 200)" || exit 1
if grep -q "${SECRET}" "${SECRET_BODY}"; then
  fail "secret echo: response contained the supplied password"
  exit 1
fi
pass "secret not echoed by /v1/meta"

FEATURE_BODY="${TMP_DIR}/feature.json"
FEATURE_STATUS="$("${CURL[@]}" --output "${FEATURE_BODY}" --write-out '%{http_code}' "${BASE_URL}/v1/platform/features")"
if [[ "${FEATURE_STATUS}" != "401" && "${FEATURE_STATUS}" != "403" ]]; then
  fail "feature route: expected unauthenticated denial, got ${FEATURE_STATUS}"
  exit 1
fi
pass "feature route unauthenticated=${FEATURE_STATUS}"

pass "smoke base_url=${BASE_URL} checks=7"
