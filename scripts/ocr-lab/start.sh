#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/../.."
if [[ ! -x .local/ocr-venv/bin/python ]]; then
  echo 'Install the OCR environment first; see scripts/ocr-lab/README.md.' >&2
  exit 1
fi
exec .local/ocr-venv/bin/python scripts/ocr-lab/server.py
