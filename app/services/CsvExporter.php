<?php

namespace App\services;

class CsvExporter
{
    public static function download(string $filename, array $columns, iterable $rows): void
    {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, no-store, must-revalidate');

        $out = fopen('php://output', 'w');

        // BOM for Excel UTF-8 support
        fwrite($out, "\xEF\xBB\xBF");

        fputcsv($out, $columns, ',', '"', '');

        foreach ($rows as $row) {
            fputcsv($out, array_values($row), ',', '"', '');
        }

        fclose($out);
        exit;
    }
}
