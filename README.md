# php-db-admin

A lightweight, Adminar/phpMyAdmin-style DB admin tool. Supports MySQL / PostgreSQL / SQLite.
Built on FlightPHP v3 + Latte, served by the PHP built-in server in an Alpine Docker image — minimal by design.

[日本語 README](README_ja.md)

## Features

- **Multi-DB**: switch between MySQL / PostgreSQL / SQLite at login
- **Two-pane UI**: table tree on the left, table detail on the right (phpMyAdmin/Adminar-like)
- **Tabbed detail view**: Browse (rows) / Structure (schema) / Search Builder (condition form → SQL)
- **CRUD**: input form is auto-generated from column types
- **CSV export**: UTF-8 with BOM (Excel-friendly)
- **SQL editor**: CodeMirror syntax highlighting, Ctrl+Enter to run, confirm dialog for destructive queries, prefill via `?sql=`
- **Search Builder**: pick column × operator (`=`, `LIKE`, `IS NULL`, …) → builds a SELECT and opens it in the SQL editor
- **Binary-safe rendering**: BLOB / BYTEA / non-UTF-8 values are shown as `[type, N bytes]` so rows don't blow up
- **AI SQL generation (optional)**: describe a query in natural language and get a SQL candidate (requires `OPENAI_API_KEY`)
- **Security**: PDO prepared statements, table-name whitelist validation, Latte auto-escaping, per-session CSRF tokens on every POST

## Quick start

The fastest way to run the app is the prebuilt image on GitHub Container Registry:

```bash
docker run --rm -p 8080:8080 ghcr.io/uplucid/php-db-admin:latest
```

Then open <http://localhost:8080> and enter your database connection details on the login screen.

Available tags:
- `latest` — tip of `main`
- `vX.Y.Z` / `X.Y` — tagged releases

### Prefilling the login form via env vars

Pass any of these variables and they will prefill the login form:

```bash
docker run --rm -p 8080:8080 \
  -e DB_DRIVER=mysql \
  -e DB_HOST=db-server \
  -e DB_PORT=3306 \
  -e DB_USER=root \
  -e DB_PASSWORD=secret \
  -e DB_NAME=mydb \
  -e OPENAI_API_KEY=sk-... \
  ghcr.io/uplucid/php-db-admin:latest
```

For larger setups, use `--env-file` pointing at a local `.env` (copy `.env.example` from the repo as a starting point).

### Persistent Latte cache (optional)

```bash
docker run --rm -p 8080:8080 \
  -v phpdbadmin-cache:/app/cache \
  ghcr.io/uplucid/php-db-admin:latest
```

## Deploying to a shared-hosting rental server

> [!IMPORTANT]
> **The app must be mounted at the root of a (sub)domain, not at a subpath.** The templates and client-side JS use absolute-from-root URLs (`/db/...`, `fetch('/ai/...')`) for simplicity and zero-config local development. Mounting under a subpath like `https://example.com/phpdbadmin/` will break navigation and cause redirect loops.
>
> Use a dedicated (sub)domain such as `dbadmin.example.com` pointing at this app.

### Layout

Keep the application code **outside** the web root and point the (sub)domain's document root at a small directory that only contains `index.php`, `.htaccess`, and `assets/`:

```
<home>/
├── phpdbadmin/             ← NOT web-accessible
│   ├── app/
│   ├── vendor/
│   ├── cache/
│   ├── composer.json
│   └── config.php          ← your filled-in copy of config.example.php
│
└── dbadmin.example.com/    ← document root of the subdomain
    ├── index.php           ← copied from phpdbadmin/public/index.php, one line edited
    ├── .htaccess           ← copied from phpdbadmin/public/.htaccess (3 lines)
    └── assets/             ← copied from phpdbadmin/public/assets/
```

### Steps

1. Point a subdomain (e.g. `dbadmin.example.com`) at a new document-root directory in your shared-hosting control panel.
2. Download `php-db-admin-vX.Y.Z.zip` from GitHub Releases and extract it outside the web root, e.g. `~/phpdbadmin/`.
3. Copy `phpdbadmin/public/index.php`, `phpdbadmin/public/.htaccess`, and `phpdbadmin/public/assets/` into the subdomain's document root.
4. In the copied `index.php`, point `APP_BASE` at where the extracted app lives:

   ```php
   define('APP_BASE', __DIR__ . '/../phpdbadmin');
   // or an absolute path:
   // define('APP_BASE', '/home/your-user/phpdbadmin');
   ```

5. Copy `config.example.php` to `phpdbadmin/config.php` and fill in DB credentials / `OPENAI_API_KEY` / etc. If your host lets you set real environment variables, you can skip this; real env vars always take precedence over `config.php`.
6. Ensure `cache/latte/` (inside the app directory) is writable by the web server.
7. The app's own "login" only checks that DB credentials work — it is **not** an authentication system. Add an extra layer with `.htpasswd`-backed Basic auth in the subdomain's `.htaccess` when exposing this anywhere beyond localhost.

## Usage

### Browsing tables
1. After logging in, pick a table from the left sidebar
2. `Browse` tab: row list (50 per page, Prev/Next)
3. `Structure` tab: column definitions (type, NULL, Key, Default, Extra)
4. `Search Builder` tab: specify operator + value per column → `Build SQL & Open in Editor` builds a SELECT and jumps to the SQL editor

