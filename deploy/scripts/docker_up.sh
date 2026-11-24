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
DEMO=0
for arg in "$@"; do
    case "${arg}" in
        --reset) RESET=1 ;;
        --demo) DEMO=1 ;;
        *) die "unknown argument: ${arg}" ;;
    esac
done

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

if [[ "${DEMO}" -eq 1 ]]; then
    log "installing the demo baseline plus the synthetic dataset"
    : "${DEMO_SEED_PASSWORD:?DEMO_SEED_PASSWORD must be set (>=12 chars)}"

    compose exec -T php-app php backend/artisan hrms:install \
        --legal-entity-code=DEMO-CN \
        --legal-entity-name="Demo China Co Ltd" \
        --country=CN --timezone=Asia/Shanghai --currency=CNY \
        --admin-username=demo.admin --admin-email=demo.admin@example.test \
        --admin-password="${DEMO_SEED_PASSWORD}" \
        --annual-days=15.00 --legal-basis="Synthetic demo policy, not legal advice" \
        --enable=payroll,time,self_service
    compose exec -T php-app php backend/artisan people:seed-demo
    compose exec -T php-app php backend/artisan talent:seed-demo
else
    log "installing the production reference baseline"
    : "${ADMIN_PASSWORD:?ADMIN_PASSWORD must be set (>=12 chars)}"
    : "${LEGAL_ENTITY_CODE:?LEGAL_ENTITY_CODE must be set}"
    : "${LEGAL_ENTITY_NAME:?LEGAL_ENTITY_NAME must be set}"
    : "${ADMIN_USERNAME:?ADMIN_USERNAME must be set}"
    : "${ADMIN_EMAIL:?ADMIN_EMAIL must be set}"
    : "${ANNUAL_DAYS:?ANNUAL_DAYS must be set (statutory entitlement is a legal decision)}"
    : "${LEGAL_BASIS:?LEGAL_BASIS must be set}"

    compose exec -T php-app php backend/artisan hrms:install \
        --legal-entity-code="${LEGAL_ENTITY_CODE}" \
        --legal-entity-name="${LEGAL_ENTITY_NAME}" \
        --country="${COUNTRY:-CN}" \
        --timezone="${TIMEZONE:-Asia/Shanghai}" \
        --currency="${CURRENCY:-CNY}" \
        --admin-username="${ADMIN_USERNAME}" \
        --admin-email="${ADMIN_EMAIL}" \
        --admin-password="${ADMIN_PASSWORD}" \
        --annual-days="${ANNUAL_DAYS}" \
        --legal-basis="${LEGAL_BASIS}" \
        ${HOLIDAYS_FILE:+--holidays-file="${HOLIDAYS_FILE}"} \
        ${ENABLE_FLAGS:+--enable="${ENABLE_FLAGS}"} \
        ${APPROVED_BY:+--approved-by="${APPROVED_BY}"} \
        ${PAYROLL_YEAR:+--payroll-year="${PAYROLL_YEAR}"}
fi

log "verifying the deployment"
health="$(curl -fsS "http://127.0.0.1:${HTTP_PORT:-5174}/v1/health" || true)"
if ! grep -q '"status":"ok"' <<<"${health}"; then
    die "health check did not report ok: ${health}"
fi

if [[ "${DEMO}" -eq 1 ]]; then
    login_code="$(curl -s -o /dev/null -w '%{http_code}' -X POST \
        "http://127.0.0.1:${HTTP_PORT:-5174}/v1/auth/login" \
        -H 'Content-Type: application/json' -H 'Accept: application/json' \
        -d "{\"username\":\"demo.admin\",\"password\":\"${DEMO_SEED_PASSWORD}\"}")"

    if [[ "${login_code}" != "202" && "${login_code}" != "200" ]]; then
        die "demo.admin login failed with HTTP ${login_code}"
    fi
fi

pass "stack is up: http://localhost:${HTTP_PORT:-5174}/v1/health (status ok)"
log "deployment complete: TLS termination and secret management are environment responsibilities"
