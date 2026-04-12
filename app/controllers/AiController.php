<?php

namespace App\controllers;

use App\services\AiService;
use App\services\DB;

class AiController
{
    public static function generate(string $db): void
    {
        if (!AiService::isConfigured()) {
            \Flight::json(['error' => 'AI is not configured (OPENAI_API_KEY missing).']);
            return;
        }

        AuthController::reconnect();
        $driver = DB::getDriver();
        if ($driver !== 'sqlite') {
            if (!DB::validateDatabaseName($db)) {
                \Flight::json(['error' => 'Invalid database name']);
                return;
            }
            DB::useDatabase($db);
        }

        $prompt = trim($_POST['prompt'] ?? '');
        if ($prompt === '') {
            \Flight::json(['error' => 'Empty prompt']);
            return;
        }

        $requested = $_POST['tables'] ?? [];
        if (!is_array($requested)) {
            $requested = [];
        }

        $allTables = DB::tables();
        $allowed   = array_values(array_intersect($requested, $allTables));

        $selectedSchemas = [];
        foreach ($allowed as $t) {
            try {
                $selectedSchemas[] = ['table' => $t, 'columns' => DB::describe($t)];
            } catch (\Throwable) {
                // skip tables we can't describe
            }
        }

        try {
            $markdown = AiService::generate($prompt, $driver, $db, $allTables, $selectedSchemas);
            \Flight::json(['markdown' => $markdown]);
        } catch (\Throwable $e) {
            \Flight::json(['error' => $e->getMessage()]);
        }
    }

    public static function ask(string $db): void
    {
        if (!AiService::isConfigured()) {
            \Flight::json(['error' => 'AI is not configured (OPENAI_API_KEY missing).']);
            return;
        }

        AuthController::reconnect();
        $driver = DB::getDriver();
        if ($driver !== 'sqlite') {
            if (!DB::validateDatabaseName($db)) {
                \Flight::json(['error' => 'Invalid database name']);
                return;
            }
            DB::useDatabase($db);
        }

        $prompt = trim($_POST['prompt'] ?? '');
        if ($prompt === '') {
            \Flight::json(['error' => 'Empty prompt']);
            return;
        }

        $tableSchemas = [];
        $pdo = DB::getPdo();
        foreach (DB::tables() as $t) {
            $row = ['table' => $t, 'columns' => [], 'rowCount' => null];
            try {
                $row['columns'] = DB::describe($t);
            } catch (\Throwable) {
                // skip description failure, continue with empty columns
            }
            try {
                $row['rowCount'] = (int)$pdo->query("SELECT COUNT(*) FROM " . DB::qi($t))->fetchColumn();
            } catch (\Throwable) {
                // leave rowCount null
            }
            $tableSchemas[] = $row;
        }

        try {
            $markdown = AiService::ask($prompt, $driver, $db, $tableSchemas);
            \Flight::json(['markdown' => $markdown]);
        } catch (\Throwable $e) {
            \Flight::json(['error' => $e->getMessage()]);
        }
    }
}
