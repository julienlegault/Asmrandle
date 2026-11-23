#!/usr/bin/env bash
# Container entrypoint: prepares the app without any user interaction, then serves it.
# Failures are retried and never exit, so the container stays reachable for debugging.
set -uo pipefail
cd "$(dirname "$0")/.."

LOG=/tmp/asmrandle.log
exec > >(tee -a "$LOG") 2>&1

prepare() {
  set -e
  if [[ ! -f backend/vendor/autoload.php ]]; then
    composer install --working-dir=backend --no-interaction --prefer-dist
  fi
  if [[ ! -f backend/.env ]]; then
    cp backend/.env.example backend/.env
  fi
  if ! grep -q '^APP_KEY=.\+' backend/.env; then
    php backend/artisan key:generate --force
  fi
  # The database healthcheck can pass slightly before the app user is usable.
  for attempt in {1..30}; do
    php backend/artisan migrate --force && break
    [[ $attempt -lt 30 ]] || exit 1
    sleep 2
  done
  php backend/artisan cards:initialize-development

  # Seed Codespace-specific development data (admin account + past runs)
  if [[ "${CODESPACES:-false}" == "true" ]]; then
    php backend/artisan db:seed --class=CodespaceSeeder --force
  fi
}

until (prepare); do
  echo "[asmrandle] Setup failed; retrying in 10s (log: $LOG)"
  sleep 10
done

# Port 8000 is deliberately not in devcontainer.json "forwardPorts": forwarding it
# before the server listens leaves Codespaces with a stale tunnel that returns 502.
# It is auto-forwarded (see portsAttributes) once the server below starts listening.
wait_until_ready() {
  for _ in {1..60}; do
    if curl -fs -o /dev/null http://127.0.0.1:8000/; then
      echo "[asmrandle] Ready: http://localhost:8000"
      return
    fi
    sleep 1
  done
  echo "[asmrandle] Server did not answer on port 8000 within 60s (log: $LOG)"
}
wait_until_ready &

echo "[asmrandle] Serving on 0.0.0.0:8000"
while true; do
  php backend/artisan serve --no-reload --host=0.0.0.0 --port=8000
  echo "[asmrandle] Server exited; restarting in 2s"
  sleep 2
done
