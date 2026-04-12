<?php

namespace App\services;

/**
 * Builds data common to all authenticated views (sidebar content).
 */
class ViewContext
{
    /**
     * @return array{driver:string,allDatabases:array,sidebarTables:array,currentDb:?string,currentTable:?string,currentPage:?string}
     */
    public static function build(?string $currentDb = null, ?string $currentTable = null, ?string $currentPage = null): array
    {
        $driver = DB::getDriver();
        $allDatabases = [];
        $sidebarTables = [];

        if ($driver !== 'sqlite') {
            try {
                $allDatabases = DB::databases();
            } catch (\Throwable) {
                $allDatabases = [];
            }
        }

        if ($currentDb !== null) {
            try {
                $sidebarTables = DB::tables();
            } catch (\Throwable) {
                $sidebarTables = [];
            }
        }

        return [
            'driver'         => $driver,
            'allDatabases'   => $allDatabases,
            'sidebarTables'  => $sidebarTables,
            'currentDb'      => $currentDb,
            'currentTable'   => $currentTable,
            'currentPage'    => $currentPage,
            'csrfToken'      => Csrf::token(),
        ];
    }
}
