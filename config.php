<?php
/**
 * FEU LFMS — Configuration
 *
 * Values are read from environment variables when set (Railway / production),
 * with XAMPP dev defaults as fallbacks so local development needs no changes.
 *
 * Environment variables to set on Railway:
 *   APP_ENV                 production
 *   APP_BASE_URL            https://your-app.up.railway.app
 *   APP_BASE_PATH           (leave empty — served from docroot on Railway)
 *   DB_HOST                 ${{MySQL.MYSQL_HOST}}
 *   DB_PORT                 ${{MySQL.MYSQL_PORT}}
 *   DB_NAME                 ${{MySQL.MYSQL_DATABASE}}
 *   DB_USER                 ${{MySQL.MYSQL_USER}}
 *   DB_PASS                 ${{MySQL.MYSQL_PASSWORD}}
 *   SESSION_COOKIE_SECURE   true
 *   UPLOAD_STORAGE_PATH     /data/uploads   (Railway persistent volume mount)
 *   ITS_AUTH_VALUE          your-real-its-token
 *   ITS_ENDPOINT_STUDENTS   https://its.feu.edu.ph/api/students
 *   ITS_ENDPOINT_STAFF      https://its.feu.edu.ph/api/staff
 */

return [
    'app' => [
        'name'      => 'FEU Library — Lost & Found Management System',
        'env'       => getenv('APP_ENV')      ?: 'development',
        'base_url'  => getenv('APP_BASE_URL') ?: 'http://localhost/feulib',
        // APP_BASE_PATH can be set to '' (empty string) on Railway — use !== false
        // so an explicit empty value isn't overridden by the XAMPP default.
        'base_path' => getenv('APP_BASE_PATH') !== false ? (string) getenv('APP_BASE_PATH') : '/feulib',
        'timezone'  => 'Asia/Manila',
    ],

    'db' => [
        'host'    => getenv('DB_HOST') ?: '127.0.0.1',
        'port'    => (int) (getenv('DB_PORT') ?: 3306),
        'name'    => getenv('DB_NAME') ?: 'lfms',
        'user'    => getenv('DB_USER') ?: 'root',
        // DB_PASS can legitimately be empty — use !== false so '' isn't overridden.
        'pass'    => getenv('DB_PASS') !== false ? (string) getenv('DB_PASS') : '',
        'charset' => 'utf8mb4',
    ],

    'session' => [
        'name'            => 'LFMSSESSID',
        'lifetime'        => 0,
        'cookie_secure'   => filter_var(getenv('SESSION_COOKIE_SECURE') ?: 'false', FILTER_VALIDATE_BOOLEAN),
        'cookie_httponly' => true,
        'cookie_samesite' => 'Strict',
    ],

    'upload' => [
        'max_bytes'     => 4 * 1024 * 1024,
        'allowed_mimes' => ['image/jpeg', 'image/png', 'image/webp'],
        // On Railway set UPLOAD_STORAGE_PATH=/data/uploads (persistent volume).
        // Locally falls back to assets/uploads inside the project.
        'storage_path'  => getenv('UPLOAD_STORAGE_PATH') ?: __DIR__ . '/assets/uploads',
    ],

    'its' => [
        'auth_mode'       => getenv('ITS_AUTH_MODE') ?: 'bearer',
        'auth_value'      => getenv('ITS_AUTH_VALUE') ?: 'dev-token-change-me-before-production',
        'api_key_header'  => 'X-API-Key',
        'timeout_seconds' => 10,
        'verify_ssl'      => true,
        'endpoints' => [
            'students' => getenv('ITS_ENDPOINT_STUDENTS') ?: 'http://localhost/feulib/index.php?p=api.its_mock&type=student',
            'staff'    => getenv('ITS_ENDPOINT_STAFF')    ?: 'http://localhost/feulib/index.php?p=api.its_mock&type=staff',
        ],
    ],
];
