<?php

namespace App\controllers;

use App\services\AiService;
use App\services\DB;
use App\services\CsvExporter;
use App\services\ViewContext;

class TableController
{
    private static function setupDb(string $db): void
    {
        AuthController::reconnect();
        $driver = DB::getDriver();
        if ($driver !== 'sqlite') {
            if (!DB::validateDatabaseName($db)) {
                throw new \RuntimeException('Invalid database name');
            }
            DB::useDatabase($db);
        }
    }

    public static function index(string $db): void
    {
        self::setupDb($db);
        $tables = DB::tables();
        \Flight::render('tables.latte', array_merge(
            ViewContext::build($db, null, 'tables'),
            [
                'tables'    => $tables,
                'aiEnabled' => AiService::isConfigured(),
            ]
        ));
    }

    public static function view(string $db, string $table): void
    {
        self::setupDb($db);
        if (!DB::validateTableName($table)) {
            \Flight::redirect('/db/' . urlencode($db));
            return;
        }

        $tabRaw = $_GET['tab'] ?? '';
        $tab = match ($tabRaw) {
            'schema' => 'schema',
            'search' => 'search',
            default  => 'data',
        };
        $columns = DB::describe($table);
        $pk      = DB::getPrimaryKey($table);

        $data = array_merge(
            ViewContext::build($db, $table, 'table'),
            [
                'tab'     => $tab,
                'columns' => $columns,
                'pk'      => $pk,
                'rows'    => [],
                'page'    => 0,
                'totalPages' => 1,
                'total'   => 0,
                'perPage' => 50,
                'searchOps'   => [],
                'searchVals'  => [],
                'searchError' => null,
            ]
        );

        if ($tab === 'data') {
            $page    = max(0, (int)($_GET['page'] ?? 0));
            $perPage = 50;
            $offset  = $page * $perPage;

            $pdo = DB::getPdo();
            $qi  = DB::qi($table);
            $total = (int)$pdo->query("SELECT COUNT(*) FROM {$qi}")->fetchColumn();
            $totalPages = max(1, (int)ceil($total / $perPage));

            $stmt = $pdo->query("SELECT * FROM {$qi} LIMIT {$perPage} OFFSET {$offset}");
            $rows = $stmt->fetchAll();

            $data['page']       = $page;
            $data['perPage']    = $perPage;
            $data['totalPages'] = $totalPages;
            $data['total']      = $total;
            $data['rows']       = $rows;
        } elseif ($tab === 'search') {
            $ops  = is_array($_GET['op'] ?? null) ? $_GET['op'] : [];
            $vals = is_array($_GET['q']  ?? null) ? $_GET['q']  : [];
            $data['searchOps']  = $ops;
            $data['searchVals'] = $vals;

            $hasAny = false;
            foreach ($ops as $v) {
                if ($v !== '' && $v !== null) { $hasAny = true; break; }
            }

            if ($hasAny) {
                try {
                    $sql = self::buildSearchSql(DB::getPdo(), $table, $columns, $ops, $vals);
                    \Flight::redirect('/db/' . urlencode($db) . '/query?sql=' . urlencode($sql));
                    return;
                } catch (\Throwable $e) {
                    $data['searchError'] = $e->getMessage();
                }
            }
        }

        \Flight::render('table_view.latte', $data);
    }

    /**
     * Build a ready-to-run SELECT statement from per-column operator/value pairs.
     * Column names are validated against describe(); values are quoted with PDO::quote.
     */
    private static function buildSearchSql(\PDO $pdo, string $table, array $columns, array $ops, array $vals): string
    {
        $valid = [];
        foreach ($columns as $c) {
            $valid[$c['Field']] = true;
        }

        $allowed = ['=', '!=', '<', '<=', '>', '>=', 'LIKE', 'LIKE %...%', 'NOT LIKE', 'IS NULL', 'IS NOT NULL'];

        $clauses = [];
        foreach ($ops as $field => $op) {
            if ($op === '' || $op === null) continue;
            if (!isset($valid[$field])) {
                throw new \RuntimeException("Unknown column: {$field}");
            }
            if (!in_array($op, $allowed, true)) {
                throw new \RuntimeException("Unsupported operator: {$op}");
            }
            $qcol = DB::qi($field);

            if ($op === 'IS NULL' || $op === 'IS NOT NULL') {
                $clauses[] = "{$qcol} {$op}";
                continue;
            }

            $val = $vals[$field] ?? '';
            if ($val === '') continue;

            if ($op === 'LIKE %...%') {
                $clauses[] = "{$qcol} LIKE " . $pdo->quote('%' . $val . '%');
            } else {
                $clauses[] = "{$qcol} {$op} " . $pdo->quote($val);
            }
        }

        $qt  = DB::qi($table);
        $sql = "SELECT * FROM {$qt}";
        if (!empty($clauses)) {
            $sql .= "\nWHERE " . implode("\n  AND ", $clauses);
        }
        return $sql;
    }

