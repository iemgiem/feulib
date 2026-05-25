<?php
declare(strict_types=1);

/**
 * ITS (Integrated Tertiary System) integration — fetch authoritative
 * student / staff / faculty records from an external REST API and
 * cache them into the local `its_users` table.
 *
 * Public surface:
 *
 *   its_fetch_students(): array        // Live API call. Throws on failure.
 *   its_fetch_staff():    array        // Live API call. Throws on failure.
 *   its_sync():           array        // Fetch both, upsert, return summary.
 *   its_probe($kind):     array        // Read-only smoke test, no DB writes.
 *   its_get_by_id($its_id): ?array     // Local DB lookup.
 *   its_get_student_by_id($its_id): ?array
 *   its_get_staff_by_id($its_id): ?array
 *   its_last_sync_at(): ?string        // MAX(last_synced_at) from its_users.
 *
 * Configuration lives in config.php under the `its` key — base URLs, auth
 * mode (`bearer` or `api_key`), credentials, timeout. See config.example.php.
 *
 * Errors: every network / auth / parse failure raises a \RuntimeException
 * with a human-readable message. its_sync() catches and returns the message
 * in its summary so the admin UI can render it; CLI callers can let it
 * propagate to exit with a non-zero status.
 *
 * Storage: rows are upserted keyed by `its_id`. Only `status='active'`
 * records from the API are written; inactive records in the API mark
 * existing local rows inactive but never insert new ones.
 */

// ---------------------------------------------------------------------------
// Fetch helpers — live HTTP calls
// ---------------------------------------------------------------------------

/**
 * Fetch the student list from the ITS endpoint. Returns an array of
 * normalised rows (see _its_normalize). Raises on any failure.
 */
function its_fetch_students(): array
{
    $url = (string) (cfg('its.endpoints.students') ?? '');
    if ($url === '') {
        throw new \RuntimeException('ITS students endpoint is not configured.');
    }
    return _its_normalise_payload(_its_http_get_json($url), 'student');
}

/**
 * Fetch the staff/faculty list from the ITS endpoint.
 */
function its_fetch_staff(): array
{
    $url = (string) (cfg('its.endpoints.staff') ?? '');
    if ($url === '') {
        throw new \RuntimeException('ITS staff endpoint is not configured.');
    }
    return _its_normalise_payload(_its_http_get_json($url), 'staff');
}

// ---------------------------------------------------------------------------
// Sync — fetch both, upsert into its_users, return summary
// ---------------------------------------------------------------------------

/**
 * Run a full sync: fetch students + staff, upsert active rows, mark
 * inactive rows inactive. Returns a summary array suitable for rendering
 * or logging:
 *
 *   [
 *     'success'        => bool,
 *     'error'          => ?string,
 *     'inserted'       => int,
 *     'updated'        => int,
 *     'deactivated'    => int,
 *     'fetched_total'  => int,
 *     'started_at'     => 'Y-m-d H:i:s',
 *     'finished_at'    => 'Y-m-d H:i:s',
 *   ]
 */
function its_sync(): array
{
    $started_at = date('Y-m-d H:i:s');
    $summary = [
        'success'       => false,
        'error'         => null,
        'inserted'      => 0,
        'updated'       => 0,
        'deactivated'   => 0,
        'fetched_total' => 0,
        'started_at'    => $started_at,
        'finished_at'   => null,
    ];

    try {
        $students = its_fetch_students();
        $staff    = its_fetch_staff();
        $all      = array_merge($students, $staff);

        $summary['fetched_total'] = count($all);

        // Track seen its_ids so we can deactivate rows the API no longer returns.
        $seen_ids = [];

        db_transaction(function () use ($all, &$summary, &$seen_ids) {
            foreach ($all as $row) {
                $seen_ids[] = $row['its_id'];

                $existing = q_one(
                    'SELECT id, status FROM its_users WHERE its_id = ? LIMIT 1',
                    [$row['its_id']]
                );

                if ($existing === null) {
                    if ($row['status'] !== 'active') {
                        // Don't insert inactive records — the spec says only
                        // active users are considered.
                        continue;
                    }
                    q(
                        'INSERT INTO its_users
                            (its_id, full_name, email, role, status, raw_json, last_synced_at)
                         VALUES (?, ?, ?, ?, ?, ?, NOW())',
                        [
                            $row['its_id'],
                            $row['full_name'],
                            $row['email'],
                            $row['role'],
                            $row['status'],
                            $row['raw_json'],
                        ]
                    );
                    $summary['inserted']++;
                } else {
                    q(
                        'UPDATE its_users
                            SET full_name = ?, email = ?, role = ?, status = ?,
                                raw_json = ?, last_synced_at = NOW()
                          WHERE id = ?',
                        [
                            $row['full_name'],
                            $row['email'],
                            $row['role'],
                            $row['status'],
                            $row['raw_json'],
                            (int) $existing['id'],
                        ]
                    );
                    $summary['updated']++;
                }
            }

            // Anything in our table that the API didn't return this run is
            // marked inactive (soft-removal). We keep the row so existing
            // references in audit_logs remain valid.
            if ($seen_ids) {
                $placeholders = implode(',', array_fill(0, count($seen_ids), '?'));
                $deactivated = q_value(
                    "SELECT COUNT(*) FROM its_users
                      WHERE status = 'active'
                        AND its_id NOT IN ($placeholders)",
                    $seen_ids
                );
                q(
                    "UPDATE its_users
                        SET status = 'inactive', last_synced_at = NOW()
                      WHERE status = 'active'
                        AND its_id NOT IN ($placeholders)",
                    $seen_ids
                );
                $summary['deactivated'] = (int) ($deactivated ?? 0);
            }
        });

        audit_log('its.sync', 'its_users', 0, [
            'inserted'    => [null, $summary['inserted']],
            'updated'     => [null, $summary['updated']],
            'deactivated' => [null, $summary['deactivated']],
        ]);

        $summary['success'] = true;
    } catch (\Throwable $e) {
        $summary['error'] = $e->getMessage();
        audit_log('its.sync_failed', 'its_users', 0, [
            'error' => [null, $summary['error']],
        ]);
    }

    $summary['finished_at'] = date('Y-m-d H:i:s');
    return $summary;
}

