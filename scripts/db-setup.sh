#!/usr/bin/env bash
# Start MariaDB (if needed) and ensure the WordPress database + user exist.
# Idempotent: safe to run repeatedly.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
. "$SCRIPT_DIR/_env.sh"

echo "==> Starting MariaDB..."
sudo service mariadb start || true

echo "==> Waiting for MariaDB to accept connections..."
for _ in $(seq 1 30); do
  if sudo mariadb -e "SELECT 1" >/dev/null 2>&1; then
    break
  fi
  sleep 1
done

echo "==> Ensuring database '$WP_DB_NAME' and user '$WP_DB_USER'..."
sudo mariadb <<SQL
CREATE DATABASE IF NOT EXISTS \`${WP_DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${WP_DB_USER}'@'localhost' IDENTIFIED BY '${WP_DB_PASSWORD}';
GRANT ALL PRIVILEGES ON \`${WP_DB_NAME}\`.* TO '${WP_DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL

echo "==> Database is ready."
