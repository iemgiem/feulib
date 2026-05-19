<?php
declare(strict_types=1);

/**
 * View helpers — URL construction + shared HTML layouts.
 *
 *   url('/index.php?p=login')          → '/feulib/index.php?p=login'   (depends on app.base_path)
 *   asset('css/components.css')        → '/feulib/assets/css/components.css'
 *
 *   auth_card_open($title, $subtitle?, $page_title?)
 *   auth_card_close()
 *
 * Task 5 adds layout_open / layout_close to this same file for the full app shell.
 */

/**
 * Build a path-prefixed URL from a leading-slash path. Uses cfg('app.base_path').
 *
 * url()                  → '/feulib'
 * url('/')               → '/feulib/'
 * url('/index.php')      → '/feulib/index.php'
 * url('/assets/x.css')   → '/feulib/assets/x.css'
 */
function url(string $path = ''): string
{
    static $base = null;
    if ($base === null) {
        $configured = cfg('app.base_path');
        if (!is_string($configured)) {
            $configured = '';
        }
        $base = rtrim($configured, '/');
    }
    if ($path === '') {
        return $base === '' ? '/' : $base;
    }
    return $base . '/' . ltrim($path, '/');
}

/**
 * Convenience wrapper for /assets/* URLs.
 *
 *   asset('css/tokens.css') → '/feulib/assets/css/tokens.css'
 */
function asset(string $relative_path): string
{
    return url('/assets/' . ltrim($relative_path, '/'));
}

/**
 * Open the auth-shell layout used by login, register, forgot.
 * Outputs <!DOCTYPE>, <head>, header bar, opens the centered card.
 * Page content (form etc.) follows; close with auth_card_close().
 */
function auth_card_open(string $card_title, ?string $card_subtitle = null, ?string $page_title = null): void
{
    $page_title = $page_title ?? ($card_title . ' · FEU LFMS');
    ?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($page_title) ?></title>
  <link rel="stylesheet" href="<?= e(asset('css/tokens.css')) ?>">
  <link rel="stylesheet" href="<?= e(asset('css/base.css')) ?>">
  <link rel="stylesheet" href="<?= e(asset('css/components.css')) ?>">
</head>
<body>
  <div class="auth-shell">
    <header class="auth-header">
      <div class="auth-header-title">FEU Library &mdash; Lost &amp; Found Management System</div>
    </header>
    <main class="auth-body">
      <section class="auth-card" aria-labelledby="auth-card-title">
        <h1 class="auth-card-title" id="auth-card-title"><?= e($card_title) ?></h1>
        <?php if ($card_subtitle !== null && $card_subtitle !== ''): ?>
          <p class="auth-card-subtitle"><?= e($card_subtitle) ?></p>
        <?php endif; ?>
<?php
}

/**
 * Close the auth-shell layout opened by auth_card_open().
 */
function auth_card_close(): void
{
    ?>
      </section>
    </main>
    <footer class="auth-footer">FEU Library &middot; Lost &amp; Found Management System</footer>
  </div>
  <script src="<?= e(asset('js/validate.js')) ?>" defer></script>
</body>
</html>
<?php
}

/**
 * Render a status badge as HTML. Maps DB enum values (lower-snake-case) to a
 * CSS class + display label. Unknown values fall back to the neutral palette
 * and the value as-is in uppercase.
 *
 *   echo status_badge('open');                  // → <span class="badge badge-open">OPEN</span>
 *   echo status_badge('pending_user_action');   // → <span class="badge badge-pending">AWAITING YOU</span>
 */
function status_badge(string $status): string
{
    [$class, $label] = match (strtolower($status)) {
        'open'                 => ['badge-open',     'OPEN'],
        'matched'              => ['badge-matched',  'MATCHED'],
        'claimed'              => ['badge-matched',  'CLAIMED'],
        'pending'              => ['badge-pending',  'PENDING'],
        'pending_user_action'  => ['badge-pending',  'AWAITING YOU'],
        'pending_verification' => ['badge-pending',  'PENDING'],
        'needs_info'           => ['badge-pending',  'NEEDS INFO'],
        'approved'             => ['badge-approved', 'APPROVED'],
        'rejected'             => ['badge-rejected', 'REJECTED'],
        'released'             => ['badge-released', 'RELEASED'],
        'expired'              => ['badge-expired',  'EXPIRED'],
        'donated'              => ['badge-donated',  'DONATED'],
        default                => ['badge-neutral',  strtoupper($status)],
    };
    return '<span class="badge ' . $class . '">' . e($label) . '</span>';
}

