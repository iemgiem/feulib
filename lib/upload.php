<?php
declare(strict_types=1);

/**
 * File upload pipeline — photos, ID proofs, signatures, selfies.
 * Shared by Lost report, Found item, Claim submission, and Release flows.
 *
 * Public API:
 *   upload_store($file, $type, $id, $purpose)           Store one entry from $_FILES.
 *   upload_store_data_url($data_url, $type, $id, $p)    Store a canvas/getUserMedia capture.
 *   upload_fetch($id)                                   Read an attachment row by id.
 *   upload_delete($id)                                  Admin-only remove + audit.
 *   upload_can_view($attachment, $user)                 Per-entity permission check.
 *   upload_stream($attachment)                          Send the file body + headers + exit.
 *   upload_url($attachment)                             Build the serve_upload URL.
 *
 * All write paths throw \RuntimeException with a user-friendly message on
 * failure. Callers catch and surface to the user as a validation error.
 *
 * Security notes:
 *   - MIME is detected with finfo from the file content. $_FILES['type']
 *     is browser-controlled and ignored.
 *   - Stored filenames are hex(random_bytes(16)) + canonical extension —
 *     enumeration-resistant and double-extension-proof.
 *   - The uploads tree itself is Deny-from-all (assets/uploads/.htaccess).
 *     All delivery routes through serve_upload.php where the per-attachment
 *     permission check runs.
 */

/** @var array<string,string> MIME → canonical extension */
const UPLOAD_ALLOWED_MIMES = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
];

const UPLOAD_ATTACHABLE_TYPES = ['lost_report', 'found_report', 'claim_ticket', 'release_log'];
const UPLOAD_PURPOSES         = ['photo', 'id_proof', 'signature', 'selfie'];

// ---------------------------------------------------------------------------
// Configuration helpers
// ---------------------------------------------------------------------------

function upload_storage_root(): string
{
    $root = cfg('upload.storage_path');
    if (!is_string($root) || $root === '') {
        throw new \RuntimeException('upload.storage_path is not configured.');
    }
    return $root;
}

function upload_max_bytes(): int
{
    return (int) (cfg('upload.max_bytes') ?? 4 * 1024 * 1024);
}

// ---------------------------------------------------------------------------
// Validation helpers
// ---------------------------------------------------------------------------

function upload_assert_valid_type(string $type): void
{
    if (!in_array($type, UPLOAD_ATTACHABLE_TYPES, true)) {
        throw new \RuntimeException('Invalid attachable type: ' . $type);
    }
}

function upload_assert_valid_purpose(string $purpose): void
{
    if (!in_array($purpose, UPLOAD_PURPOSES, true)) {
        throw new \RuntimeException('Invalid attachment purpose: ' . $purpose);
    }
}

/**
 * Detect MIME from file content. Never trust the browser-sent $_FILES['type'].
 */
function upload_detect_mime(string $path): string
{
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($path);
    if (!is_string($mime) || $mime === '') {
        throw new \RuntimeException('Could not determine file type.');
    }
    return $mime;
}

/**
 * Translate PHP UPLOAD_ERR_* codes into a human-readable message.
 */
function upload_error_message(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE   => 'File exceeds the server upload size limit.',
        UPLOAD_ERR_FORM_SIZE  => 'File exceeds the form size limit.',
        UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded. Try again.',
        UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'Server temp directory is missing.',
        UPLOAD_ERR_CANT_WRITE => 'Server could not save the file.',
        UPLOAD_ERR_EXTENSION  => 'A PHP extension blocked the upload.',
        default               => 'Upload failed (code ' . $code . ').',
    };
}

// ---------------------------------------------------------------------------
// Path helpers
// ---------------------------------------------------------------------------

/**
 * Create (if needed) and return the absolute storage directory for an entity.
 */
function upload_make_storage_dir(string $type, int $id): string
{
    $root = rtrim(upload_storage_root(), '/\\');
    $dir  = $root . DIRECTORY_SEPARATOR . $type . DIRECTORY_SEPARATOR . $id;
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new \RuntimeException('Could not create upload directory.');
    }
    return $dir;
}

/**
 * Forward-slash relative form used in the database — portable across OSes.
 */
function upload_relative_path(string $absolute): string
{
    $absolute = str_replace('\\', '/', $absolute);
    $root     = str_replace('\\', '/', rtrim(upload_storage_root(), '/\\'));
    if (strpos($absolute, $root . '/') === 0) {
        return substr($absolute, strlen($root) + 1);
    }
    return $absolute;
}

/**
 * Absolute filesystem path from a stored row's relative path.
 */
