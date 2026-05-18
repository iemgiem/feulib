<?php
/**
 * App-shell header fragment. Included by layout_open() in partials/layout.php.
 * Renders the FEU green bar with brand + tools (bell, user info, logout).
 */

$user = current_user();
$role_label = strtoupper((string) ($user['role'] ?? ''));

// Unread-count query is cheap; safe to run on every authenticated page render.
// Task 20 swaps this for live polling — the markup stays.
$unread_count = (int) (q_value(
    'SELECT COUNT(*) FROM notifications WHERE recipient_account_id = ? AND is_read = 0',
    [(int) $user['id']]
) ?? 0);
?>
<header class="app-header" role="banner">
  <div class="app-header-brand">FEU Library &mdash; Lost &amp; Found</div>
  <div class="app-header-tools">
    <a href="<?= e(url('/index.php?p=notifications')) ?>"
       class="app-header-bell"
       aria-label="Notifications<?= $unread_count > 0 ? ' (' . $unread_count . ' unread)' : '' ?>">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor"
           stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/>
        <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
      </svg>
      <?php if ($unread_count > 0): ?>
        <span class="app-header-bell-badge"><?= e((string) min($unread_count, 99)) ?><?= $unread_count > 99 ? '+' : '' ?></span>
      <?php endif; ?>
    </a>
    <div class="app-header-user">
      <span><?= e($user['full_name']) ?></span>
      <span class="role-pill"><?= e($role_label) ?></span>
    </div>
    <a href="<?= e(url('/index.php?p=logout')) ?>" class="app-header-logout">Log out</a>
  </div>
</header>