/**
 * Canonical item-category enum, shared by lost + found forms and detail views.
 * The keys go into the DB; the values are the display labels.
 */
function item_categories(): array
{
    return [
        'bag'          => 'Bag',
        'backpack'     => 'Backpack',
        'phone'        => 'Phone',
        'laptop'       => 'Laptop / Tablet',
        'charger'      => 'Charger / Cable',
        'headphones'   => 'Earphones / Headphones',
        'watch'        => 'Watch / Jewelry',
        'book'         => 'Book / Notebook',
        'calculator'   => 'Calculator',
        'stationery'   => 'Pen / Stationery',
        'wallet'       => 'ID / Wallet',
        'keys'         => 'Keys',
        'water bottle' => 'Water Bottle',
        'umbrella'     => 'Umbrella',
        'clothing'     => 'Clothing',
        'other'        => 'Other',
    ];
}

/**
 * Look up the display label for a category key. Falls back to a Title-cased
 * version of the key if it isn't in the canonical enum.
 */
function category_label(string $key): string
{
    $cats = item_categories();
    return $cats[$key] ?? ucfirst($key);
}

/**
 * Build a public-facing reference number: LFMS-YYYY-[<prefix>-]NNNNN.
 *   make_ref_number('lost',  42)  → "LFMS-2026-00042"
 *   make_ref_number('found', 7)   → "LFMS-2026-F-00007"
 *   make_ref_number('claim', 13)  → "LFMS-2026-C-00013"
 *
 * The numeric segment comes from the entity's auto-increment id, so refs
 * are globally unique within each table without a separate counter row.
 */
function make_ref_number(string $kind, int $id): string
{
    $infix = match ($kind) {
        'lost'  => '',
        'found' => 'F-',
        'claim' => 'C-',
        default => throw new \InvalidArgumentException("Unknown ref kind: {$kind}"),
    };
    return sprintf('LFMS-%s-%s%05d', date('Y'), $infix, $id);
}

/**
 * Render a match-score chip (0..100) with traffic-light coloring.
 * Thresholds live in DESIGN_TOKENS.css (--score-threshold-high / -medium).
 */
function score_chip(int $score): string
{
    $score = max(0, min(100, $score));
    $class = $score >= 70 ? 'score-chip-high'
           : ($score >= 40 ? 'score-chip-medium' : 'score-chip-low');
    return '<span class="score-chip ' . $class . '" title="Match score">' . $score . '</span>';
}

/**
 * Read sort / page / per-page / status / q from $_GET using a prefix.
 * Allows multiple tables on one page to maintain independent state via
 * different prefixes (e.g. 'm_' for the match queue, 'c_' for claims).
 *
 *   $state = table_state('m_', ['sort' => 'score', 'dir' => 'desc', 'per_page' => 10]);
 *   $state['sort'], $state['dir'], $state['page'], $state['per_page'], $state['status'], $state['q']
 */
function table_state(string $prefix = '', array $defaults = []): array
{
    $allowed_per = [10, 25, 50, 100];
    $raw_per     = (int) ($_GET[$prefix . 'per_page'] ?? 0);

    return [
        'sort'     => isset($_GET[$prefix . 'sort']) && is_string($_GET[$prefix . 'sort'])
                      ? trim($_GET[$prefix . 'sort'])
                      : (string) ($defaults['sort'] ?? ''),
        'dir'      => (isset($_GET[$prefix . 'dir']) && $_GET[$prefix . 'dir'] === 'asc')
                      ? 'asc'
                      : ((isset($_GET[$prefix . 'dir']) && $_GET[$prefix . 'dir'] === 'desc')
                          ? 'desc'
                          : (string) ($defaults['dir'] ?? 'desc')),
        'page'     => max(1, (int) ($_GET[$prefix . 'page'] ?? 1)),
        'per_page' => in_array($raw_per, $allowed_per, true)
                      ? $raw_per
                      : (int) ($defaults['per_page'] ?? 25),
        'status'   => isset($_GET[$prefix . 'status']) && is_string($_GET[$prefix . 'status'])
                      ? trim($_GET[$prefix . 'status'])
                      : (string) ($defaults['status'] ?? ''),
        'q'        => isset($_GET[$prefix . 'q']) && is_string($_GET[$prefix . 'q'])
                      ? trim($_GET[$prefix . 'q'])
                      : '',
    ];
}

