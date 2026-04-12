<?php
/**
 * config.example.php — rental-server / shared-hosting config template.
 *
 * Rental hosts often can't set environment variables, so this file acts
 * as a fallback for $_ENV.
 *   1) Copy to config.php (gitignored) at the project root.
 *   2) Fill in the values you need.
 *   3) Real environment variables (Docker env_file, Apache SetEnv,
 *      putenv, etc.) always take precedence over values defined here.
 *
 * Keep config.php OUTSIDE of git and denied by .htaccess (already covered).
 */

return [
    // DB login-form prefill (optional)
    'DB_DRIVER'       => '',
    'DB_HOST'         => '',
    'DB_PORT'         => '',
    'DB_USER'         => '',
    'DB_PASSWORD'     => '',
    'DB_NAME'         => '',
    'DB_PATH'         => '',

    // AI SQL generation (optional)
    'OPENAI_API_KEY'  => '',
    'OPENAI_BASE_URL' => '',
    'OPENAI_MODEL'    => '',
];
