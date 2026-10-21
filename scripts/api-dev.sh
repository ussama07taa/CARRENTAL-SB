#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/../apps/api"
env_args=()
if [[ "${BENSEBIH_DEMO:-0}" == "1" ]]; then
  [[ -f .env.demo ]] || { echo 'Missing .env.demo; prepare the isolated demo first.'; exit 1; }
  env_args=(--env=demo)
  echo 'DEMO MODE: isolated bensebih_demo database; fictitious accounts only.'
fi
export PHP_INI_SCAN_DIR=":$(pwd)/../../scripts/php"
php artisan serve "${env_args[@]}" --no-reload --host=127.0.0.1 --port=8000 &
api_pid=$!
php artisan horizon "${env_args[@]}" &
worker_pid=$!
php artisan schedule:work "${env_args[@]}" &
scheduler_pid=$!
trap 'kill "$api_pid" "$worker_pid" "$scheduler_pid" 2>/dev/null || true; wait || true' EXIT INT TERM
wait -n "$api_pid" "$worker_pid" "$scheduler_pid"
