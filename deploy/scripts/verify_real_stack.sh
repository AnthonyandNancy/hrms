#!/usr/bin/env bash
#
# V1.1 real-stack acceptance gate.
#
# Runs against a running Compose stack (default http://127.0.0.1:5184) and
# writes every result to evidence/v11-real-stack-<timestamp>.log. Unlike the
# local verify_release.sh, this proves release SHA traceability, live login,
# the 160-page route audit, semantic 503 handling and MySQL backup/restore.
#
# Usage:
#   HRMS_DEMO_PASSWORD=... deploy/scripts/verify_real_stack.sh [--base-url URL] [--project NAME]
set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd -- "$SCRIPT_DIR/../.." && pwd)"
BASE_URL="${HRMS_FRONTEND_URL:-http://127.0.0.1:5184}"
PROJECT="${COMPOSE_PROJECT_NAME:-factoryhrv11}"
FAIL=0
FAILED_GATES=()

usage() {
  cat <<'USAGE'
Usage: verify_real_stack.sh [--base-url URL] [--project NAME]

Requires HRMS_DEMO_PASSWORD (the password used when seeding demo users).
Writes evidence/v11-real-stack-<timestamp>.log; exits non-zero on any failure.
USAGE
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --base-url) BASE_URL="${2:-}"; shift 2 ;;
    --project) PROJECT="${2:-}"; shift 2 ;;
    -h|--help) usage; exit 0 ;;
    *) echo "unknown argument: $1" >&2; exit 2 ;;
  esac
done

mkdir -p "$REPO_ROOT/evidence"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
LOG="$REPO_ROOT/evidence/v11-real-stack-$STAMP.log"
: >"$LOG"

section() { printf '\n######## %s ########\n' "$1" | tee -a "$LOG"; }
pass() { printf 'PASS: %s\n' "$1" | tee -a "$LOG"; }
fail() { printf 'FAIL: %s\n' "$1" | tee -a "$LOG" >&2; FAIL=$((FAIL + 1)); FAILED_GATES+=("$1"); }
skip() { printf 'SKIP: %s\n' "$1" | tee -a "$LOG"; }

run_gate() {
  local name="$1"; shift
  printf '\n--- %s ---\n' "$name" | tee -a "$LOG"
  if "$@" >>"$LOG" 2>&1; then
    pass "$name"
  else
    fail "$name"
  fi
}

if [[ -z "${HRMS_DEMO_PASSWORD:-}" ]]; then
  echo "FAIL: HRMS_DEMO_PASSWORD is required" | tee -a "$LOG" >&2
  exit 2
fi

section "1. Stack readiness"
if curl -fsS --max-time 10 "$BASE_URL/v1/health/ready" >/dev/null 2>&1; then
  pass "readiness endpoint"
else
  fail "readiness endpoint"
fi

section "2. Release traceability"
run_gate "release traceability" node "$REPO_ROOT/scripts/check_release_traceability.mjs" --base-url "$BASE_URL"
run_gate "post-deploy smoke" bash "$REPO_ROOT/deploy/scripts/smoke.sh" --base-url "$BASE_URL"

section "3. Real login and permission negative tests"
LOGIN_BODY="$(mktemp)"; COOKIE_JAR="$(mktemp)"
trap 'rm -f "$LOGIN_BODY" "$COOKIE_JAR"' EXIT

if curl -fsS --max-time 15 -c "$COOKIE_JAR" -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -d "{\"username\":\"${HRMS_DEMO_USERNAME:-demo.hr}\",\"password\":\"${HRMS_DEMO_PASSWORD}\"}" \
  "$BASE_URL/v1/auth/login" >"$LOGIN_BODY" 2>>"$LOG"; then
  pass "demo login"
else
  fail "demo login"
fi

ME_STATUS="$(curl -sS --max-time 10 -o /dev/null -w '%{http_code}' -b "$COOKIE_JAR" "$BASE_URL/v1/me")"
if [[ "$ME_STATUS" == "200" ]]; then pass "/v1/me authenticated"; else fail "/v1/me authenticated (got $ME_STATUS)"; fi

DENIED_STATUS="$(curl -sS --max-time 10 -o /dev/null -w '%{http_code}' "$BASE_URL/v1/platform/features")"
if [[ "$DENIED_STATUS" == "401" || "$DENIED_STATUS" == "403" ]]; then
  pass "unauthenticated platform read denied ($DENIED_STATUS)"
else
  fail "unauthenticated platform read denied (got $DENIED_STATUS)"
fi

section "4. 160-page real-stack route audit"
if [[ -d "$REPO_ROOT/frontend/tests/e2e/real" ]]; then
  (
    cd "$REPO_ROOT/frontend"
    HRMS_DEMO_PASSWORD="$HRMS_DEMO_PASSWORD" \
    HRMS_DEMO_USERNAME="${HRMS_DEMO_USERNAME:-demo.hr}" \
    HRMS_FRONTEND_URL="$BASE_URL" \
    HRMS_BACKEND_URL="$BASE_URL" \
    pnpm exec playwright test -c playwright.real.config.ts tests/e2e/real/registry-audit.spec.ts --reporter=line
  ) >>"$LOG" 2>&1 && pass "160-page registry audit" || fail "160-page registry audit"
else
  skip "160-page registry audit (real e2e directory missing)"
fi

section "5. MySQL backup/restore"
run_gate "mysql backup/restore" bash "$REPO_ROOT/deploy/scripts/mysql_backup_restore.sh" --project "$PROJECT" --database factoryhr

section "Summary"
printf 'FAIL=%d\n' "$FAIL" | tee -a "$LOG"
if [[ "$FAIL" -gt 0 ]]; then
  printf 'Failed gates:\n' | tee -a "$LOG"
  printf ' - %s\n' "${FAILED_GATES[@]}" | tee -a "$LOG"
  printf 'LOG: %s\n' "$LOG"
  exit 1
fi
printf 'ALL REAL-STACK GATES PASSED. LOG: %s\n' "$LOG" | tee -a "$LOG"
