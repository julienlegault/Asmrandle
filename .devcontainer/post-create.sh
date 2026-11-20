#!/usr/bin/env bash
# Tooling for browser tests only; the web app itself is prepared by start.sh.
set -uo pipefail
cd "$(dirname "$0")/.."

npm ci || echo "npm ci failed; run it manually to use the browser tests."
npx playwright install --with-deps chromium || echo "Playwright install failed; run it manually to use the browser tests."
exit 0
