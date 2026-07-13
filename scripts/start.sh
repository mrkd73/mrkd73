#!/usr/bin/env bash
# Start the WordPress development server (PHP built-in web server).
# Ensures MariaDB is running first.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
. "$SCRIPT_DIR/_env.sh"

if [ ! -f "$WP_DIR/wp-settings.php" ]; then
  echo "WordPress core not found. Run 'make setup' first." >&2
  exit 1
fi

sudo service mariadb start || true

echo "==> Starting WordPress dev server at $WP_URL"
echo "    Press Ctrl+C to stop."
cd "$WP_DIR"
# router file lets the built-in server serve WordPress permalinks
exec php -S "0.0.0.0:${WP_PORT}" "$SCRIPT_DIR/router.php"
