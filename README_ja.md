# php-db-admin

phpMyAdminやAdminar風の軽量DB管理ツール。MySQL / PostgreSQL / SQLite 対応。
FlightPHP v3 + Latte + PHPビルトインサーバー + Alpine Dockerで動く、ミニマル構成。

[English README](README.md)

## 特徴

- **マルチDB対応**: MySQL / PostgreSQL / SQLite をログイン時に切替
- **2ペインUI**: 左にテーブルツリー、右にテーブル詳細（phpMyAdminやAdminar風）
- **タブ切替**: Browse（レコード一覧）/ Structure（スキーマ）/ Search Builder（検索SQL組み立て）
- **CRUD**: カラム型に応じた入力フォーム自動生成
- **CSVエクスポート**: BOM付UTF-8（Excel対応）
- **SQLエディタ**: CodeMirrorによるシンタックスハイライト / Ctrl+Enter実行 / 破壊的クエリ確認ダイアログ / 外部からの `?sql=` プリフィル対応
- **Search Builder**: カラム×演算子（`=`, `LIKE`, `IS NULL` 等）で条件を組み、SELECT文を生成してSQLエディタへ遷移
- **バイナリ保護**: BLOB/BYTEA/非UTF-8の値は `[type, N bytes]` として表示（行が崩れない）
- **セキュリティ**: PDOプリペアドステートメント、テーブル名ホワイトリスト検証、Latteによる自動エスケープ

## 動作要件

- Docker / Docker Compose
- ブラウザ（`http://localhost:8080`）

## クイックスタート

```bash
# 環境ファイルを用意（.env はgitignore対象）
cp .env.example .env

# ビルド＆起動
docker compose up -d --build

# ブラウザで開く
open http://localhost:8080
```

ログイン画面でドライバ（MySQL / PostgreSQL / SQLite）・ホスト・ユーザー等を入力して接続。

### 環境変数で接続情報を初期値に設定

`.env` に値を書くと `docker-compose.yml` の `env_file` 経由でPHPに渡り、ログインフォームに自動入力されます:

```env
DB_DRIVER=mysql
DB_HOST=db-server
DB_PORT=3306
DB_USER=root
DB_PASSWORD=secret
DB_NAME=mydb
DB_PATH=/path/to/sqlite.db   # SQLite用

# AI SQL生成 (任意)
OPENAI_API_KEY=sk-...
OPENAI_BASE_URL=
OPENAI_MODEL=gpt-5.4-mini
```

## Dockerイメージで動かす (GHCR)

`main` ブランチへの push と `v*` タグ push のたびに、GitHub Container Registry にイメージが自動公開されます。Docker が使える環境なら、これが最短の起動方法です:

```bash
docker run --rm -p 8080:8080 \
  -e OPENAI_API_KEY=sk-...          \
  ghcr.io/uplucid/php-db-admin:latest
```

利用可能なタグ:
- `latest` — `main` ブランチの最新
- `vX.Y.Z` / `X.Y` — リリースタグ

Latte キャッシュを永続化したい場合は `/app/cache` にボリュームをマウント:

```bash
docker run --rm -p 8080:8080 \
  -v phpdbadmin-cache:/app/cache \
  ghcr.io/uplucid/php-db-admin:latest
```

## レンタルサーバへ設置する場合

> [!IMPORTANT]
> **このアプリは (サブ)ドメインのルートに設置する必要があります。サブパス(例: `https://example.com/phpdbadmin/`)では動きません。** テンプレートと JS 側が `/db/...` や `fetch('/ai/...')` のような絶対パスを使っているため、サブパスに置くとナビゲーションが壊れ、リダイレクトループになります。
>
> `dbadmin.example.com` のような専用サブドメインを用意して、そのドキュメントルートをこのアプリに向けてください。

### レイアウト

アプリ本体は Web ルートの**外**に置き、サブドメインのドキュメントルートには `index.php`・`.htaccess`・`assets/` の最小セットだけを配置します:

```
<ホーム>/
├── phpdbadmin/             ← Webからは見えない場所
│   ├── app/
│   ├── vendor/
│   ├── cache/
│   ├── composer.json
│   └── config.php          ← config.example.php をコピーして記入したもの
│
└── dbadmin.example.com/    ← サブドメインのドキュメントルート
    ├── index.php           ← phpdbadmin/public/index.php をコピーし1行だけ編集
    ├── .htaccess           ← phpdbadmin/public/.htaccess をコピー（3行）
    └── assets/             ← phpdbadmin/public/assets/ をコピー
```

### 手順

1. ホスティングのコントロールパネルで `dbadmin.example.com` 等のサブドメインを作成し、新しいドキュメントルートディレクトリに向ける
2. GitHub Releases から `php-db-admin-vX.Y.Z.zip` をダウンロードして、Web ルートの外(例: `~/phpdbadmin/`)に展開
3. `phpdbadmin/public/index.php`, `phpdbadmin/public/.htaccess`, `phpdbadmin/public/assets/` をサブドメインのドキュメントルートへコピー
4. コピーした `index.php` 内の `APP_BASE` をアプリ本体のパスに書き換える:

   ```php
   define('APP_BASE', __DIR__ . '/../phpdbadmin');
   // あるいは絶対パスで:
   // define('APP_BASE', '/home/your-user/phpdbadmin');
   ```

