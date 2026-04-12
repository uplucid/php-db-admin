<?php

namespace App\services;

use PDO;
use PDOException;
use InvalidArgumentException;

class DB
{
    private static ?PDO $pdo = null;
    private static string $driver = '';
    private static string $currentDb = '';

    public static function connect(array $cfg): void
    {
        $driver = $cfg['driver'] ?? '';
        $dsn = match ($driver) {
            'mysql'  => "mysql:host={$cfg['host']};port={$cfg['port']};charset=utf8mb4",
            'pgsql'  => "pgsql:host={$cfg['host']};port={$cfg['port']}",
            'sqlite' => "sqlite:{$cfg['path']}",
            default  => throw new InvalidArgumentException("Unsupported driver: {$driver}")
        };

        $user = $cfg['user'] ?? null;
        $pass = $cfg['pass'] ?? null;

        self::$pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        self::$driver = $driver;
        self::$currentDb = '';
    }

    public static function getDriver(): string
    {
        return self::$driver;
    }

    public static function getPdo(): PDO
    {
        if (self::$pdo === null) {
            throw new \RuntimeException('Not connected');
        }
        return self::$pdo;
    }

    public static function useDatabase(string $db): void
    {
        $pdo = self::getPdo();
        switch (self::$driver) {
            case 'mysql':
                $pdo->exec("USE `" . str_replace('`', '``', $db) . "`");
                break;
            case 'pgsql':
                // PostgreSQL requires reconnection to change database
                $cfg = $_SESSION['db_config'] ?? [];
                $dsn = "pgsql:host={$cfg['host']};port={$cfg['port']};dbname={$db}";
                self::$pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]);
                break;
            case 'sqlite':
                // SQLite: file = database, no switching needed
                break;
        }
        self::$currentDb = $db;
    }

    public static function databases(): array
    {
        $pdo = self::getPdo();
        return match (self::$driver) {
            'mysql'  => $pdo->query("SHOW DATABASES")->fetchAll(PDO::FETCH_COLUMN),
            'pgsql'  => $pdo->query("SELECT datname FROM pg_database WHERE datistemplate = false ORDER BY datname")->fetchAll(PDO::FETCH_COLUMN),
            'sqlite' => [],
            default  => []
        };
    }

    public static function tables(): array
    {
        $pdo = self::getPdo();
        return match (self::$driver) {
            'mysql'  => $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN),
            'pgsql'  => $pdo->query("SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename")->fetchAll(PDO::FETCH_COLUMN),
            'sqlite' => $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN),
            default  => []
        };
    }

    public static function describe(string $table): array
    {
        $pdo = self::getPdo();

        switch (self::$driver) {
            case 'mysql':
                $stmt = $pdo->query("DESCRIBE `" . str_replace('`', '``', $table) . "`");
                $rows = $stmt->fetchAll();
                $result = [];
                foreach ($rows as $row) {
                    $result[] = [
                        'Field'   => $row['Field'],
                        'Type'    => $row['Type'],
                        'Null'    => $row['Null'],
                        'Key'     => $row['Key'] === 'PRI' ? 'PRI' : '',
                        'Default' => $row['Default'],
                        'Extra'   => $row['Extra'] ?? '',
                    ];
                }
                return $result;

            case 'pgsql':
                $stmt = $pdo->prepare("
                    SELECT
                        c.column_name,
                        c.data_type,
                        c.is_nullable,
                        c.column_default,
                        CASE WHEN pk.column_name IS NOT NULL THEN 'PRI' ELSE '' END AS key
                    FROM information_schema.columns c
                    LEFT JOIN (
                        SELECT ku.column_name
                        FROM information_schema.table_constraints tc
                        JOIN information_schema.key_column_usage ku
                            ON tc.constraint_name = ku.constraint_name
                        WHERE tc.constraint_type = 'PRIMARY KEY'
                          AND ku.table_name = :table1
                    ) pk ON c.column_name = pk.column_name
                    WHERE c.table_name = :table2
                      AND c.table_schema = 'public'
                    ORDER BY c.ordinal_position
                ");
                $stmt->execute(['table1' => $table, 'table2' => $table]);
                $rows = $stmt->fetchAll();
                $result = [];
                foreach ($rows as $row) {
                    $extra = '';
                    if ($row['column_default'] !== null && str_starts_with($row['column_default'], 'nextval(')) {
                        $extra = 'auto_increment';
                    }
                    $result[] = [
                        'Field'   => $row['column_name'],
                        'Type'    => $row['data_type'],
                        'Null'    => $row['is_nullable'],
                        'Key'     => $row['key'],
                        'Default' => $row['column_default'],
                        'Extra'   => $extra,
                    ];
                }
                return $result;

            case 'sqlite':
                $stmt = $pdo->query("PRAGMA table_info(" . self::quoteIdentifier($table) . ")");
                $rows = $stmt->fetchAll();
                $result = [];
                foreach ($rows as $row) {
                    $result[] = [
                        'Field'   => $row['name'],
                        'Type'    => $row['type'] ?: 'TEXT',
                        'Null'    => $row['notnull'] ? 'NO' : 'YES',
                        'Key'     => $row['pk'] ? 'PRI' : '',
                        'Default' => $row['dflt_value'],
                        'Extra'   => '',
                    ];
                }
                return $result;

            default:
                return [];
        }
    }

    public static function getPrimaryKey(string $table): ?string
    {
        $columns = self::describe($table);
        foreach ($columns as $col) {
            if ($col['Key'] === 'PRI') {
                return $col['Field'];
            }
        }
        return null;
    }

    public static function validateTableName(string $table): bool
    {
        $tables = self::tables();
        return in_array($table, $tables, true);
    }

    public static function validateDatabaseName(string $db): bool
    {
        if (self::$driver === 'sqlite') {
            return true;
        }
        $databases = self::databases();
        return in_array($db, $databases, true);
    }

    private static function quoteIdentifier(string $identifier): string
    {
        return match (self::$driver) {
            'mysql'  => '`' . str_replace('`', '``', $identifier) . '`',
            'pgsql'  => '"' . str_replace('"', '""', $identifier) . '"',
            'sqlite' => '"' . str_replace('"', '""', $identifier) . '"',
            default  => $identifier
        };
    }

    public static function qi(string $identifier): string
    {
        return self::quoteIdentifier($identifier);
    }

    public static function disconnect(): void
    {
        self::$pdo = null;
        self::$driver = '';
        self::$currentDb = '';
    }
}
