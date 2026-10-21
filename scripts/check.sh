#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
export PATH="/opt/bensebih-toolchain/node/bin:$PATH"
export NEXT_TELEMETRY_DISABLED=1
export TURBO_TELEMETRY_DISABLED=1
pnpm build
pnpm typecheck
cd apps/api
# Browsershot is deliberately pinned; keep schema and lock validation strict.
php /opt/bensebih-toolchain/composer.phar validate --strict --no-check-all
php artisan test
