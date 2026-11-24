#!/usr/bin/env bash
#
# One-command bring-up of the reference stack on Docker Compose.
#
# Why this exists: `docker compose up` alone leaves the database empty, so the
# API returns 401/500 and the stack looks broken. This script performs the
# steps the compose file cannot express (migrate, seed, verify) and fails loudly
# at the first problem.
#
# Usage: deploy/scripts/docker_up.sh [--reset]
#   --reset   drop and recreate the database before migrating (destroys data)
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/common.sh
source "${SCRIPT_DIR}/lib/common.sh"

COMPOSE_FILE="${REPO_ROOT}/deploy/compose/docker-compose.yml"
RESET=0
[[ "${1:-}" == "--reset" ]] && RESET=1

compose() { docker compose -f "${COMPOSE_FILE}" "$@"; }

require_cmd docker

log "validating compose configuration"
compose config --quiet || die "compose configuration is invalid"

log "building images (first run downloads base images)"
compose build

log "starting the stack"
compose up -d

log "waiting for services to become healthy"
for _ in $(seq 1 60); do
    unhealthy="$(compose ps --format '{{.Name}} {{.Health}}' | grep -v -E 'healthy|^$' || true)"
    if [[ -z "${unhealthy}" ]]; then
        break
    fi
    sleep 5
done

compose ps

if [[ "${RESET}" -eq 1 ]]; then
    log "resetting the database (--reset)"
    compose exec -T mysql mysql -uroot -p"${MYSQL_ROOT_PASSWORD:-change-me-root}" -e \
        "DROP DATABASE IF EXISTS \`${DB_DATABASE:-factoryhr}\`;
         CREATE DATABASE \`${DB_DATABASE:-factoryhr}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
         GRANT ALL ON \`${DB_DATABASE:-factoryhr}\`.* TO '${DB_USERNAME:-factoryhr}'@'%';
         FLUSH PRIVILEGES;"
fi

log "running migrations"
compose exec -T php-app php backend/artisan migrate --force

log "seeding the synthetic demo tenant"
compose exec -T -e DEMO_SEED_PASSWORD="${DEMO_SEED_PASSWORD:?DEMO_SEED_PASSWORD must be set (>=12 chars)}" \
    php-app php backend/artisan iam:sync-capabilities
compose exec -T -e DEMO_SEED_PASSWORD="${DEMO_SEED_PASSWORD}" \
    php-app php backend/artisan iam:seed-demo
compose exec -T php-app php backend/artisan people:seed-demo
compose exec -T php-app php backend/artisan workflow:seed-templates
compose exec -T php-app php backend/artisan talent:seed-demo
compose exec -T php-app php backend/artisan time:seed-leave-types --tenant=1 --annual-days=15.00 || true

log "verifying the deployment"
health="$(curl -fsS "http://127.0.0.1:${HTTP_PORT:-5174}/v1/health" || true)"
if ! grep -q '"status":"ok"' <<<"${health}"; then
    die "health check did not report ok: ${health}"
fi

login_code="$(curl -s -o /dev/null -w '%{http_code}' -X POST \
    "http://127.0.0.1:${HTTP_PORT:-5174}/v1/auth/login" \
    -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -d "{\"username\":\"demo.hr\",\"password\":\"${DEMO_SEED_PASSWORD}\"}")"

if [[ "${login_code}" != "200" ]]; then
    die "demo.hr login failed with HTTP ${login_code}"
fi

pass "stack is up: http://localhost:${HTTP_PORT:-5174}/v1/health (status ok, demo.hr login 200)"
log "this is a reference/demo deployment: synthetic data, placeholder secrets, no TLS"
