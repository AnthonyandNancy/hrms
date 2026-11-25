#!/usr/bin/env bash
# CI entry point for the V1.1 production acceptance gate.
# Delegates to scripts/v11_production_gate.mjs so local and CI runs are identical.
set -euo pipefail
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$root"
exec node scripts/v11_production_gate.mjs "$@"