function upload_absolute_path(array $attachment): string
{
    $rel = str_replace('/', DIRECTORY_SEPARATOR, (string) $attachment['stored_path']);
    return rtrim(upload_storage_root(), '/\\') . DIRECTORY_SEPARATOR . $rel;
}

// ---------------------------------------------------------------------------
// Write paths
// ---------------------------------------------------------------------------

/**
 * Store one entry from $_FILES + insert the attachments row.
 *
 * @param array  $file            One element of $_FILES, e.g. $_FILES['photo'].
 * @param string $attachable_type One of UPLOAD_ATTACHABLE_TYPES.
 * @param int    $attachable_id   Parent row id (must already exist).
 * @param string $purpose         One of UPLOAD_PURPOSES.
 * @return int                    The new attachments.id.
 * @throws \RuntimeException on validation failure or write failure.
 */
function upload_store(array $file, string $attachable_type, int $attachable_id, string $purpose): int
{
    upload_assert_valid_type($attachable_type);
    upload_assert_valid_purpose($purpose);

    if (!isset($file['error'], $file['tmp_name'], $file['name'], $file['size'])) {
        throw new \RuntimeException('Malformed file upload payload.');
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new \RuntimeException(upload_error_message((int) $file['error']));
    }
    if ((int) $file['size'] <= 0) {
        throw new \RuntimeException('The uploaded file is empty.');
    }
    if ((int) $file['size'] > upload_max_bytes()) {
        $mb = number_format(upload_max_bytes() / 1024 / 1024, 1);
        throw new \RuntimeException('File is too large. Maximum size is ' . $mb . ' MB.');
    }
    if (!is_uploaded_file((string) $file['tmp_name'])) {
        throw new \RuntimeException('Uploaded file is missing.');
    }

    $mime = upload_detect_mime((string) $file['tmp_name']);
    if (!isset(UPLOAD_ALLOWED_MIMES[$mime])) {
        throw new \RuntimeException('File type not allowed. Please upload a JPEG, PNG, or WebP image.');
    }

    $ext         = UPLOAD_ALLOWED_MIMES[$mime];
    $hash        = bin2hex(random_bytes(16));
    $stored_name = $hash . '.' . $ext;
    $dir         = upload_make_storage_dir($attachable_type, $attachable_id);
    $stored_path = $dir . DIRECTORY_SEPARATOR . $stored_name;

    if (!move_uploaded_file((string) $file['tmp_name'], $stored_path)) {
        throw new \RuntimeException('Could not save uploaded file.');
    }
    @chmod($stored_path, 0644);

    return upload_record(
        $attachable_type,
        $attachable_id,
        $purpose,
        (string) $file['name'],
        $stored_path,
        $mime,
        (int) $file['size']
    );
}

/**
 * Store a base64-encoded data URL (canvas.toDataURL output) as an attachment.
 * Used by the signature pad and selfie capture in Task 13.
 */
function upload_store_data_url(string $data_url, string $attachable_type, int $attachable_id, string $purpose): int
{
    upload_assert_valid_type($attachable_type);
    upload_assert_valid_purpose($purpose);

    if (!preg_match('#^data:(image/(?:jpeg|png|webp));base64,(.+)$#', $data_url, $m)) {
        throw new \RuntimeException('Invalid image capture.');
    }
    $mime  = $m[1];
    $bytes = base64_decode($m[2], true);
    if ($bytes === false || $bytes === '') {
        throw new \RuntimeException('Could not decode captured image.');
    }
    if (strlen($bytes) > upload_max_bytes()) {
        $mb = number_format(upload_max_bytes() / 1024 / 1024, 1);
        throw new \RuntimeException('Captured image is too large. Maximum size is ' . $mb . ' MB.');
    }

    $ext         = UPLOAD_ALLOWED_MIMES[$mime];
    $hash        = bin2hex(random_bytes(16));
    $stored_name = $hash . '.' . $ext;
    $dir         = upload_make_storage_dir($attachable_type, $attachable_id);
    $stored_path = $dir . DIRECTORY_SEPARATOR . $stored_name;

    if (file_put_contents($stored_path, $bytes) === false) {
        throw new \RuntimeException('Could not save captured image.');
    }
    @chmod($stored_path, 0644);

    // Verify the written file matches the declared MIME — guards against
    // a client sending "data:image/png;base64,<actually-jpeg-bytes>".
    $detected = upload_detect_mime($stored_path);
    if ($detected !== $mime) {
        @unlink($stored_path);
        throw new \RuntimeException('Captured image type did not match its header.');
    }

    return upload_record(
        $attachable_type,
        $attachable_id,
        $purpose,
        $purpose . '.' . $ext,
        $stored_path,
        $mime,
        strlen($bytes)
    );
}

