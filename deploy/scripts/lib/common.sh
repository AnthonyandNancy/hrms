#!/usr/bin/env bash
# Shared helpers for deployment scripts. Source, do not execute directly.
set -euo pipefail

readonly SCRIPT_LIB_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
readonly REPO_ROOT="$(cd "${SCRIPT_LIB_DIR}/../../.." && pwd)"
readonly BACKEND_DIR="${REPO_ROOT}/backend"
readonly COMPOSE_DIR="${REPO_ROOT}/deploy/compose"
readonly ARTIFACT_DIR="${REPO_ROOT}/build/ops"

log() { printf '[%s] %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*"; }
pass() { printf 'PASS: %s\n' "$*"; }
fail() { printf 'FAIL: %s\n' "$*" >&2; }
die() { fail "$*"; exit 1; }

require_cmd() {
  command -v "$1" >/dev/null 2>&1 || die "required command not found: $1"
}

resolve_php() {
  if command -v php >/dev/null 2>&1; then
    printf '%s' "$(command -v php)"
    return 0
  fi
  if [[ -x "${REPO_ROOT}/.tools/php/php" ]]; then
    printf '%s' "${REPO_ROOT}/.tools/php/php"
    return 0
  fi
  die "php not found (add it to PATH or place it at .tools/php/php)"
}

artisan() {
  local php
  php="$(resolve_php)"
  (cd "${BACKEND_DIR}" && "${php}" artisan "$@")
}

ensure_artifact_dir() {
  mkdir -p "${ARTIFACT_DIR}"
}

sha256_file() {
  sha256sum "$1" | awk '{print $1}'
}

json_escape() {
  python3 -c 'import json,sys; print(json.dumps(sys.argv[1]))' "$1"
}
