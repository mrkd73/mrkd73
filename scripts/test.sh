#!/usr/bin/env bash
# Smoke test: verify WordPress is installed and the site responds over HTTP.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
. "$SCRIPT_DIR/_env.sh"

sudo service mariadb start || true

echo "==> Checking WordPress installation state"
if ! wp_cli core is-installed; then
  echo "  FAIL: WordPress is not installed. Run 'make setup' first." >&2
  exit 1
fi
echo "  OK: WordPress is installed ($(wp_cli core version))"

echo "==> Checking database connectivity"
wp_cli db check >/dev/null && echo "  OK: database reachable"

echo "==> Starting a temporary server for an HTTP smoke test"
cd "$WP_DIR"
php -S "127.0.0.1:${WP_PORT}" "$SCRIPT_DIR/router.php" >/tmp/wp-test-server.log 2>&1 &
server_pid=$!
trap 'kill "$server_pid" 2>/dev/null || true' EXIT

for _ in $(seq 1 20); do
  if curl -fsS -o /dev/null "http://127.0.0.1:${WP_PORT}/" 2>/dev/null; then
    break
  fi
  sleep 0.5
done

code=$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:${WP_PORT}/")
title=$(curl -s "http://127.0.0.1:${WP_PORT}/" | grep -o '<title>[^<]*</title>' | head -1)

if [ "$code" = "200" ]; then
  echo "  OK: homepage returned HTTP 200"
  echo "  Page title: ${title:-<none>}"
  echo ""
  echo "==> All smoke tests passed."
  exit 0
else
  echo "  FAIL: homepage returned HTTP $code" >&2
  cat /tmp/wp-test-server.log >&2
  exit 1
fi
