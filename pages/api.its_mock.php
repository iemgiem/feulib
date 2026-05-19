<?php
declare(strict_types=1);

/**
 * Mock ITS endpoint — stands in for the real external Integrated Tertiary
 * System during development. Returns a sample roster of students or staff
 * depending on `?type=student|staff`.
 *
 *   GET ?p=api.its_mock&type=student   List of students
 *   GET ?p=api.its_mock&type=staff     List of staff + faculty
 *
 * Validates the same Authorization / X-API-Key header that the real ITS
 * would, so the integration code path is exercised end-to-end. The
 * expected token comes from `its.auth_value` in config.php — change it
 * before any production deploy.
 *
 * This endpoint is registered as public in lib/routes.php because external
 * APIs are stateless and authenticate via header, not session.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// -----------------------------------------------------------------------------
// Validate the inbound auth header against config
// -----------------------------------------------------------------------------
$mode     = (string) (cfg('its.auth_mode')  ?? 'bearer');
$expected = (string) (cfg('its.auth_value') ?? '');

// Apache + mod_php strips the `Authorization` header from $_SERVER by default,
// so we have to try several fallbacks. The order below is "cheapest first" —
// in production behind a configured Apache, the very first lookup wins.
$read_request_header = static function (string $name): string {
    $server_key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    $value = (string) ($_SERVER[$server_key] ?? '');
    if ($value !== '') {
        return $value;
    }
    // Some PHP-FPM / CGI setups expose Authorization only via REDIRECT_*.
    $redirect_key = 'REDIRECT_' . $server_key;
    $value = (string) ($_SERVER[$redirect_key] ?? '');
    if ($value !== '') {
        return $value;
    }
    // Last resort: SAPI header table. getallheaders() is case-insensitive on
    // the keys it returns, but we iterate to stay portable across SAPIs.
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            foreach ($headers as $k => $v) {
                if (strcasecmp((string) $k, $name) === 0) {
                    return (string) $v;
                }
            }
        }
    }
    return '';
};

$provided = '';
if ($mode === 'bearer') {
    $auth = $read_request_header('Authorization');
    if (stripos($auth, 'Bearer ') === 0) {
        $provided = trim(substr($auth, 7));
    }
} elseif ($mode === 'api_key') {
    $header_name = (string) (cfg('its.api_key_header') ?? 'X-API-Key');
    $provided    = $read_request_header($header_name);
}

if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// -----------------------------------------------------------------------------
// Fixed mock data — change freely; the integration normalises whatever
// shape it gets.
// -----------------------------------------------------------------------------
$students = [
    ['id' => '2023-00001', 'name' => 'Juan Dela Cruz',  'role' => 'student', 'email' => 'juan.delacruz@feu.edu.ph',  'status' => 'active'],
    ['id' => '2023-00002', 'name' => 'Maria Santos',    'role' => 'student', 'email' => 'maria.santos@feu.edu.ph',   'status' => 'active'],
    ['id' => '2023-00003', 'name' => 'Pedro Gomez',     'role' => 'student', 'email' => 'pedro.gomez@feu.edu.ph',    'status' => 'active'],
    ['id' => '2023-00004', 'name' => 'Ana Lopez',       'role' => 'student', 'email' => 'ana.lopez@feu.edu.ph',      'status' => 'active'],
    ['id' => '2022-00115', 'name' => 'Carlos Reyes',    'role' => 'student', 'email' => 'carlos.reyes@feu.edu.ph',   'status' => 'active'],
    ['id' => '2022-00207', 'name' => 'Sofia Garcia',    'role' => 'student', 'email' => 'sofia.garcia@feu.edu.ph',   'status' => 'active'],
    ['id' => '2021-00318', 'name' => 'Miguel Torres',   'role' => 'student', 'email' => 'miguel.torres@feu.edu.ph',  'status' => 'inactive'],
];

$staff = [
    ['id' => 'EMP-1001', 'name' => 'Alice Reyes',     'role' => 'staff',   'email' => 'alice.reyes@feu.edu.ph',     'status' => 'active'],
    ['id' => 'EMP-1002', 'name' => 'Bob Santos',      'role' => 'staff',   'email' => 'bob.santos@feu.edu.ph',      'status' => 'active'],
    ['id' => 'EMP-2014', 'name' => 'Luis Tan',        'role' => 'faculty', 'email' => 'luis.tan@feu.edu.ph',        'status' => 'active'],
    ['id' => 'EMP-2027', 'name' => 'Patricia Lim',    'role' => 'faculty', 'email' => 'patricia.lim@feu.edu.ph',    'status' => 'active'],
    ['id' => 'EMP-3005', 'name' => 'Roberto Aquino',  'role' => 'staff',   'email' => 'roberto.aquino@feu.edu.ph',  'status' => 'active'],
];

$type = $_GET['type'] ?? '';
if ($type === 'student') {
    $payload = ['data' => $students];
} elseif ($type === 'staff') {
    $payload = ['data' => $staff];
} else {
    http_response_code(400);
    echo json_encode(['error' => "Missing or invalid `type`. Use 'student' or 'staff'."]);
    exit;
}

echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;
