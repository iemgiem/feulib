<?php
declare(strict_types=1);

/**
 * Auth-gated attachment delivery.
 *
 *   GET /index.php?p=serve_upload&id=<attachment_id>
 *
 * The front controller has already enforced "must be authenticated" before
 * dispatching here (per lib/routes.php). This page additionally enforces
 * the per-attachment permission rules in upload_can_view().
 *
 * Responses:
 *   404 — id is invalid, missing, or no such attachment
 *   403 — current user is not allowed to view this attachment
 *   200 — binary body, served inline with cache headers
 */

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(404);
    exit;
}

$attachment = upload_fetch($id);
if ($attachment === null) {
    http_response_code(404);
    exit;
}

$user = current_user();
if ($user === null || !upload_can_view($attachment, $user)) {
    http_response_code(403);
    exit;
}

upload_stream($attachment);
