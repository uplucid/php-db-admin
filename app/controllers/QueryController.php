<?php

namespace App\controllers;

use App\services\AiService;
use App\services\DB;
use App\services\ViewContext;

class QueryController
{
    public static function editor(string $db): void
    {
        AuthController::reconnect();
        $driver = DB::getDriver();
        if ($driver !== 'sqlite') {
            if (!DB::validateDatabaseName($db)) {
                \Flight::redirect('/');
                return;
            }
            DB::useDatabase($db);
        }

        $initialSql = (string)($_GET['sql'] ?? '');

        \Flight::render('query.latte', array_merge(
            ViewContext::build($db, null, 'query'),
            [
                'initialSql' => $initialSql,
                'aiEnabled'  => AiService::isConfigured(),
            ]
        ));
    }

    public static function run(string $db): void
    {
        AuthController::reconnect();
        $driver = DB::getDriver();
        if ($driver !== 'sqlite') {
            if (!DB::validateDatabaseName($db)) {
                \Flight::json(['error' => 'Invalid database name']);
                return;
            }
            DB::useDatabase($db);
        }

        $sql = trim($_POST['sql'] ?? '');
        if ($sql === '') {
            \Flight::json(['error' => 'Empty query']);
            return;
        }

        $pdo = DB::getPdo();

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute();

            $columnCount = $stmt->columnCount();
            if ($columnCount > 0) {
                $rows = $stmt->fetchAll();
                $columns = [];
                $metas   = [];
                for ($i = 0; $i < $columnCount; $i++) {
                    $meta = $stmt->getColumnMeta($i);
                    $columns[] = $meta['name'];
                    $metas[$meta['name']] = $meta;
                }
                foreach ($rows as &$row) {
                    foreach ($row as $name => &$val) {
                        if (is_string($val) && self::looksBinary($val, $metas[$name] ?? [])) {
                            $type = $metas[$name]['native_type'] ?? 'BINARY';
                            $val = ['__binary' => true, 'size' => strlen($val), 'type' => $type];
                        }
                    }
                    unset($val);
                }
                unset($row);
                \Flight::json([
                    'type'    => 'select',
                    'columns' => $columns,
                    'rows'    => $rows,
                    'count'   => count($rows),
                ]);
            } else {
                $affected = $stmt->rowCount();
                \Flight::json([
                    'type'     => 'execute',
                    'affected' => $affected,
                ]);
            }
        } catch (\PDOException $e) {
            \Flight::json(['error' => $e->getMessage()]);
        }
    }

    private static function looksBinary(string $val, array $meta): bool
    {
        $native = strtoupper((string)($meta['native_type'] ?? ''));
        if (str_contains($native, 'BLOB') || str_contains($native, 'BYTEA') || $native === 'BIT') {
            return true;
        }
        if ($val === '') return false;
        if (str_contains($val, "\0")) return true;
        return !mb_check_encoding($val, 'UTF-8');
    }
}
