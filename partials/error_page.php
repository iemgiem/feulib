<?php
/**
 * Shared standalone layout for 403/404/500 pages.
 *
 * Required variables (each error page sets these before including this file):
 *   string $status        HTTP status code as string ("403")
 *   string $label         Short label ("Forbidden", "Not Found", "Server Error")
 *   string $heading       Main heading shown to the user
 *   string $body          Body paragraph
 *   string $action_label  Primary button text
 *   string $action_url    Primary button URL
 *
 * Optional:
 *   string|null $details  Dev-only pre-formatted details (only shown when app.env=development)
 *
 * Styles are inlined so error pages never depend on the CSS pipeline. If a
 * stylesheet fails to load, the error page still renders correctly.
 */

if (!isset($status, $label, $heading, $body, $action_label, $action_url)) {
    throw new \RuntimeException('partials/error_page.php requires $status, $label, $heading, $body, $action_label, and $action_url to be set.');
}
$details = $details ?? null;
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title><?= e($status) ?> — <?= e($label) ?> · FEU LFMS</title>
  <style>
    :root {
      --feu-green: #006400;
      --feu-green-dark: #004d00;
      --feu-gold: #FFD700;
      --bg: #f7f9f7;
      --surface: #ffffff;
      --text: #222222;
      --text-muted: #5a6b5a;
      --border: #e2e6e2;
    }
    *, *::before, *::after { box-sizing: border-box; }
    html, body { margin: 0; }
    body {
      min-height: 100vh;
      font-family: "Segoe UI", Tahoma, "Helvetica Neue", Arial, sans-serif;
      background: var(--bg);
      color: var(--text);
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      padding: 32px 16px;
      line-height: 1.5;
    }
    .err-card {
      max-width: 480px;
      width: 100%;
      background: var(--surface);
      border: 1px solid var(--border);
      border-left: 4px solid var(--feu-green);
      border-radius: 6px;
      padding: 32px;
    }
    .err-header {
      display: flex;
      align-items: baseline;
      gap: 12px;
      margin: 0 0 16px;
      padding-bottom: 16px;
      border-bottom: 3px solid var(--feu-gold);
    }
    .err-status {
      font-size: 36px;
      font-weight: 700;
      color: var(--feu-green);
      line-height: 1;
    }
    .err-label {
      font-size: 12px;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      color: var(--text-muted);
      font-weight: 600;
    }
    .err-card h1 {
      font-size: 20px;
      font-weight: 700;
      margin: 0 0 12px;
      color: var(--text);
      line-height: 1.25;
    }
    .err-card p {
      margin: 0 0 24px;
      color: var(--text-muted);
    }
    .err-action {
      display: inline-block;
      background: var(--feu-green);
      color: #fff;
      text-decoration: none;
      padding: 10px 18px;
      border-radius: 6px;
      font-weight: 600;
      font-size: 14px;
    }
    .err-action:hover { background: var(--feu-green-dark); }
    .err-action:focus-visible {
      outline: 2px solid var(--feu-green);
      outline-offset: 2px;
    }
    .err-details {
      margin: 24px 0 0;
      padding: 12px;
      background: #f0f3f0;
      border-radius: 4px;
      font-family: Consolas, "Courier New", monospace;
      font-size: 12px;
      color: var(--text);
      overflow-x: auto;
      white-space: pre-wrap;
    }
    .err-footer {
      margin-top: 24px;
      font-size: 12px;
      color: var(--text-muted);
      text-align: center;
    }
  </style>
</head>
<body>
  <main class="err-card" role="main">
    <header class="err-header">
      <div class="err-status"><?= e($status) ?></div>
      <div class="err-label"><?= e($label) ?></div>
    </header>
    <h1><?= e($heading) ?></h1>
    <p><?= e($body) ?></p>
    <a class="err-action" href="<?= e($action_url) ?>"><?= e($action_label) ?></a>
    <?php if ($details !== null && cfg('app.env') === 'development'): ?>
      <pre class="err-details"><?= e($details) ?></pre>
    <?php endif; ?>
  </main>
  <p class="err-footer">FEU Library — Lost &amp; Found Management System</p>
</body>
</html>
