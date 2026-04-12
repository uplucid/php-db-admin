<?php

namespace App\controllers;

use App\services\DB;
use App\services\ViewContext;

class DatabaseController
{
    public static function index(): void
    {
        AuthController::reconnect();
        $driver = DB::getDriver();

        if ($driver === 'sqlite') {
            \Flight::redirect('/db/_sqlite');
            return;
        }

        $databases = DB::databases();
        \Flight::render('databases.latte', array_merge(
            ViewContext::build(null, null, 'databases'),
            ['databases' => $databases]
        ));
    }
}
