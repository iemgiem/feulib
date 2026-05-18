<?php
declare(strict_types=1);

/**
 * Admin dashboard (placeholder for Task 5).
 * Task 16 replaces this with the 6-card stat strip, Recent Activity audit
 * snippet, Quick Links row, and "Items expiring soon" surface.
 */

$user = current_user();

layout_open('Admin Dashboard');

page_header('Administration');
?>

<div class="card">
  <h2 class="card-title">Welcome, <?= e($user['full_name']) ?></h2>
  <p class="card-subtitle">Library administrator view.</p>
  <p class="text-muted">
    Task 16 turns this placeholder into the admin operations view: six stat
    cards (total lost, total found, match rate, avg time to claim, items in
    storage, items expiring soon), a Recent Activity feed sourced from the
    audit log, and Quick Links to reports, user management, and policy
    configuration.
  </p>
</div>

<div class="card">
  <h2 class="card-title">Admin actions available</h2>
  <ul class="text-muted stack-2">
    <li><strong>Reports</strong> &mdash; date-range operational summaries with CSV/XLSX/PDF export.</li>
    <li><strong>Audit Log</strong> &mdash; append-only history of every state-mutating action.</li>
    <li><strong>Settings</strong> &mdash; users &amp; roles, storage locations, holding period, match weights, backup status.</li>
  </ul>
</div>

<?php
layout_close();
