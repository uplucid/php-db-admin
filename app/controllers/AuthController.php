<?php

namespace App\controllers;

use App\services\Csrf;
use App\services\DB;

class AuthController
{
    public static function loginPage(): void
    {
        $envConfig = [
            'driver'   => $_ENV['DB_DRIVER'] ?? '',
            'host'     => $_ENV['DB_HOST'] ?? '',
            'port'     => $_ENV['DB_PORT'] ?? '',
            'user'     => $_ENV['DB_USER'] ?? '',
            'password' => $_ENV['DB_PASSWORD'] ?? '',
            'name'     => $_ENV['DB_NAME'] ?? '',
            'path'     => $_ENV['DB_PATH'] ?? '',
        ];

        $error = $_SESSION['login_error'] ?? '';
        unset($_SESSION['login_error']);

        \Flight::render('login.latte', [
            'envConfig' => $envConfig,
            'error'     => $error,
            'csrfToken' => Csrf::token(),
        ]);
    }

    public static function login(): void
    {
        $driver = $_POST['driver'] ?? '';
        $host   = $_POST['host'] ?? '';
        $port   = $_POST['port'] ?? '';
        $user   = $_POST['user'] ?? '';
        $pass   = $_POST['pass'] ?? '';
        $dbname = $_POST['dbname'] ?? '';
        $path   = $_POST['path'] ?? '';

        if (!in_array($driver, ['mysql', 'pgsql', 'sqlite'], true)) {
            $_SESSION['login_error'] = 'Invalid driver selected.';
            \Flight::redirect('/');
            return;
        }

        $cfg = [
            'driver' => $driver,
            'host'   => $host,
            'port'   => $port ?: ($driver === 'mysql' ? '3306' : '5432'),
            'user'   => $user,
            'pass'   => $pass,
            'path'   => $path,
        ];

        try {
            DB::connect($cfg);

            if ($driver !== 'sqlite' && $dbname !== '') {
                DB::useDatabase($dbname);
            }

            DB::disconnect();

            session_regenerate_id(true);
            $_SESSION['db_config'] = $cfg;
            $_SESSION['authenticated'] = true;

            if ($driver === 'sqlite') {
                \Flight::redirect('/db/_sqlite');
            } elseif ($dbname !== '') {
                \Flight::redirect('/db/' . urlencode($dbname));
            } else {
                \Flight::redirect('/');
            }
        } catch (\PDOException $e) {
            $_SESSION['login_error'] = 'Connection failed: ' . $e->getMessage();
            \Flight::redirect('/');
        }
    }

    public static function logout(): void
    {
        DB::disconnect();
        session_destroy();
        \Flight::redirect('/');
    }

    public static function ensureAuth(): void
    {
        $requestUrl = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $publicRoutes = ['/', '/auth'];

        if (in_array($requestUrl, $publicRoutes, true) || $requestUrl === '/logout') {
            return;
        }

        if (empty($_SESSION['authenticated'])) {
            \Flight::redirect('/');
            \Flight::stop();
        }
    }

    public static function reconnect(): void
    {
        $cfg = $_SESSION['db_config'] ?? null;
        if ($cfg === null) {
            throw new \RuntimeException('No database configuration in session');
        }
        DB::connect($cfg);
    }
}