5. `config.example.php` を `phpdbadmin/config.php` としてコピーし、DB接続情報 / `OPENAI_API_KEY` 等を記入。ホストで環境変数が設定できる場合は省略可(環境変数が常に優先される)
6. `cache/latte/` にWebサーバから書き込み権限を付与
7. アプリ内「ログイン」は「DB接続情報が通るか」の確認だけで、認証機構ではありません。ローカル以外に公開する場合はサブドメインの `.htaccess` に `.htpasswd` ベースの Basic 認証を追加して二重保護してください

## 使い方

### テーブルを見る
1. ログイン後、左サイドバーからテーブルを選択
2. `Browse` タブ: レコード一覧（50件/ページ、Prev/Next）
3. `Structure` タブ: カラム定義（型、NULL、Key、Default、Extra）
4. `Search Builder` タブ: カラム毎に演算子と値を指定 → `Build SQL & Open in Editor` でSELECT文を組み立てSQLエディタに遷移

### レコード編集
- 一覧画面の `Edit` / `Delete` ボタン
- `+ New Record` で新規作成
- カラム型に応じたinput（INT→number、DATE→date、TEXT→textarea等）

### CSVエクスポート
- Browseタブの `Export CSV` ボタン
- 現在のクエリ結果（全件）をBOM付UTF-8で出力

### SQLエディタ
- サイドバーの `SQL Editor` から起動
- `Ctrl+Enter` (Mac: `Cmd+Enter`) で実行
- DROP / TRUNCATE / DELETE は確認ダイアログ表示
- SELECT結果はテーブル表示、それ以外は影響行数表示

## ディレクトリ構成

```
db-admin/
├── Dockerfile              # マルチステージビルド (php:8.4-cli-alpine)
├── docker-compose.yml
├── composer.json           # flightphp/core + latte/latte
├── public/
│   ├── index.php           # ルーティング + Latte登録
│   ├── router.php          # PHPビルトインサーバ用ルータ
│   └── assets/app.css
└── app/
    ├── controllers/
    │   ├── AuthController.php
    │   ├── DatabaseController.php
    │   ├── TableController.php
    │   └── QueryController.php
    ├── services/
    │   ├── DB.php              # マルチDB対応PDOラッパー
    │   ├── CsvExporter.php
    │   ├── ViewContext.php     # サイドバー用の共通データ
    │   └── FormHelper.php      # カラム型→input type 変換
    └── views/                  # Latteテンプレート
        ├── layout.latte        # 2ペインレイアウト
        ├── login.latte
        ├── databases.latte
        ├── tables.latte
        ├── table_view.latte    # Browse/Structure タブ
        ├── table_form.latte
        ├── query.latte
        └── partials/
            ├── data.latte
            ├── schema.latte
            └── search.latte    # Search Builder のフォーム
```

## URL設計

| メソッド | パス | 説明 |
|---|---|---|
| GET  | `/` | ログイン画面 or DB一覧 |
| POST | `/auth` | ログイン |
| GET  | `/logout` | ログアウト |
| GET  | `/db/{db}` | テーブル一覧 |
| GET  | `/db/{db}/table/{table}` | Browseタブ（`?tab=schema`/`?tab=search` で切替） |
| GET  | `/db/{db}/table/{table}/create` | 新規レコードフォーム |
| POST | `/db/{db}/table/{table}/store` | 新規登録 |
| GET  | `/db/{db}/table/{table}/edit/{id}` | 編集フォーム |
| POST | `/db/{db}/table/{table}/update/{id}` | 更新 |
| POST | `/db/{db}/table/{table}/delete/{id}` | 削除 |
| GET  | `/db/{db}/table/{table}/export` | CSVダウンロード |
| GET  | `/db/{db}/query` | SQLエディタ（`?sql=...` でプリフィル可能） |
| POST | `/db/{db}/query/run` | SQL実行（JSON API） |

## 開発

### ソース変更の反映

`docker-compose.yml` に `./app` / `./public` / `./cache` のボリュームマウントを設定済み。
コードを変更してもコンテナ再起動不要（Latte の `setAutoRefresh(true)` も有効）。

### 依存パッケージのインストール（ホスト側 vendor 生成）

IDE補完用にホスト側に `vendor/` を展開する場合:

```bash
docker run --rm -v "$(pwd):/app" -w /app composer:2 install
```

### ログ確認

```bash
docker compose logs -f app
```

## 非対応 (スコープ外)

- HTTPS対応
- 複数同時ユーザー
- テーブル構造変更（ALTER TABLE）
- インポート機能
- ストアドプロシージャ / ビュー管理

## セキュリティ

- ユーザー入力は**すべてPDOプリペアドステートメント**でバインド
- テーブル名・DB名は**ホワイトリスト検証**後にクエリへ埋込
- Latteの**コンテキスト認識自動エスケープ**でXSS対策（HTML / 属性 / JS / URL）
- セッション固定対策（ログイン時に`session_regenerate_id(true)`）
- **CSRF対策**: 全POSTルートでセッショントークン検証（`csrf_token` フィールド or `X-CSRF-Token` ヘッダ）
- **本番用途は非推奨**（アプリ内認証はDB接続情報の入力のみ）。本番サーバに設置する場合は `.htaccess` Basic認証等で二重保護すること
