<?php
declare(strict_types=1);

/**
 * App-shell layout helpers — wraps every authenticated page.
 *
 *   layout_open(string $page_title)        Open <html>, render header + sidebar, open <main>.
 *   breadcrumb(array $crumbs)               Optional. Items: [['Label', '/url'], ['Current']].
 *   page_header(string $heading, ?string $action_html = null)
 *                                           Optional. H1 + optional right-aligned action HTML.
 *   layout_close()                          Close <main>, render footer, close <html>.
 *
 *   sidebar_items(string $role)             Internal: returns the role-aware nav map.
 *
 * Bootstrap loads this file. Partials/header.php, sidebar.php, footer.php contain
 * the HTML fragments included at the appropriate moments inside layout_open/close.
 */

/**
 * Open the app-shell layout. Pages call this before rendering any content.
 */
function layout_open(string $page_title): void
{
    if (!is_authenticated()) {
        // Defensive — anonymous pages should use auth_card_open, not layout_open.
        throw new \RuntimeException('layout_open() called without an authenticated user.');
    }
    ?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($page_title) ?> · FEU LFMS</title>
  <link rel="stylesheet" href="<?= e(asset('css/tokens.css')) ?>">
  <link rel="stylesheet" href="<?= e(asset('css/base.css')) ?>">
  <link rel="stylesheet" href="<?= e(asset('css/components.css')) ?>">
</head>
<body>
  <div class="app-shell">
<?php
    require __DIR__ . '/header.php';
    require __DIR__ . '/sidebar.php';
    ?>
    <main class="app-main" role="main">
      <div class="app-content">
<?php
}

/**
 * Render breadcrumb trail. Pass an array of [$label, $url?] entries; the last
 * entry is rendered as the current page (no link).
 */
function breadcrumb(array $crumbs): void
{
    if (!$crumbs) {
        return;
    }
    $last = count($crumbs) - 1;
    echo '<nav class="breadcrumb" aria-label="Breadcrumb">';
    foreach ($crumbs as $i => $crumb) {
        if ($i > 0) {
            echo '<span class="breadcrumb-separator" aria-hidden="true">&rsaquo;</span>';
        }
        $label = (string) ($crumb[0] ?? '');
        $href  = $crumb[1] ?? null;
        if ($href !== null && $href !== '' && $i !== $last) {
            echo '<a href="' . e($href) . '">' . e($label) . '</a>';
        } else {
            echo '<span class="breadcrumb-current">' . e($label) . '</span>';
        }
    }
    echo '</nav>';
}

/**
 * Render the page header strip — H1 + optional right-aligned action HTML.
 * Callers are responsible for escaping the action HTML internally.
 */
function page_header(string $heading, ?string $action_html = null): void
{
    echo '<header class="page-header"><h1>' . e($heading) . '</h1>';
    if ($action_html !== null && $action_html !== '') {
        echo '<div class="page-header-actions">' . $action_html . '</div>';
    }
    echo '</header>';
}

/**
 * Close the app-shell layout.
 */
function layout_close(): void
{
    ?>
      </div>
    </main>
<?php
    require __DIR__ . '/footer.php';
    ?>
  </div>
  <script src="<?= e(asset('js/validate.js')) ?>" defer></script>
  <script src="<?= e(asset('js/photo-upload.js')) ?>" defer></script>
</body>
</html>
<?php
}

/**
 * Build the role-aware sidebar item map.
 *
 * Each entry is either:
 *   - a section marker: ['section' => true, 'label' => null|'Section Name']
 *   - a nav item:       ['label' => 'Foo', 'href' => '...', 'active_tokens' => [...]]
 *
 * `active_tokens` is a whitelist of page tokens that should mark this item active.
 * This makes nested URLs (e.g. lost.show) light up their parent ("My Lost Reports")
 * without complicated prefix logic.
 */
function sidebar_items(string $role): array
{
    $items = [];

    // ----- Section 1: user-perspective (everyone sees this) -----
    $items[] = ['section' => true, 'label' => null];

    $items[] = [
        'label' => 'Dashboard',
        'href'  => url('/index.php?p=' . (match ($role) {
            'admin' => 'admin.dashboard',
            'staff' => 'staff.dashboard',
            default => 'dashboard',
        })),
        'active_tokens' => match ($role) {
            'admin' => ['admin.dashboard'],
            'staff' => ['staff.dashboard'],
            default => ['dashboard'],
        },
    ];
    $items[] = ['label' => 'Report Lost Item', 'href' => url('/index.php?p=lost.new'),      'active_tokens' => ['lost.new', 'lost.created']];
    $items[] = ['label' => 'My Lost Reports',  'href' => url('/index.php?p=lost'),          'active_tokens' => ['lost', 'lost.show']];
    $items[] = ['label' => 'Notifications',    'href' => url('/index.php?p=notifications'), 'active_tokens' => ['notifications']];
    $items[] = ['label' => 'My Claims',        'href' => url('/index.php?p=claims'),        'active_tokens' => ['claims', 'claim.new', 'claim.show']];

    // ----- Section 2: staff items -----
    if ($role === 'staff' || $role === 'admin') {
        $items[] = ['section' => true, 'label' => 'Staff'];
        $items[] = ['label' => 'Log Found Item', 'href' => url('/index.php?p=found.new'),    'active_tokens' => ['found.new', 'found.created']];
        $items[] = ['label' => 'Found Items',    'href' => url('/index.php?p=found'),        'active_tokens' => ['found', 'found.show']];
        $items[] = ['label' => 'Match Review',   'href' => url('/index.php?p=matches'),      'active_tokens' => ['matches', 'match.show']];
        $items[] = ['label' => 'Claims Queue',   'href' => url('/index.php?p=staff.claims'), 'active_tokens' => ['staff.claims', 'release']];
    }

    // ----- Section 3: admin items -----
    if ($role === 'admin') {
        $items[] = ['section' => true, 'label' => 'Admin'];
        $items[] = ['label' => 'Reports',   'href' => url('/index.php?p=admin.reports'),  'active_tokens' => ['admin.reports', 'admin.report.show']];
        $items[] = ['label' => 'Audit Log', 'href' => url('/index.php?p=admin.audit'),    'active_tokens' => ['admin.audit']];
        $items[] = ['label' => 'Settings',  'href' => url('/index.php?p=admin.settings'), 'active_tokens' => ['admin.settings']];
    }

    return $items;
}