// ---------------------------------------------------------------------------
// Probe — read-only smoke test against a configured endpoint.
//
// Same fetch + normalise pipeline as its_sync(), but no DB writes, no audit
// log. Returns a structured summary so the CLI / admin UI can render it.
// Designed for "I just dropped real ITS credentials into config.php, do
// they actually work?" before scheduling the nightly job.
// ---------------------------------------------------------------------------

/**
 * Probe one ITS endpoint and return a result summary.
 *
 * $kind: 'students' or 'staff'.
 *
 * Result shape:
 *   [
 *     'success'          => bool,
 *     'kind'             => 'students' | 'staff',
 *     'url'              => string,        // configured endpoint URL
 *     'error'            => ?string,       // null on success
 *     'auth_mode'        => 'bearer' | 'api_key',
 *     'using_dev_token'  => bool,          // true if auth_value matches the
 *                                          // shipped placeholder
 *     'raw_count'        => int,           // rows in JSON payload
 *     'normalised_count' => int,           // rows that passed normalisation
 *     'active_count'     => int,           // normalised rows with status=active
 *     'sample'           => ?array,        // first normalised row, for eyeballing
 *   ]
 */
function its_probe(string $kind): array
{
    $kind = $kind === 'staff' ? 'staff' : 'students';
    $expected_role = $kind === 'staff' ? 'staff' : 'student';

    $url = (string) (cfg('its.endpoints.' . $kind) ?? '');
    $auth_value = (string) (cfg('its.auth_value') ?? '');

    $result = [
        'success'          => false,
        'kind'             => $kind,
        'url'              => $url,
        'error'            => null,
        'auth_mode'        => (string) (cfg('its.auth_mode') ?? 'bearer'),
        'using_dev_token'  => $auth_value === 'dev-token-change-me-before-production',
        'raw_count'        => 0,
        'normalised_count' => 0,
        'active_count'     => 0,
        'sample'           => null,
    ];

    if ($url === '') {
        $result['error'] = "ITS {$kind} endpoint is not configured (its.endpoints.{$kind}).";
        return $result;
    }

    try {
        $payload = _its_http_get_json($url);
        // Count raw rows before normalisation so operators can see whether
        // the API is returning data but our parser is rejecting it.
        if (isset($payload['data']) && is_array($payload['data'])) {
            $result['raw_count'] = count($payload['data']);
        } elseif (isset($payload['users']) && is_array($payload['users'])) {
            $result['raw_count'] = count($payload['users']);
        } elseif (array_is_list($payload)) {
            $result['raw_count'] = count($payload);
        } else {
            $result['raw_count'] = 1;
        }

        $rows = _its_normalise_payload($payload, $expected_role);
        $result['normalised_count'] = count($rows);
        foreach ($rows as $row) {
            if ($row['status'] === 'active') {
                $result['active_count']++;
            }
        }
        $result['sample'] = $rows[0] ?? null;
        $result['success'] = true;
    } catch (\Throwable $e) {
        $result['error'] = $e->getMessage();
    }

    return $result;
}

// ---------------------------------------------------------------------------
// Local lookups
// ---------------------------------------------------------------------------

function its_get_by_id(string $its_id): ?array
{
    return q_one('SELECT * FROM its_users WHERE its_id = ? LIMIT 1', [$its_id]);
}

function its_get_student_by_id(string $its_id): ?array
{
    return q_one(
        "SELECT * FROM its_users
          WHERE its_id = ? AND role = 'student' LIMIT 1",
        [$its_id]
    );
}

