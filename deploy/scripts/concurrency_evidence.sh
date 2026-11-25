#!/usr/bin/env bash
#
# V1.1 concurrency, idempotency and state-machine evidence.
#
# These groups prove that duplicate submissions cannot double-apply money,
# leave balances, approvals or onboarding effects. The tests already exist;
# this script produces one traceable evidence log for acceptance.
set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd -- "$SCRIPT_DIR/../.." && pwd)"
PHP_BIN="${PHP_BIN:-$REPO_ROOT/.tools/php/php}"
[[ -x "$PHP_BIN" ]] || PHP_BIN="$(command -v php)"

mkdir -p "$REPO_ROOT/evidence"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
LOG="$REPO_ROOT/evidence/v11-concurrency-idempotency-$STAMP.log"
: >"$LOG"

cd "$REPO_ROOT/backend"

run_filter() {
  local label="$1" filter="$2"
  printf '\n### %s (%s)\n' "$label" "$filter" | tee -a "$LOG"
  if "$PHP_BIN" artisan test --compact --filter="$filter" >>"$LOG" 2>&1; then
    printf 'PASS: %s\n' "$label" | tee -a "$LOG"
  else
    printf 'FAIL: %s\n' "$label" | tee -a "$LOG" >&2
    exit 1
  fi
}

run_filter "concurrent approval decisions" 'ConcurrentApprovalTest'
run_filter "idempotency replay and conflict" 'IdempotencyServiceTest|IamCommandsTest'
run_filter "onboarding effect deduplication" 'OnboardingDedupTest|OnboardingEffectTest'
run_filter "overtime single settlement" 'OvertimeSettlementTest'
run_filter "training enrollment idempotency" 'TrainingEnrollmentTest|TalentRedLinesTest'
run_filter "attendance closing lock" 'AttendanceClosingTest'
run_filter "payroll state machine" 'PayrollHttpLifecycleTest|PayrollInputsApiTest'

printf '\nALL CONCURRENCY/IDEMPOTENCY GROUPS PASSED. LOG: %s\n' "$LOG" | tee -a "$LOG"