/**
 * Render a sortable column header link. Toggles dir when clicking the active
 * column. Always resets the page to 1 when the sort changes.
 *
 * For the matching aria-sort attribute on the enclosing <th>, see sort_aria().
 */
function sort_link(string $column, string $label, array $state, string $prefix = '', array $base = []): string
{
    $is_active = $state['sort'] === $column;
    $new_dir   = $is_active && $state['dir'] === 'asc' ? 'desc' : 'asc';

    $params = $base;
    $params[$prefix . 'sort'] = $column;
    $params[$prefix . 'dir']  = $new_dir;
    unset($params[$prefix . 'page']);

    $indicator = $is_active
        ? ($state['dir'] === 'asc' ? '↑' : '↓')
        : '↕';

    $class = 'sort-link' . ($is_active ? ' sort-active' : '');
    return '<a href="' . e(url('/index.php?' . http_build_query($params))) . '" class="' . $class . '">'
         . e($label)
         . '<span class="sort-indicator" aria-hidden="true">' . $indicator . '</span>'
         . '</a>';
}

/**
 * Return ` aria-sort="ascending|descending"` (leading space) for the <th>
 * that wraps the active sort_link(), or `""` for any other column. Companion
 * to sort_link() — together they close ACCESSIBILITY.md #2 (WCAG 4.1.2).
 *
 *   <th<?= sort_aria('ref', $state) ?>><?= sort_link('ref', 'Ref', $state) ?></th>
 *
 * Pass the same $state you give sort_link(); $state['dir'] should already be
 * one of 'asc' / 'desc' (table_state() guarantees this).
 */
function sort_aria(string $column, array $state): string
{
    if (($state['sort'] ?? '') !== $column) {
        return '';
    }
    $dir = ($state['dir'] ?? 'asc') === 'asc' ? 'ascending' : 'descending';
    return ' aria-sort="' . $dir . '"';
}

/**
 * Pagination component. Returns empty string when no pagination is needed
 * (total fits in one page).
 *
 *   echo render_pagination($total, $state, 'm_', ['p' => 'staff.dashboard']);
 */
