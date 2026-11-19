#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."

echo "Running migrations and seeding development data..."
php backend/artisan migrate --force
php backend/artisan cards:initialize-development

echo "Starting Asmrandle web server..."
# Start the Laravel development server in the background
nohup php backend/artisan serve --host=0.0.0.0 --port=8000 > /tmp/asmrandle-web.log 2>&1 &
SERVER_PID=$!
echo "Server started with PID $SERVER_PID"

# Wait for server to be ready
for i in {1..30}; do
  if curl --silent --fail http://127.0.0.1:8000/api/config >/dev/null 2>&1; then
    echo "✓ Asmrandle is available on port 8000"
    break
  fi
  if [ $i -eq 30 ]; then
    echo "✗ Asmrandle failed to start. Check /tmp/asmrandle-web.log for details:" >&2
    cat /tmp/asmrandle-web.log >&2
    exit 1
  fi
  echo "  Waiting for server... ($i/30)"
  sleep 1
done

# Keep a background watcher that restarts the server if it dies
(
  while true; do
    sleep 5
    if ! curl --silent --fail http://127.0.0.1:8000/api/config >/dev/null 2>&1; then
      echo "Server is not responding. Restarting..."
      pkill -f "php backend/artisan serve" || true
      sleep 2
      nohup php backend/artisan serve --host=0.0.0.0 --port=8000 > /tmp/asmrandle-web.log 2>&1 &
      echo "Server restarted"
      sleep 3
    fi
  done
) &

exit 0
