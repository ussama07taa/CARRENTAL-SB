#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
export PATH="/opt/bensebih-toolchain/node/bin:$PATH"
export NEXT_TELEMETRY_DISABLED=1
export TURBO_TELEMETRY_DISABLED=1
[[ -f apps/api/.env && -f apps/api/vendor/autoload.php && -d node_modules ]] || {
  echo 'Project dependencies or local .env are missing. Read docs/development-ar.md.'; exit 1;
}
pg_isready -h 127.0.0.1 -p 5432
redis-cli ping
echo 'Dashboard http://localhost:3000 | Site http://localhost:3001 | API http://localhost:8000'
exec pnpm dev