function render_pagination(int $total, array $state, string $prefix = '', array $base = []): string
{
    $per_page = (int) $state['per_page'];
    if ($per_page <= 0 || $total <= 0) {
        return '';
    }
    $total_pages = (int) ceil($total / $per_page);
    if ($total_pages <= 1) {
        return '';
    }

    $page  = max(1, min((int) $state['page'], $total_pages));
    $start = ($page - 1) * $per_page + 1;
    $end   = min($total, $page * $per_page);

    $page_url = static function (int $p) use ($prefix, $base): string {
        $params = $base;
        $params[$prefix . 'page'] = $p;
        return url('/index.php?' . http_build_query($params));
    };

    // Page-number window: 1, ..., (page-1, page, page+1), ..., total
    $pages = [1];
    for ($i = $page - 1; $i <= $page + 1; $i++) {
        if ($i > 1 && $i < $total_pages) {
            $pages[] = $i;
        }
    }
    if ($total_pages > 1) {
        $pages[] = $total_pages;
    }
    $pages = array_values(array_unique($pages));
    sort($pages);

    $html  = '<nav class="pagination" aria-label="Pagination">';
    $html .= '<p class="pagination-info">Showing ' . $start . '&ndash;' . $end . ' of ' . $total . '</p>';
    $html .= '<ul class="pagination-pages">';

    if ($page > 1) {
        $html .= '<li><a href="' . e($page_url($page - 1)) . '" rel="prev">&lsaquo; Prev</a></li>';
    }

    $prev = 0;
    foreach ($pages as $p) {
        if ($prev > 0 && $p > $prev + 1) {
            $html .= '<li><span class="pagination-gap" aria-hidden="true">&hellip;</span></li>';
        }
        if ($p === $page) {
            $html .= '<li><a class="pagination-current" href="' . e($page_url($p)) . '" aria-current="page">' . $p . '</a></li>';
        } else {
            $html .= '<li><a href="' . e($page_url($p)) . '">' . $p . '</a></li>';
        }
        $prev = $p;
    }

    if ($page < $total_pages) {
        $html .= '<li><a href="' . e($page_url($page + 1)) . '" rel="next">Next &rsaquo;</a></li>';
    }

    $html .= '</ul>';

    // Per-page selector — submit as GET, preserves all other base params.
    $form_params = $base;
    unset($form_params[$prefix . 'page'], $form_params[$prefix . 'per_page']);
    $html .= '<form class="pagination-controls" method="GET">';
    foreach ($form_params as $k => $v) {
        $html .= '<input type="hidden" name="' . e((string) $k) . '" value="' . e((string) $v) . '">';
    }
    $html .= '<label for="' . e($prefix . 'per_page') . '">Per page</label>';
    $html .= '<select id="' . e($prefix . 'per_page') . '" name="' . e($prefix . 'per_page') . '" onchange="this.form.submit()">';
    foreach ([10, 25, 50, 100] as $opt) {
        $sel = $opt === $per_page ? ' selected' : '';
        $html .= '<option value="' . $opt . '"' . $sel . '>' . $opt . '</option>';
    }
    $html .= '</select>';
    $html .= '</form>';
    $html .= '</nav>';

    return $html;
}

/**
 * Humanize a SQL DATETIME into a relative phrase ("3 days ago"), falling back
 * to an absolute date once the gap exceeds a week.
 */
function time_ago(?string $datetime): string
{
    if (!$datetime) {
        return '';
    }
    $ts = strtotime($datetime);
    if ($ts === false) {
        return '';
    }
    $diff = time() - $ts;
    if ($diff < 0)                 return date('M j, Y', $ts);   // future
    if ($diff < 60)                return 'just now';
    if ($diff < 3600)              return floor($diff / 60)   . ' min ago';
    if ($diff < 86400)             return floor($diff / 3600) . ' hr ago';
    if ($diff < 7 * 86400)         return floor($diff / 86400) . ' days ago';
    return date('M j, Y', $ts);
}

/**
 * Form-error a11y helpers (Task 27, ACCESSIBILITY.md #1).
 *
 * Programmatically link a field-level error message to its input so screen
 * readers announce the error when the input gets focus (WCAG 3.3.1, 1.3.1).
 *
 * Usage:
 *
 *   <input id="email" name="email"<?= field_aria('email', $errors) ?>>
 *   <?= field_error_html('email', $errors) ?>
 *
 * `field_aria` returns ` aria-invalid="true" aria-describedby="<name>-error"`
 * (leading space) when the field has an error, otherwise an empty string —
 * drop it inside an input/textarea/select tag before the closing `>`.
 *
 * `field_error_html` returns a `<p id="<name>-error" class="…">…</p>` element
 * with the matching id, or an empty string if no error. The CSS class
 * defaults to `form-error`; pass `'field-error-text'` to match the
 * `.field` / `.field-error` family of forms.
 *
 * Both helpers expect $errors to be an associative array keyed by field
 * name, where each value is either a string or an array of strings (only
 * the first message is rendered, matching the existing call sites).
 */
function field_aria(string $name, array $errors): string
{
    if (empty($errors[$name])) {
        return '';
    }
    return ' aria-invalid="true" aria-describedby="' . e($name) . '-error"';
}

function field_error_html(string $name, array $errors, string $class = 'form-error'): string
{
    if (empty($errors[$name])) {
        return '';
    }
    $msg = $errors[$name];
    if (is_array($msg)) {
        $msg = $msg[0] ?? '';
    }
    return '<p id="' . e($name) . '-error" class="' . e($class) . '">'
         . e((string) $msg) . '</p>';
}