    public static function createForm(string $db, string $table): void
    {
        self::setupDb($db);
        if (!DB::validateTableName($table)) {
            \Flight::redirect('/db/' . urlencode($db));
            return;
        }

        $columns = DB::describe($table);

        \Flight::render('table_form.latte', array_merge(
            ViewContext::build($db, $table, 'table'),
            [
                'columns' => $columns,
                'row'     => null,
                'mode'    => 'create',
                'pkValue' => null,
            ]
        ));
    }

    public static function store(string $db, string $table): void
    {
        self::setupDb($db);
        if (!DB::validateTableName($table)) {
            \Flight::redirect('/db/' . urlencode($db));
            return;
        }

        $columns = DB::describe($table);
        $pdo     = DB::getPdo();
        $fields  = [];
        $placeholders = [];
        $values  = [];

        foreach ($columns as $col) {
            $field = $col['Field'];
            if (stripos($col['Extra'], 'auto_increment') !== false) {
                continue;
            }
            if (!isset($_POST['col'][$field])) {
                continue;
            }
            $val = $_POST['col'][$field];
            if ($val === '' && $col['Null'] === 'YES') {
                $val = null;
            }
            $fields[]       = DB::qi($field);
            $placeholders[] = '?';
            $values[]       = $val;
        }

        $qi  = DB::qi($table);
        $sql = "INSERT INTO {$qi} (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($values);

        \Flight::redirect('/db/' . urlencode($db) . '/table/' . urlencode($table));
    }

    public static function editForm(string $db, string $table, string $id): void
    {
        self::setupDb($db);
        if (!DB::validateTableName($table)) {
            \Flight::redirect('/db/' . urlencode($db));
            return;
        }

        $pk = DB::getPrimaryKey($table);
        if ($pk === null) {
            \Flight::redirect('/db/' . urlencode($db) . '/table/' . urlencode($table));
            return;
        }

        $pdo  = DB::getPdo();
        $qi   = DB::qi($table);
        $qpk  = DB::qi($pk);
        $stmt = $pdo->prepare("SELECT * FROM {$qi} WHERE {$qpk} = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        if (!$row) {
            \Flight::redirect('/db/' . urlencode($db) . '/table/' . urlencode($table));
            return;
        }

        $columns = DB::describe($table);

        \Flight::render('table_form.latte', array_merge(
            ViewContext::build($db, $table, 'table'),
            [
                'columns' => $columns,
                'row'     => $row,
                'mode'    => 'edit',
                'pkValue' => $id,
            ]
        ));
    }

    public static function update(string $db, string $table, string $id): void
    {
        self::setupDb($db);
        if (!DB::validateTableName($table)) {
            \Flight::redirect('/db/' . urlencode($db));
            return;
        }

        $pk = DB::getPrimaryKey($table);
        if ($pk === null) {
            \Flight::redirect('/db/' . urlencode($db) . '/table/' . urlencode($table));
            return;
        }

        $columns = DB::describe($table);
        $pdo     = DB::getPdo();
        $sets    = [];
        $values  = [];

        foreach ($columns as $col) {
            $field = $col['Field'];
            if ($field === $pk) {
                continue;
            }
            if (!isset($_POST['col'][$field])) {
                continue;
            }
            $val = $_POST['col'][$field];
            if ($val === '' && $col['Null'] === 'YES') {
                $val = null;
            }
            $sets[]   = DB::qi($field) . ' = ?';
            $values[] = $val;
        }

        $values[] = $id;
        $qi  = DB::qi($table);
        $qpk = DB::qi($pk);
        $sql = "UPDATE {$qi} SET " . implode(', ', $sets) . " WHERE {$qpk} = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($values);

        \Flight::redirect('/db/' . urlencode($db) . '/table/' . urlencode($table));
    }

    public static function delete(string $db, string $table, string $id): void
    {
        self::setupDb($db);
        if (!DB::validateTableName($table)) {
            \Flight::redirect('/db/' . urlencode($db));
            return;
        }

        $pk = DB::getPrimaryKey($table);
        if ($pk === null) {
            \Flight::redirect('/db/' . urlencode($db) . '/table/' . urlencode($table));
            return;
        }

        $pdo  = DB::getPdo();
        $qi   = DB::qi($table);
        $qpk  = DB::qi($pk);
        $stmt = $pdo->prepare("DELETE FROM {$qi} WHERE {$qpk} = ?");
        $stmt->execute([$id]);

        \Flight::redirect('/db/' . urlencode($db) . '/table/' . urlencode($table));
    }

    /**
     * Export current query results (same filter/page-agnostic; exports all matching rows).
     * Preserves future filter/sort params from the data view.
     */
    public static function export(string $db, string $table): void
    {
        self::setupDb($db);
        if (!DB::validateTableName($table)) {
            \Flight::redirect('/db/' . urlencode($db));
            return;
        }

        $pdo     = DB::getPdo();
        $qi      = DB::qi($table);
        $columns = DB::describe($table);
        $colNames = array_map(fn($c) => $c['Field'], $columns);

        // Same base query as the data view — currently unfiltered,
        // but designed so future WHERE/ORDER BY from $_GET can be applied here.
        $stmt = $pdo->query("SELECT * FROM {$qi}");
        $rows = $stmt->fetchAll();

        $filename = $table . '_' . date('Ymd_His') . '.csv';
        CsvExporter::download($filename, $colNames, $rows);
    }
}
