#!/usr/bin/env bash
# Shared helper: resolve repo root and load env defaults.
# Sourced by the other scripts in this directory.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
WP_DIR="$ROOT_DIR/wordpress"

if [ -f "$ROOT_DIR/.env" ]; then
  set -a
  # shellcheck disable=SC1091
  . "$ROOT_DIR/.env"
  set +a
fi

: "${WP_DB_NAME:=wordpress}"
: "${WP_DB_USER:=wordpress}"
: "${WP_DB_PASSWORD:=wordpress}"
: "${WP_DB_HOST:=localhost}"
: "${WP_PORT:=8080}"
: "${WP_URL:=http://localhost:${WP_PORT}}"
: "${WP_TITLE:=mrkd73 Dev}"
: "${WP_ADMIN_USER:=admin}"
: "${WP_ADMIN_PASSWORD:=admin}"
: "${WP_ADMIN_EMAIL:=admin@example.com}"

wp_cli() {
  wp --path="$WP_DIR" "$@"
}
