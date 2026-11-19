#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."

composer install --working-dir=backend --no-interaction
npm ci
npx playwright install --with-deps chromium

if [[ ! -f backend/.env ]]; then
  cp backend/.env.example backend/.env
  php backend/artisan key:generate
fi
