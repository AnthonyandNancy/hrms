#!/usr/bin/env bash
#
# V1.1 authorization negative-test evidence.
#
# Runs the pre-existing cross-anchor, cross-legal-entity, field-denial, MFA,
# session-revocation and superuser-payroll groups and writes one auditable log.
# It does not redefine those tests; a failure here means the denial matrix
# regressed, not that evidence formatting broke.
set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd -- "$SCRIPT_DIR/../.." && pwd)"
PHP_BIN="${PHP_BIN:-$REPO_ROOT/.tools/php/php}"
[[ -x "$PHP_BIN" ]] || PHP_BIN="$(command -v php)"

mkdir -p "$REPO_ROOT/evidence"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
LOG="$REPO_ROOT/evidence/v11-authorization-negative-$STAMP.log"
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

run_filter "anchor and scope denial" 'AnchorScopeTest|AuthorizationScopeTest|EmploymentScopeColumnTest'
run_filter "field-level denial" 'FieldDenialHttpTest|FieldProjectionTest|PayrollFieldProjectionTest'
run_filter "cross-legal-entity isolation" 'CrossLegalEntityTest'
run_filter "superuser full authority and P3 audit" 'SuperuserFullAuthorityTest'
run_filter "MFA and session revocation" 'MfaEnrollmentInvariantTest|MfaChallengeTest|SessionRevocationTest|AuthorizationSnapshotReplayTest'
run_filter "no active employment contract" 'NoActiveEmploymentTest'

printf '\nALL AUTHORIZATION NEGATIVE GROUPS PASSED. LOG: %s\n' "$LOG" | tee -a "$LOG"
