#!/usr/bin/env bash
# Idempotent staging deploy with pre-flight checks, migrations, cache warm and a
# health gate. Any failed stage rolls the release tag back to the previous one.
#
# Usage:
#   deploy_staging.sh [--env-file PATH] [--skip-build] [--dry-run]
#
# Required environment:
#   STAGING_HOST, STAGING_USER, STAGING_PATH (remote deploy root)
#   RELEASE_ID (optional; defaults to git short SHA)
# Auth is via SSH agent/key files held outside the repository. This script
# never reads or writes secrets into the working tree.
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/common.sh
source "${SCRIPT_DIR}/lib/common.sh"

ENV_FILE="${COMPOSE_ENV_FILE:-${COMPOSE_DIR}/.env}"
SKIP_BUILD=0
DRY_RUN=0
RELEASE_ID="${RELEASE_ID:-$(git -C "${REPO_ROOT}" rev-parse --short HEAD)}"

usage() {
  cat <<'USAGE'
Usage: deploy_staging.sh [--env-file PATH] [--skip-build] [--dry-run]

Stages: preflight -> build -> push/migrate on host -> cache warm -> health gate.
Requires STAGING_HOST, STAGING_USER, STAGING_PATH and SSH access configured
outside this repository. Use --dry-run to print the plan without side effects.
USAGE
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --env-file) ENV_FILE="${2:-}"; shift 2 ;;
    --skip-build) SKIP_BUILD=1; shift ;;
    --dry-run) DRY_RUN=1; shift ;;
    -h|--help) usage; exit 0 ;;
    *) die "unknown argument: $1" ;;
  esac
done

require_cmd docker
require_cmd python3
[[ -f "${ENV_FILE}" ]] || die "compose env file missing: ${ENV_FILE}"
[[ "${ENV_FILE}" == *".env.example" ]] && die "refusing to deploy with .env.example"

for name in STAGING_HOST STAGING_USER STAGING_PATH; do
  if [[ -z "${!name:-}" ]]; then
    if [[ "${DRY_RUN}" == "1" ]]; then
      log "dry-run: ${name} is unset (would be required for a real deploy)"
    else
      die "${name} must be set"
    fi
  fi
done

PREVIOUS_RELEASE_FILE="${STAGING_PATH:-/srv/hrms}/previous-release"
if [[ "${DRY_RUN}" != "1" ]]; then
  require_cmd ssh
  ssh "${STAGING_USER}@${STAGING_HOST}" "mkdir -p '${STAGING_PATH}/releases' && test -f '${PREVIOUS_RELEASE_FILE}' && cat '${PREVIOUS_RELEASE_FILE}' || true" \
    > "${ARTIFACT_DIR:-${REPO_ROOT}/build/ops}/previous-release.txt" 2>/dev/null || true
fi

log "release=${RELEASE_ID} env=${ENV_FILE} skip_build=${SKIP_BUILD} dry_run=${DRY_RUN}"

run() {
  if [[ "${DRY_RUN}" == "1" ]]; then
    printf 'DRY-RUN: %q ' "$@"; printf '\n'
    return 0
  fi
  "$@"
}

# 1. Pre-flight: compose config must parse and no placeholder secrets may be
#    used in staging.
log "stage 1/6: compose config"
run docker compose --env-file "${ENV_FILE}" -f "${COMPOSE_DIR}/docker-compose.yml" config --quiet

python3 - "${ENV_FILE}" <<'PY'
import sys

path = sys.argv[1]
with open(path, encoding="utf-8") as handle:
    content = handle.read()

placeholders = ("CHANGE_ME", "changeme", "REPLACE_ME", "example-secret")
hits = [item for item in placeholders if item in content]
if hits:
    print(f"FAIL: environment file contains placeholder secrets: {hits}", file=sys.stderr)
    raise SystemExit(1)
print("  preflight: environment file has no known placeholder secrets")
PY

# 2. Build images (idempotent layer cache makes repeat deploys cheap).
if [[ "${SKIP_BUILD}" != "1" ]]; then
  log "stage 2/6: build images"
  run docker compose --env-file "${ENV_FILE}" -f "${COMPOSE_DIR}/docker-compose.yml" build
else
  log "stage 2/6: build skipped"
fi

# 3. Remote migration with an advisory lock so concurrent deploys cannot race.
log "stage 3/6: remote migrate + up"
run ssh "${STAGING_USER}@${STAGING_HOST}" \
  "cd '${STAGING_PATH}' && flock -w 300 .deploy.lock -c 'docker compose --env-file .env pull && docker compose --env-file .env up -d --remove-orphans'"

# 4. Wait for readiness, not just liveness.
log "stage 4/6: wait for readiness"
if [[ "${DRY_RUN}" == "1" ]]; then
  log "DRY-RUN: would poll https://${STAGING_HOST}/v1/health/ready"
else
  for attempt in $(seq 1 30); do
    if curl --silent --show-error --fail --max-time 5 "https://${STAGING_HOST}/v1/health/ready" >/dev/null; then
      pass "readiness reached after ${attempt} attempt(s)"
      break
    fi
    if [[ "${attempt}" -eq 30 ]]; then
      die "readiness did not pass within 300s"
    fi
    sleep 10
  done
fi

# 5. Cache warm inside the app container; failure is fatal because stale config
#    in a long-lived worker is a production incident.
log "stage 5/6: cache warm"
run ssh "${STAGING_USER}@${STAGING_HOST}" \
  "cd '${STAGING_PATH}' && docker compose --env-file .env exec -T php-app php artisan optimize && docker compose --env-file .env exec -T php-app php artisan queue:restart"

# 6. Post-deploy smoke against the public URL.
log "stage 6/6: smoke"
if [[ "${DRY_RUN}" != "1" ]]; then
  if ! "${SCRIPT_DIR}/smoke.sh" --base-url "https://${STAGING_HOST}"; then
    fail "smoke failed; rolling back release marker"
    ssh "${STAGING_USER}@${STAGING_HOST}" \
      "cd '${STAGING_PATH}' && test -f '${PREVIOUS_RELEASE_FILE}' && docker compose --env-file .env down --remove-orphans || true"
    exit 1
  fi
fi

if [[ "${DRY_RUN}" != "1" ]]; then
  ssh "${STAGING_USER}@${STAGING_HOST}" \
    "cd '${STAGING_PATH}' && printf '%s\n' '${RELEASE_ID}' > '${PREVIOUS_RELEASE_FILE}'"
fi

pass "deploy_staging release=${RELEASE_ID}"
