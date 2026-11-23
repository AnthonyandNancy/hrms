#!/usr/bin/env bash
set -uo pipefail
# HRMS reference implementation release gate runner.
# Usage: scripts/verify_release.sh [--quick]
# Writes a timestamped log under evidence/logs and exits non-zero on any failed gate.

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
export PATH="$ROOT/.tools/php:$PATH"
LOG_DIR="$ROOT/evidence/logs"
mkdir -p "$LOG_DIR"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
LOG="$LOG_DIR/verify-$STAMP.log"
: > "$LOG"

PASS=0; FAIL=0; SKIP=0
declare -a FAILED_GATES=()

run_gate() {
  local name="$1"; shift
  printf '\n===== GATE: %s =====\n' "$name" | tee -a "$LOG"
  printf '$ %s\n' "$*" | tee -a "$LOG"
  if "$@" >>"$LOG" 2>&1; then
    printf 'RESULT: PASS - %s\n' "$name" | tee -a "$LOG"
    PASS=$((PASS+1))
  else
    local code=$?
    printf 'RESULT: FAIL (%s) - %s\n' "$code" "$name" | tee -a "$LOG"
    FAIL=$((FAIL+1)); FAILED_GATES+=("$name")
  fi
}

skip_gate() {
  printf 'RESULT: SKIP - %s (%s)\n' "$1" "$2" | tee -a "$LOG"
  SKIP=$((SKIP+1))
}

section() { printf '\n######## %s ########\n' "$1" | tee -a "$LOG"; }

section "Backend"
if [ -d "$ROOT/backend" ]; then
  cd "$ROOT/backend" || exit 1
  run_gate "php syntax" bash -c "find app database routes tests -name '*.php' -print0 | xargs -0 -n1 php -l"
  run_gate "backend tests" php artisan test
  run_gate "pint" vendor/bin/pint --test
  run_gate "phpstan" php -d memory_limit=1G vendor/bin/phpstan analyse --no-progress
  run_gate "capability audit" php artisan capability:audit
  run_gate "arch test" php artisan arch:test
  run_gate "openapi contract" bash -c "[ -f tests/Contract/OpenApiContractTest.php ] && php artisan test --filter=OpenApiContractTest || exit 2"
else
  skip_gate "backend" "backend directory missing"
fi

section "Frontend"
if [ -d "$ROOT/frontend" ] && [ -f "$ROOT/frontend/package.json" ]; then
  cd "$ROOT/frontend" || exit 1
  run_gate "frontend typecheck" pnpm typecheck
  run_gate "frontend lint" pnpm lint
  run_gate "frontend unit" pnpm test:unit -- --run
  run_gate "frontend build" pnpm build
  if [ "${1:-}" != "--quick" ]; then
    run_gate "frontend e2e mock" pnpm test:e2e
  else
    skip_gate "frontend e2e" "--quick"
  fi
else
  skip_gate "frontend" "frontend not present"
fi

section "Contract"
cd "$ROOT" || exit 1
if [ -f "$ROOT/deploy/scripts/openapi-lint.mjs" ]; then
  run_gate "openapi lint" node deploy/scripts/openapi-lint.mjs
else
  skip_gate "openapi lint" "deploy/scripts/openapi-lint.mjs missing"
fi

section "Operations scripts"
for script in deploy/scripts/backup_restore_smoke.sh deploy/scripts/migration_rehearsal.sh deploy/scripts/rollback_drill.sh; do
  if [ -f "$ROOT/$script" ]; then
    run_gate "$(basename "$script")" bash "$ROOT/$script"
  else
    skip_gate "$(basename "$script")" "missing"
  fi
done

section "Summary"
printf 'PASS=%d FAIL=%d SKIP=%d\n' "$PASS" "$FAIL" "$SKIP" | tee -a "$LOG"
if [ "$FAIL" -gt 0 ]; then
  printf 'Failed gates:\n' | tee -a "$LOG"
  printf ' - %s\n' "${FAILED_GATES[@]}" | tee -a "$LOG"
  printf 'LOG: %s\n' "$LOG"
  exit 1
fi
printf 'ALL EXECUTED GATES PASSED. LOG: %s\n' "$LOG"