/**
 * Insert the attachments row + audit entry inside a transaction. Internal helper.
 */
function upload_record(string $type, int $id, string $purpose, string $filename, string $stored_path, string $mime, int $size): int
{
    return db_transaction(function () use ($type, $id, $purpose, $filename, $stored_path, $mime, $size) {
        $user = current_user();
        if ($user === null) {
            throw new \RuntimeException('Cannot record an upload outside an authenticated session.');
        }
        $rel = upload_relative_path($stored_path);
        q(
            'INSERT INTO attachments
               (attachable_type, attachable_id, purpose, filename, stored_path, mime_type, size_bytes, uploaded_by_account_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$type, $id, $purpose, $filename, $rel, $mime, $size, (int) $user['id']]
        );
        $aid = db_last_id();
        audit_log('attachment.create', 'attachment', $aid, [
            'attachable_type' => $type,
            'attachable_id'   => $id,
            'purpose'         => $purpose,
            'size'            => $size,
        ]);
        return $aid;
    });
}

// ---------------------------------------------------------------------------
// Read paths
// ---------------------------------------------------------------------------

function upload_fetch(int $id): ?array
{
    return q_one('SELECT * FROM attachments WHERE id = ? LIMIT 1', [$id]);
}

/**
 * Build the public URL through which an attachment is served.
 */
function upload_url(array $attachment): string
{
    return url('/index.php?p=serve_upload&id=' . (int) $attachment['id']);
}

/**
 * Per-entity permission check.
 *
 * Rules:
 *   lost_report   → reporter, staff, admin
 *   found_report  → any authenticated user (so claim flow can compare)
 *   claim_ticket  → claimant, staff, admin
 *   release_log   → staff, admin (privacy of the in-person handover)
 */
function upload_can_view(array $attachment, array $user): bool
{
    $role = (string) $user['role'];
    if ($role === 'admin') {
        return true;
    }
    $type      = (string) $attachment['attachable_type'];
    $target_id = (int) $attachment['attachable_id'];

    if ($type === 'lost_report') {
        if ($role === 'staff') {
            return true;
        }
        $row = q_one('SELECT reporter_account_id FROM lost_reports WHERE id = ?', [$target_id]);
        return $row !== null && (int) $row['reporter_account_id'] === (int) $user['id'];
    }

    if ($type === 'found_report') {
        return true;
    }

    if ($type === 'claim_ticket') {
        if ($role === 'staff') {
            return true;
        }
        $row = q_one('SELECT claimant_account_id FROM claim_tickets WHERE id = ?', [$target_id]);
        return $row !== null && (int) $row['claimant_account_id'] === (int) $user['id'];
    }

    if ($type === 'release_log') {
        return $role === 'staff';
    }

    return false;
}

/**
 * Stream the attachment's binary body with correct headers, then exit.
 * Callers MUST have already verified permission via upload_can_view().
 */
function upload_stream(array $attachment): void
{
    $path = upload_absolute_path($attachment);
    if (!is_file($path)) {
        http_response_code(404);
        exit;
    }

    // Defense-in-depth: containment check.
    $real_path = realpath($path);
    $real_root = realpath(upload_storage_root());
    if ($real_path === false || $real_root === false || strpos($real_path, $real_root) !== 0) {
        http_response_code(403);
        exit;
    }

    header('Content-Type: ' . (string) $attachment['mime_type']);
    header('Content-Length: ' . filesize($real_path));
    header('Content-Disposition: inline; filename="' . basename((string) $attachment['filename']) . '"');
    header('Cache-Control: private, max-age=300');
    header('X-Content-Type-Options: nosniff');

    readfile($real_path);
    exit;
}

// ---------------------------------------------------------------------------
// Delete (admin-only)
// ---------------------------------------------------------------------------

function upload_delete(int $id): void
{
    $attachment = upload_fetch($id);
    if ($attachment === null) {
        return;
    }

    db_transaction(function () use ($attachment) {
        q('DELETE FROM attachments WHERE id = ?', [(int) $attachment['id']]);
        audit_log('attachment.delete', 'attachment', (int) $attachment['id'], [
            'attachable_type' => $attachment['attachable_type'],
            'attachable_id'   => $attachment['attachable_id'],
            'purpose'         => $attachment['purpose'],
        ]);
    });

    $path = upload_absolute_path($attachment);
    if (is_file($path)) {
        @unlink($path);
    }
}
