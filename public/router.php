<?php

/**
 * Router script for PHP built-in server.
 * Serves static files directly, routes everything else to index.php.
 */
if (php_sapi_name() === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $file = __DIR__ . $path;
    if ($path !== '/' && is_file($file)) {
        return false; // Serve static files
    }
}

require __DIR__ . '/index.php';
