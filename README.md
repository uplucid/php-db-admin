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

## Requirements

- Docker / Docker Compose
- A web browser (`http://localhost:8080`)

## Quick start

```bash
# Prepare the env file (.env is gitignored)
cp .env.example .env

# Build & run
docker compose up -d --build

# Open in your browser
open http://localhost:8080
```

At the login screen, pick a driver (MySQL / PostgreSQL / SQLite) and enter the connection details.

### Prefilling the login form via env vars

Values in `.env` are passed to PHP through the `env_file` directive in `docker-compose.yml` and used to prefill the login form:

```env
DB_DRIVER=mysql
DB_HOST=db-server
DB_PORT=3306
DB_USER=root
DB_PASSWORD=secret
DB_NAME=mydb
DB_PATH=/path/to/sqlite.db   # SQLite only

# AI SQL generation (optional)
OPENAI_API_KEY=sk-...
OPENAI_BASE_URL=
OPENAI_MODEL=gpt-4o-mini
```

## Deploying to a shared-hosting rental server

Two layouts are supported. **Mode A is strongly recommended** because it keeps application code physically out of the web root, and the required `.htaccess` is only three lines.

### Mode A — keep app code out of the web root (recommended)

```
<home>/
├── phpdbadmin/             ← NOT web-accessible (above or beside the web root)
│   ├── app/
│   ├── vendor/
│   ├── cache/
│   ├── composer.json
│   └── config.php          ← your filled-in copy of config.example.php
│
└── public_html/            ← web root
    ├── index.php           ← copied from phpdbadmin/public/index.php, one line edited
    ├── .htaccess           ← copied from phpdbadmin/public/.htaccess (3 lines)
    └── assets/             ← copied from phpdbadmin/public/assets/
```

Steps:

1. Download `php-db-admin-vX.Y.Z.zip` from GitHub Releases and extract it somewhere **outside** the web root, e.g. `~/phpdbadmin/`.
2. Copy `phpdbadmin/public/index.php`, `phpdbadmin/public/.htaccess`, and `phpdbadmin/public/assets/` into your web root.
3. In the copied `index.php`, point `APP_BASE` at where you put the app. Example:

   ```php
   define('APP_BASE', __DIR__ . '/../phpdbadmin');
   // or an absolute path:
   // define('APP_BASE', '/home/your-user/phpdbadmin');
   ```

4. Copy `config.example.php` to `phpdbadmin/config.php` and fill in DB credentials / `OPENAI_API_KEY` / etc. If your host lets you set real environment variables, you can skip this; real env vars always take precedence.
5. Ensure `cache/latte/` (inside your app directory) is writable by the web server.
6. Since the app's own login only checks whether DB credentials work, add an extra layer with `.htpasswd`-backed Basic auth in the web root's `.htaccess` when exposing this anywhere beyond localhost.

### Mode B — everything in a subdirectory of the web root

If your host won't let you place files above the web root, upload the extracted zip to e.g. `public_html/phpdbadmin/` and access it at `https://example.com/phpdbadmin/`. In this case `app/`, `vendor/`, `cache/`, and `config.php` are physically reachable via URL, so you **must** block them explicitly. Create `public_html/phpdbadmin/.htaccess` with:

```apache
# Block direct access to sensitive directories
RedirectMatch 404 (?i)^/phpdbadmin/(?:app|vendor|cache|\.git|tests?)(?:/|$)

# Block sensitive files
<FilesMatch "(?i)(^\.|composer\.(json|lock)|\.md$|\.latte$|\.example$|^config\.php$|\.sqlite3?$|\.db$)">
    Require all denied
</FilesMatch>

# Optional Basic auth — strongly recommended for Mode B
# AuthType Basic
# AuthName "db-admin"
# AuthUserFile /absolute/path/above/docroot/.htpasswd
# Require valid-user

# Serve real files under public/ (e.g. /phpdbadmin/assets/app.css),
# then fall back to the front controller.
RewriteEngine On
RewriteBase /phpdbadmin/

RewriteCond %{REQUEST_URI} !^/phpdbadmin/public/
RewriteCond %{DOCUMENT_ROOT}/phpdbadmin/public%{REQUEST_URI} -f [OR]
RewriteCond %{DOCUMENT_ROOT}/phpdbadmin/public%{REQUEST_URI} -d
RewriteRule ^/?(.*)$ public/$1 [L]

RewriteRule ^/?$ public/index.php [L,QSA]
RewriteRule ^/?(.*)$ public/index.php [L,QSA]
```

This requires `mod_rewrite`, `FilesMatch`, and `RedirectMatch`. If anything in this section is not supported by your host, Mode B is not safe for you — prefer Mode A.

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
db-admin/
├── Dockerfile              # multi-stage build (php:8.4-cli-alpine)
├── docker-compose.yml
├── composer.json           # flightphp/core + latte/latte
├── .htaccess               # shared-hosting routing + deny rules
├── .env.example            # copy to .env for Docker
├── config.example.php      # copy to config.php for shared hosting
├── public/
│   ├── index.php           # routes + Latte registration + CSRF guard
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

## Development

### Hot reload
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

Pushing a `v*` tag triggers [`.github/workflows/release.yml`](.github/workflows/release.yml), which runs `composer install --no-dev --optimize-autoloader`, bundles a deployment zip, and attaches it to the GitHub Release.

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
