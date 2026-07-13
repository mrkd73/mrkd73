#!/usr/bin/env bash
# Full local setup: download WordPress core, write wp-config.php,
# ensure the database, and run the WordPress installer.
# Idempotent: safe to run repeatedly.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
. "$SCRIPT_DIR/_env.sh"

# 1. Local env file
if [ ! -f "$ROOT_DIR/.env" ]; then
  echo "==> Creating .env from .env.example"
  cp "$ROOT_DIR/.env.example" "$ROOT_DIR/.env"
fi

# 2. Database
"$SCRIPT_DIR/db-setup.sh"

# 3. WordPress core
if [ ! -f "$WP_DIR/wp-settings.php" ]; then
  echo "==> Downloading WordPress core..."
  wp core download --path="$WP_DIR"
else
  echo "==> WordPress core already present."
fi

# 4. wp-config.php
if [ ! -f "$WP_DIR/wp-config.php" ]; then
  echo "==> Creating wp-config.php"
  wp_cli config create \
    --dbname="$WP_DB_NAME" \
    --dbuser="$WP_DB_USER" \
    --dbpass="$WP_DB_PASSWORD" \
    --dbhost="$WP_DB_HOST" \
    --skip-check
  wp_cli config set WP_DEBUG true --raw
  wp_cli config set WP_DEBUG_LOG true --raw
else
  echo "==> wp-config.php already present."
fi

# 5. Install WordPress
if wp_cli core is-installed >/dev/null 2>&1; then
  echo "==> WordPress already installed."
else
  echo "==> Installing WordPress..."
  wp_cli core install \
    --url="$WP_URL" \
    --title="$WP_TITLE" \
    --admin_user="$WP_ADMIN_USER" \
    --admin_password="$WP_ADMIN_PASSWORD" \
    --admin_email="$WP_ADMIN_EMAIL" \
    --skip-email
fi

echo ""
echo "==> WordPress is ready."
echo "    Site:  $WP_URL"
echo "    Admin: $WP_URL/wp-admin/  (user: $WP_ADMIN_USER / pass: $WP_ADMIN_PASSWORD)"
echo "    Run 'make start' to launch the dev server."
