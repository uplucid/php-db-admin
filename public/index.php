<?php

use App\controllers\AuthController;
use App\controllers\DatabaseController;
use App\controllers\TableController;
use App\controllers\QueryController;
use App\controllers\AiController;
use App\services\Csrf;

// ---------------------------------------------------------------
// APP_BASE = the directory that contains app/, vendor/, cache/,
// and (optionally) config.php. Defaults to the directory above
// this file, matching the repo layout.
//
// Shared-hosting deployment: if you have to keep this index.php
// separated from the rest of the app (e.g. this file lives in
// your web root but app/, vendor/, cache/ live above it for
// safety), edit the line below to point at that directory. E.g.
//   define('APP_BASE', __DIR__ . '/../../phpdbadmin');
//   define('APP_BASE', '/home/your-user/phpdbadmin');
// ---------------------------------------------------------------
define('APP_BASE', dirname(__DIR__));

require APP_BASE . '/vendor/autoload.php';

// Load config.php fallback for hosts that can't set env vars (shared hosting).
// Real environment variables always take precedence over the file.
$configPath = APP_BASE . '/config.php';
if (is_file($configPath)) {
    $config = require $configPath;
    if (is_array($config)) {
        foreach ($config as $k => $v) {
            if (!isset($_ENV[$k]) && getenv($k) === false) {
                $_ENV[$k] = (string)$v;
            }
        }
    }
}

session_start();

// View directory
Flight::set('flight.views.path', APP_BASE . '/app/views');

// Latte renderer
Flight::map('render', function (string $template, array $data, ?string $block = null): void {
    static $latte = null;
    if ($latte === null) {
        $latte = new Latte\Engine();
        $latte->setTempDirectory(APP_BASE . '/cache/latte');
        $latte->setAutoRefresh(true);
    }
    $finalPath = Flight::get('flight.views.path') . '/' . $template;
    $latte->render($finalPath, $data, $block);
});

// CSRF guard (runs before auth so a POST with a bad token never touches a handler)
Flight::before('start', function (&$params, &$output) {
    Csrf::enforce();
});

// Auth middleware
Flight::before('start', function (&$params, &$output) {
    AuthController::ensureAuth();
});

// --- Routes ---

Flight::route('GET /', function () {
    if (!empty($_SESSION['authenticated'])) {
        DatabaseController::index();
    } else {
        AuthController::loginPage();
    }
});

Flight::route('POST /auth', function () {
    AuthController::login();
});

Flight::route('GET /logout', function () {
    AuthController::logout();
});

// Database > Tables (sidebar shows tables, main shows table list/welcome)
Flight::route('GET /db/@db', function (string $db) {
    TableController::index($db);
});

// Table > Records / Schema (tab via ?tab=schema|data)
Flight::route('GET /db/@db/table/@table', function (string $db, string $table) {
    TableController::view($db, $table);
});

// Create / Edit
Flight::route('GET /db/@db/table/@table/create', function (string $db, string $table) {
    TableController::createForm($db, $table);
});

Flight::route('POST /db/@db/table/@table/store', function (string $db, string $table) {
    TableController::store($db, $table);
});

Flight::route('GET /db/@db/table/@table/edit/@id', function (string $db, string $table, string $id) {
    TableController::editForm($db, $table, $id);
});

Flight::route('POST /db/@db/table/@table/update/@id', function (string $db, string $table, string $id) {
    TableController::update($db, $table, $id);
});

Flight::route('POST /db/@db/table/@table/delete/@id', function (string $db, string $table, string $id) {
    TableController::delete($db, $table, $id);
});

// CSV Export — exports current query (with same filters as data view)
Flight::route('GET /db/@db/table/@table/export', function (string $db, string $table) {
    TableController::export($db, $table);
});

// SQL Editor
Flight::route('GET /db/@db/query', function (string $db) {
    QueryController::editor($db);
});

Flight::route('POST /db/@db/query/run', function (string $db) {
    QueryController::run($db);
});

// AI SQL generation
Flight::route('POST /db/@db/ai/generate', function (string $db) {
    AiController::generate($db);
});

// AI DB Q&A
Flight::route('POST /db/@db/ai/ask', function (string $db) {
    AiController::ask($db);
});

Flight::start();
