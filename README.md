# mrkd73

A local **WordPress** development environment.

WordPress core itself is not committed to the repository — it is downloaded into
`wordpress/` on demand (see `.gitignore`). The repo only tracks the tooling
needed to spin the site up.

## Stack

- **PHP 8.3** (CLI + built-in web server)
- **MariaDB 10.11**
- **WP-CLI** for provisioning and management

## Quick start

```bash
# 1. Install core, configure wp-config.php, create the DB, install WordPress
make setup

# 2. Start the dev server (http://localhost:8080)
make start
```

Then open:

- Site: <http://localhost:8080>
- Admin: <http://localhost:8080/wp-admin/> (default dev login `admin` / `admin`)

Configuration defaults live in `.env.example`; copy to `.env` to override
(`make setup` does this automatically on first run).

## Common commands

| Command      | Description                                                        |
| ------------ | ----------------------------------------------------------------- |
| `make setup` | Download core, write `wp-config.php`, create DB, install WordPress |
| `make db`    | Start MariaDB and ensure the WordPress DB/user exist              |
| `make start` | Run the dev server (PHP built-in server + WordPress router)       |
| `make lint`  | PHP syntax checks + WordPress core checksum verification          |
| `make test`  | Smoke test: WordPress installed + homepage returns HTTP 200       |
| `make clean` | Remove the downloaded `wordpress/` directory                      |

Manage the site directly with WP-CLI, e.g.:

```bash
wp --path=wordpress post list
wp --path=wordpress plugin install <slug> --activate
```

> Note: there is no separate "build" step — PHP is interpreted, so `make start`
> serves the code directly.
