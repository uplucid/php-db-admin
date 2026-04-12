<?php

namespace App\services;

class FormHelper
{
    /**
     * Map a DB column type to an HTML input type.
     */
    public static function inputTypeForColumn(string $type): string
    {
        $type = strtolower($type);
        if (preg_match('/int|serial/i', $type)) return 'number';
        if (preg_match('/float|double|decimal|numeric|real/i', $type)) return 'number';
        if (preg_match('/datetime|timestamp/i', $type)) return 'datetime-local';
        if (preg_match('/date$/i', $type)) return 'date';
        if (preg_match('/time$/i', $type)) return 'time';
        if (preg_match('/text|json|blob|clob/i', $type)) return 'textarea';
        return 'text';
    }
}
