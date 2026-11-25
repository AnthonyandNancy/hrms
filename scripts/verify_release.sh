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
  run_gate "mfa enrollment invariant" php artisan iam:verify-mfa-enrollment
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
  run_gate "frontend build" env VITE_API_MODE=http pnpm build
  run_gate "no demo wording" bash -c "! grep -rIn --exclude-dir=node_modules --exclude-dir=dist --exclude-dir=mocks -E '参考实现|界面规范|v0\.1|DEMO-0000|演示验证码|任意 6 位|246810|演示账号' src"
  run_gate "no mock data in dist" bash -c "! grep -rIl -E 'demo\.example|createMockAdapter' dist"
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

section "Cutover migration wrappers (E10)"
for wrapper in migrate_organizations migrate_people migrate_leave_opening migrate_payroll_ytd; do
  if [ -f "$ROOT/deploy/scripts/$wrapper.php" ]; then
    run_gate "$wrapper validate" php "$ROOT/deploy/scripts/$wrapper.php"
  else
    skip_gate "$wrapper" "missing"
  fi
done

section "Payroll correctness (E-11, needs real calculated runs)"
# Reuses the demo database. Skipped honestly when no calculated run exists, so a
# clean checkout never reports a fabricated pass.
if [ -f "$ROOT/backend/artisan" ]; then
  cd "$ROOT/backend" || exit 1
  # status is cast to a PHP enum, so compare in PHP after loading rather than
  # relying on a SQL whereIn over raw strings.
  has_run=$(php artisan tinker --execute="
    echo \App\Modules\Payroll\Models\PayrollRun::query()->get()->contains(fn(\$r) => in_array(\$r->status->value, ['CALCULATED','REVIEWED','APPROVED','PUBLISHED','PAID','CLOSED'], true)) ? 'yes' : 'no';
  " 2>/dev/null | tail -1 | tr -d '[:space:]')
  if [ "$has_run" = "yes" ]; then
    run_gate "payroll reconciliation" php artisan payroll:reconciliation
    period=$(php -r '
      require "vendor/autoload.php";
      $app = require "bootstrap/app.php";
      $app->make("Illuminate\Contracts\Console\Kernel")->bootstrap();
      echo App\Modules\Payroll\Models\PayrollRun::query()->orderBy("id")->value("period");
    ' 2>/dev/null)
    if [ -n "$period" ]; then
      run_gate "payroll old-system export" php "$ROOT/scripts/payroll_old_export.php" --period="$period"
      php "$ROOT/scripts/payroll_old_export.php" --period="$period" > /tmp/hrms-payroll-old.csv 2>/dev/null
      run_gate "payroll parallel check" php artisan payroll:parallel-check --period="$period" --old-file=/tmp/hrms-payroll-old.csv
    else
      skip_gate "payroll correctness" "could not determine a calculated payroll period"
    fi
  else
    skip_gate "payroll correctness" "no calculated payroll run in the demo database"
  fi
else
  skip_gate "payroll correctness" "backend/artisan missing"
fi

section "V1.1 acceptance evidence"
if [[ "${1:-}" != "--quick" || "${V11_REAL_STACK:-0}" == "1" ]]; then
  if [[ -n "${HRMS_DEMO_PASSWORD:-}" ]]; then
    run_gate "authorization negative evidence" bash "$ROOT/deploy/scripts/authorization_negative_evidence.sh"
    run_gate "concurrency idempotency evidence" bash "$ROOT/deploy/scripts/concurrency_evidence.sh"
    run_gate "V1.1 real stack acceptance" bash "$ROOT/deploy/scripts/verify_real_stack.sh"
  else
    skip_gate "V1.1 real stack acceptance" "HRMS_DEMO_PASSWORD is not set (required for real login)"
  fi
  run_gate "route coverage release gate" node "$ROOT/scripts/audit_route_coverage.mjs" --require-mature
else
  skip_gate "V1.1 acceptance evidence" "--quick without V11_REAL_STACK=1"
fi

section "Summary"
printf 'PASS=%d FAIL=%d SKIP=%d\n' "$PASS" "$FAIL" "$SKIP" | tee -a "$LOG"
if [ "$FAIL" -gt 0 ]; then
  printf 'Failed gates:\n' | tee -a "$LOG"
  printf ' - %s\n' "${FAILED_GATES[@]}" | tee -a "$LOG"
  printf 'LOG: %s\n' "$LOG"
  exit 1
fi
printf 'ALL EXECUTED GATES PASSED. LOG: %s\n' "$LOG"
