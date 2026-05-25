<?php
/**
 * FEU LFMS — Local configuration template
 *
 * Copy this file to `config.php` in the project root and update the
 * values for your environment. `config.php` is the only file that
 * holds environment-specific secrets; it must never be committed.
 *
 * For XAMPP defaults on Windows, the values below already work — you
 * only need to make sure the `lfms` database exists (see db/README.md).
 */

return [
    'app' => [
        'name'      => 'FEU Library — Lost & Found Management System',
        'env'       => 'development',   // 'development' or 'production'
        'base_url'  => 'http://localhost/feulib',
        'base_path' => '/feulib',       // URL path component; '' if served from docroot
        'timezone'  => 'Asia/Manila',
    ],

    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'lfms',
        'user'    => 'root',            // XAMPP default
        'pass'    => '',                // XAMPP default — set a real password before any non-local deploy
        'charset' => 'utf8mb4',
    ],

    'session' => [
        'name'           => 'LFMSSESSID',
        'lifetime'       => 0,          // 0 = until browser closes; library staff log out at end of shift
        'cookie_secure'  => false,      // set true when serving over HTTPS
        'cookie_httponly'=> true,
        'cookie_samesite'=> 'Strict',
    ],

    'upload' => [
        'max_bytes'      => 4 * 1024 * 1024,                         // 4 MB
        'allowed_mimes'  => ['image/jpeg', 'image/png', 'image/webp'],
        'storage_path'   => __DIR__ . '/assets/uploads',             // absolute path on disk
    ],

    // -------------------------------------------------------------------------
    // ITS (Integrated Tertiary System) — external user directory.
    // The Admin → ITS Integration page and db/sync_its.php pull authoritative
    // student / staff / faculty records from this endpoint into the local
    // its_users table. Defaults point at the in-project mock for development.
    // -------------------------------------------------------------------------
    'its' => [
        // Auth: 'bearer' sends `Authorization: Bearer <auth_value>`.
        //       'api_key' sends `<api_key_header>: <auth_value>`.
        'auth_mode'        => 'bearer',
        'auth_value'       => 'dev-token-change-me-before-production',
        'api_key_header'   => 'X-API-Key',

        // Network
        'timeout_seconds'  => 10,
        'verify_ssl'       => true,

        // Endpoints — full URLs so the mock and real ITS can coexist trivially.
        // The mock validates the same auth header the real ITS would.
        'endpoints' => [
            'students' => 'http://localhost/feulib/index.php?p=api.its_mock&type=student',
            'staff'    => 'http://localhost/feulib/index.php?p=api.its_mock&type=staff',
        ],
    ],
];
