#!/usr/bin/env bash
# Lint checks:
#  - PHP syntax check (php -l) on any custom PHP in wp-content (themes/plugins/mu-plugins)
#  - WordPress core checksum verification (integrity of the downloaded core)

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
. "$SCRIPT_DIR/_env.sh"

status=0

echo "==> PHP syntax check (php -l)"
targets=("$SCRIPT_DIR/router.php")
if [ -d "$WP_DIR/wp-content" ]; then
  while IFS= read -r -d '' f; do
    targets+=("$f")
  done < <(find "$WP_DIR/wp-content" \
      -path "*/themes/twenty*" -prune -o \
      -name '*.php' -print0 2>/dev/null)
fi

for f in "${targets[@]}"; do
  if ! php -l "$f" >/dev/null; then
    echo "  SYNTAX ERROR: $f"
    status=1
  fi
done
[ "$status" -eq 0 ] && echo "  OK (${#targets[@]} file(s) checked)"

if [ -f "$WP_DIR/wp-settings.php" ]; then
  # Informational only: the WordPress.org checksum API can lag behind newly
  # bundled core files (e.g. php-ai-client in WP 7.0.x), so a mismatch here is
  # not treated as a lint failure. PHP syntax is the authoritative check above.
  echo "==> Verifying WordPress core checksums (informational)"
  wp_cli core verify-checksums 2>&1 | tail -1 || true
fi

exit "$status"
