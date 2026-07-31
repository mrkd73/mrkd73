# AGENTS.md

## Cursor Cloud specific instructions

This repo is a local **WordPress** dev environment. Standard commands live in
`README.md` and the `Makefile`; this section only captures non-obvious caveats.

- **WordPress core is not committed.** It lives in `wordpress/` (git-ignored) and
  is downloaded on demand. The startup update script runs `wp core download` if
  `wordpress/wp-settings.php` is missing, but it does **not** create
  `wp-config.php`, create the database, or install the site. After a fresh boot
  run `make setup` (idempotent) to get a fully installed site.
- **MariaDB does not auto-start on boot.** Start it with `make db`
  (or `sudo service mariadb start`) before running the site or tests.
  `make start`/`make test`/`make setup` also start it for you.
- **DB connection uses the unix socket.** `WP_DB_HOST=localhost` (not
  `127.0.0.1`) so PHP's mysqli connects via the socket, matching the
  `wordpress'@'localhost` MariaDB account. Default dev creds: DB `wordpress`,
  user `wordpress`, password `wordpress`.
- **Dev admin login:** `admin` / `admin` (set in `.env`, dev-only).
- **Serving:** `make start` runs PHP's built-in server on port `8080` via
  `scripts/router.php` (needed so permalinks and static assets resolve). There is
  no build step — PHP is interpreted.
- **Lint checksum is informational.** `make lint`'s authoritative check is
 `php -l` syntax checking. `wp core verify-checksums` is reported but never fails
 the build, because the WordPress.org checksum API can lag behind newly bundled
 core files (e.g. `php-ai-client` in WP 7.0.x).

### Agent WP plugin

- **Source lives in the repo, deployed via symlink.** The `agent-wp` plugin is
 tracked at `wp-content/plugins/agent-wp/` and symlinked into the git-ignored
 `wordpress/wp-content/plugins/agent-wp` (the startup script recreates the
 symlink when both dirs exist). Edit the repo copy; changes are live immediately
 (PHP is interpreted, no build step).
- **Activate after a fresh install:** `wp --path=wordpress plugin activate agent-wp`.
 Activation state is in the DB, so re-run this after `make setup` recreates the DB.
- **GapGPT key is required for live AI.** The API key is stored server-side only
 (never returned to the browser) and is set from the plugin's admin page
 ("ایجنت وردپرس"). Without a key the chat returns a built-in mock reply, so the
 UI is testable but real model responses need a key.
