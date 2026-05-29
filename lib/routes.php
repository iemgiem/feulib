<?php
declare(strict_types=1);

/**
 * Route table — the allow-list consulted by index.php for every request.
 *
 * Each key is a page token (e.g. "dashboard", "lost.new", "admin.audit") that
 * appears in URLs as `?p=<token>`. Each value declares:
 *
 *   'file'   string  Filename under /pages/ to include for this token.
 *   'public' bool    Optional. If true, no auth check. Default: false.
 *   'roles'  array   Required when not public. Allowed roles: user, staff, admin.
 *
 * To add a new page: append an entry here AND create the corresponding
 * pages/<file>.php. The controller treats any unmapped token as 404, so the
 * allow-list is the source of truth for the public URL surface.
 *
 * NOTE: many tokens here point at files that have not been built yet — those
 * tasks are tracked in .design/lfms/TASKS.md. The controller renders a clear
 * dev-mode message when a mapped token's file is missing.
 */

return [

    // -------------------------------------------------------------------------
    // Public — anyone, including anonymous visitors
    // -------------------------------------------------------------------------
    'login'    => ['file' => 'login.php',    'public' => true],
    'register' => ['file' => 'register.php', 'public' => true],
    'forgot'   => ['file' => 'forgot.php',   'public' => true],
    'logout'   => ['file' => 'logout.php',   'public' => true],

    '403'      => ['file' => '403.php',      'public' => true],
    '404'      => ['file' => '404.php',      'public' => true],
    '500'      => ['file' => '500.php',      'public' => true],

    // -------------------------------------------------------------------------
    // User+ — student/faculty surface (Staff and Admin also see these)
    // -------------------------------------------------------------------------
    'dashboard'        => ['file' => 'dashboard.php',        'roles' => ['user', 'staff', 'admin']],
    'lost'             => ['file' => 'lost.php',             'roles' => ['user', 'staff', 'admin']],
    'lost.new'         => ['file' => 'lost.new.php',         'roles' => ['user', 'staff', 'admin']],
    'lost.created'     => ['file' => 'lost.created.php',     'roles' => ['user', 'staff', 'admin']],
    'lost.show'        => ['file' => 'lost.show.php',        'roles' => ['user', 'staff', 'admin']],
    'notifications'    => ['file' => 'notifications.php',    'roles' => ['user', 'staff', 'admin']],
    'claims'           => ['file' => 'claims.php',           'roles' => ['user', 'staff', 'admin']],
    'claim.new'        => ['file' => 'claim.new.php',        'roles' => ['user', 'staff', 'admin']],
    'claim.show'       => ['file' => 'claim.show.php',       'roles' => ['user', 'staff', 'admin']],
    'profile'          => ['file' => 'profile.php',          'roles' => ['user', 'staff', 'admin']],

    // -------------------------------------------------------------------------
    // Staff+ — library staff surface (Admin also sees these)
    // -------------------------------------------------------------------------
    'staff.dashboard'  => ['file' => 'staff.dashboard.php',  'roles' => ['staff', 'admin']],
    'found'            => ['file' => 'found.php',            'roles' => ['staff', 'admin']],
    'found.new'        => ['file' => 'found.new.php',        'roles' => ['staff', 'admin']],
    'found.created'    => ['file' => 'found.created.php',    'roles' => ['staff', 'admin']],
    'found.show'       => ['file' => 'found.show.php',       'roles' => ['staff', 'admin']],
    'matches'          => ['file' => 'matches.php',          'roles' => ['staff', 'admin']],
    'match.show'       => ['file' => 'match.show.php',       'roles' => ['staff', 'admin']],
    'staff.claims'     => ['file' => 'staff.claims.php',     'roles' => ['staff', 'admin']],
    'release'          => ['file' => 'release.php',          'roles' => ['staff', 'admin']],

    // -------------------------------------------------------------------------
    // Admin only
    // -------------------------------------------------------------------------
    'admin.dashboard'   => ['file' => 'admin.dashboard.php',   'roles' => ['admin']],
    'admin.donate'      => ['file' => 'admin.donate.php',      'roles' => ['admin']],
    'admin.reports'     => ['file' => 'admin.reports.php',     'roles' => ['admin']],
    'admin.report.show' => ['file' => 'admin.report.show.php', 'roles' => ['admin']],
    'admin.audit'       => ['file' => 'admin.audit.php',       'roles' => ['admin']],
    'admin.settings'    => ['file' => 'admin.settings.php',    'roles' => ['admin']],
    'admin.its'         => ['file' => 'admin.its.php',         'roles' => ['admin']],

    // -------------------------------------------------------------------------
    // System endpoints
    // -------------------------------------------------------------------------
    'api.notifications' => ['file' => 'api.notifications.php', 'roles' => ['user', 'staff', 'admin']],
    'serve_upload'      => ['file' => 'serve_upload.php',      'roles' => ['user', 'staff', 'admin']],

    // Mock ITS endpoint — public because external APIs authenticate via
    // header, not session. The handler validates the configured token.
    'api.its_mock'      => ['file' => 'api.its_mock.php',      'public' => true],

    // Admin-only backup download — generates a full SQL dump on demand.
    'api.backup'        => ['file' => 'api.backup.php',        'roles' => ['admin']],
];