function its_get_staff_by_id(string $its_id): ?array
{
    return q_one(
        "SELECT * FROM its_users
          WHERE its_id = ? AND role IN ('staff','faculty') LIMIT 1",
        [$its_id]
    );
}

function its_last_sync_at(): ?string
{
    $value = q_value('SELECT MAX(last_synced_at) FROM its_users');
    return $value !== null ? (string) $value : null;
}

// ---------------------------------------------------------------------------
// Internal — HTTP + payload normalisation
// ---------------------------------------------------------------------------

/**
 * GET $url, send the configured auth header, parse the JSON response.
 * Throws \RuntimeException with a clear message on any failure.
 *
 * Visible for testing as `_its_http_get_json` (the leading underscore
 * marks it as internal — do not call from outside this file).
 */
function _its_http_get_json(string $url): array
{
    if (!function_exists('curl_init')) {
        throw new \RuntimeException('PHP cURL extension is required for ITS integration.');
    }

    $mode  = (string) (cfg('its.auth_mode')  ?? 'bearer');
    $value = (string) (cfg('its.auth_value') ?? '');
    if ($value === '') {
        throw new \RuntimeException('ITS auth_value is not configured.');
    }

    $headers = ['Accept: application/json'];
    if ($mode === 'bearer') {
        $headers[] = 'Authorization: Bearer ' . $value;
    } elseif ($mode === 'api_key') {
        $header_name = (string) (cfg('its.api_key_header') ?? 'X-API-Key');
        $headers[] = $header_name . ': ' . $value;
    } else {
        throw new \RuntimeException("Unknown ITS auth_mode '$mode'. Use 'bearer' or 'api_key'.");
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => (int) (cfg('its.timeout_seconds') ?? 10),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => (bool) (cfg('its.verify_ssl') ?? true),
        CURLOPT_SSL_VERIFYHOST => ((bool) (cfg('its.verify_ssl') ?? true)) ? 2 : 0,
    ]);

    $body = curl_exec($ch);
    if ($body === false) {
        $msg = curl_error($ch) ?: 'unknown cURL error';
        curl_close($ch);
        throw new \RuntimeException("ITS network error: $msg");
    }
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status === 401 || $status === 403) {
        throw new \RuntimeException("ITS authentication failed (HTTP $status). Check `its.auth_value` in config.php.");
    }
    if ($status < 200 || $status >= 300) {
        throw new \RuntimeException("ITS endpoint returned HTTP $status.");
    }

    $decoded = json_decode((string) $body, true);
    if (!is_array($decoded)) {
        throw new \RuntimeException('ITS response was not valid JSON.');
    }
    return $decoded;
}

/**
 * Accepts either a flat list of user objects, or {"data": [...]}, or
 * {"users": [...]}. Returns the normalised list. The `$expected_role`
 * is informational only — if the API returns explicit roles per row we
 * keep them; otherwise we fall back to the endpoint's expected role.
 */
function _its_normalise_payload(array $payload, string $expected_role): array
{
    if (isset($payload['data']) && is_array($payload['data'])) {
        $rows = $payload['data'];
    } elseif (isset($payload['users']) && is_array($payload['users'])) {
        $rows = $payload['users'];
    } elseif (array_is_list($payload)) {
        $rows = $payload;
    } else {
        // Single object response — wrap it.
        $rows = [$payload];
    }

    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $normalised = _its_normalise_row($row, $expected_role);
        if ($normalised !== null) {
            $out[] = $normalised;
        }
    }
    return $out;
}

/**
 * Take one API row and return the canonical shape used by its_sync().
 * Returns null if the row is missing required fields (id, name) — those
 * are silently skipped, so a partial outage in the API can't corrupt
 * the whole sync.
 *
 *   $expected_role  Falls back here when the row has no `role` field.
 */
function _its_normalise_row(array $row, string $expected_role): ?array
{
    $its_id = trim((string) ($row['id'] ?? ''));
    $name   = trim((string) ($row['name'] ?? $row['full_name'] ?? ''));
    if ($its_id === '' || $name === '') {
        return null;
    }

    $role_raw = strtolower(trim((string) ($row['role'] ?? $expected_role)));
    if (!in_array($role_raw, ['student', 'staff', 'faculty'], true)) {
        $role_raw = $expected_role === 'staff' ? 'staff' : 'student';
    }

    $status_raw = strtolower(trim((string) ($row['status'] ?? 'active')));
    $status     = $status_raw === 'active' ? 'active' : 'inactive';

    $email = trim((string) ($row['email'] ?? ''));
    if ($email === '') {
        $email = null;
    }

    return [
        'its_id'    => mb_substr($its_id, 0, 50),
        'full_name' => mb_substr($name, 0, 150),
        'email'     => $email !== null ? mb_substr($email, 0, 255) : null,
        'role'      => $role_raw,
        'status'    => $status,
        'raw_json'  => json_encode($row, JSON_UNESCAPED_UNICODE),
    ];
}