### Editing records
- `Edit` / `Delete` buttons on each row
- `+ New Record` to insert
- Input type is picked based on the column type (INT → number, DATE → date, TEXT → textarea, …)

### CSV export
- `Export CSV` button on the Browse tab
- Exports the current query result (all rows) as UTF-8 with BOM

### SQL editor
- Launch from the sidebar via `SQL Editor`
- `Ctrl+Enter` (or `Cmd+Enter` on macOS) to run
- DROP / TRUNCATE / DELETE shows a confirm dialog
- SELECT results render as a table; other statements show the affected-row count

## Directory layout

```
php-db-admin/
├── Dockerfile              # multi-stage build (php:8.4-cli-alpine)
├── docker-compose.yml
├── composer.json           # flightphp/core + latte/latte
├── .env.example            # copy to .env for Docker
├── config.example.php      # copy to config.php for shared hosting
├── public/
│   ├── index.php           # routes + Latte registration + CSRF guard
│   ├── .htaccess           # 3-line front-controller rewrite
│   ├── router.php          # router for the PHP built-in server
│   └── assets/app.css
└── app/
    ├── controllers/
    │   ├── AuthController.php
    │   ├── DatabaseController.php
    │   ├── TableController.php
    │   ├── QueryController.php
    │   └── AiController.php
    └── services/
        ├── DB.php              # multi-driver PDO wrapper
        ├── CsvExporter.php
        ├── ViewContext.php     # shared sidebar data
        ├── FormHelper.php      # column type → HTML input type
        ├── AiService.php       # OpenAI-compatible client
        └── Csrf.php            # session CSRF token helper
    └── views/                  # Latte templates
        ├── layout.latte        # two-pane layout
        ├── login.latte
        ├── databases.latte
        ├── tables.latte
        ├── table_view.latte    # Browse / Structure / Search tabs
        ├── table_form.latte
        ├── query.latte
        └── partials/
            ├── data.latte
            ├── schema.latte
            └── search.latte
```

## URL design

| Method | Path | Description |
|---|---|---|
| GET  | `/` | Login page or database list |
| POST | `/auth` | Log in |
| GET  | `/logout` | Log out |
| GET  | `/db/{db}` | Table list |
| GET  | `/db/{db}/table/{table}` | Browse tab (`?tab=schema` / `?tab=search` to switch) |
| GET  | `/db/{db}/table/{table}/create` | New record form |
| POST | `/db/{db}/table/{table}/store` | Insert |
| GET  | `/db/{db}/table/{table}/edit/{id}` | Edit form |
| POST | `/db/{db}/table/{table}/update/{id}` | Update |
| POST | `/db/{db}/table/{table}/delete/{id}` | Delete |
| GET  | `/db/{db}/table/{table}/export` | CSV download |
| GET  | `/db/{db}/query` | SQL editor (prefill via `?sql=...`) |
| POST | `/db/{db}/query/run` | Execute SQL (JSON API) |
| POST | `/db/{db}/ai/generate` | AI SQL generation (JSON API) |
| POST | `/db/{db}/ai/ask` | AI DB Q&A (JSON API) |

## Development (building from source)

Clone the repo and use Docker Compose to build and run locally with hot reload:

```bash
git clone https://github.com/uplucid/php-db-admin.git
cd php-db-admin

# Optional: prepare a .env (gitignored) to prefill the login form
cp .env.example .env

# Build & run
docker compose up -d --build

open http://localhost:8080
```

`./app`, `./public`, and `./cache` are bind-mounted in `docker-compose.yml`, so source changes are picked up without restarting the container (Latte's `setAutoRefresh(true)` is also on).

### Installing vendor/ on the host (for IDE completion)

```bash
docker run --rm -v "$(pwd):/app" -w /app composer:2 install
```

### Logs

```bash
docker compose logs -f app
```

## Releases

Pushing a `v*` tag triggers two workflows:

- [`.github/workflows/release.yml`](.github/workflows/release.yml) runs `composer install --no-dev --optimize-autoloader`, bundles a deployment zip, and attaches it to the GitHub Release.
- [`.github/workflows/docker.yml`](.github/workflows/docker.yml) builds and pushes a multi-tag image (`vX.Y.Z`, `X.Y`, `latest`) to `ghcr.io/uplucid/php-db-admin`.

## Out of scope

- HTTPS / production hardening
- Multiple concurrent users
- Schema changes (ALTER TABLE) in the UI
- Import
- Stored procedure / view management

## Security notes

- All user input is bound via **PDO prepared statements**
- Table and database names are **whitelist-validated** before being inlined into SQL
- Latte provides **context-aware auto-escaping** for XSS protection (HTML / attribute / JS / URL)
- Session is regenerated on login (`session_regenerate_id(true)`) to mitigate session fixation
- **CSRF protection**: every POST route validates a per-session token (via `csrf_token` field or `X-CSRF-Token` header)
- **Not recommended for public production use**. The in-app login only asks for DB credentials; add `.htaccess` Basic auth or similar when exposing this anywhere beyond localhost.
