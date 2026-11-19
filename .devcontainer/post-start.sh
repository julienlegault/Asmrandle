#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."

php backend/artisan migrate --force
php backend/artisan cards:initialize-development

if ! curl --silent --fail http://127.0.0.1:8000/api/config >/dev/null; then
  nohup php backend/artisan serve --host=0.0.0.0 --port=8000 > /tmp/asmrandle-web.log 2>&1 &
  for _ in {1..30}; do
    if curl --silent --fail http://127.0.0.1:8000/api/config >/dev/null; then
      echo "Asmrandle is available on port 8000."
      exit 0
    fi
    sleep 1
  done
  echo "Asmrandle failed to start. See /tmp/asmrandle-web.log." >&2
  exit 1
fi
echo "Asmrandle is already available on port 8000."
