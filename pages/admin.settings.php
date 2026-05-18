<?php
declare(strict_types=1);

/**
 * Admin settings — match scoring weights, holding period, and notification rules.
 * All values stored in the `settings` table as key/value strings.
 *
 * Sections:
 *   1. Match scoring weights (category, color, location, date, description)
 *   2. Match threshold
 *   3. Holding period (days before items expire)
 */

$user    = current_user();
$user_id = (int) $user['id'];

// Load all settings into a flat array keyed by key_name.
$raw_settings = q_all('SELECT key_name, value FROM settings');
$settings     = [];
foreach ($raw_settings as $row) {
    $settings[(string) $row['key_name']] = $row['value'];
}

$setting = static fn(string $key, string $default = '') => $settings[$key] ?? $default;

$errors  = [];
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $section = $_POST['section'] ?? '';

    if ($section === 'match_weights') {
        $keys = [
            'match_weight_category',
            'match_weight_color',
            'match_weight_location',
            'match_weight_date',
            'match_weight_description',
            'match_threshold',
        ];
        $values = [];
        $total_weights = 0;
        foreach ($keys as $key) {
            $val = (int) ($_POST[$key] ?? 0);
            if ($key !== 'match_threshold') {
                if ($val < 0 || $val > 100) {
                    $errors[$key] = ['Must be between 0 and 100.'];
                }
                $total_weights += $val;
            } else {
                if ($val < 0 || $val > 100) {
                    $errors[$key] = ['Must be between 0 and 100.'];
                }
            }
            $values[$key] = (string) $val;
        }
        if (!$errors && $total_weights !== 100) {
            $errors['match_weight_category'] = ['The five weights must add up to exactly 100. Currently: ' . $total_weights . '.'];
        }

        if (!$errors) {
            db_transaction(function () use ($values, $user_id) {
                foreach ($values as $key => $val) {
                    q(
                        'INSERT INTO settings (key_name, value, updated_by_account_id)
                         VALUES (?, ?, ?)
                         ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = NOW(),
                             updated_by_account_id = VALUES(updated_by_account_id)',
                        [$key, $val, $user_id]
                    );
                }
                audit_log('settings.update_match_weights', 'settings', 0);
            });
            flash_set('success', 'Match scoring weights saved.');
            go(url('/index.php?p=admin.settings'));
        }
    } elseif ($section === 'holding_period') {
        $days = (int) ($_POST['holding_period_days'] ?? 0);
        if ($days < 1 || $days > 365) {
            $errors['holding_period_days'] = ['Must be between 1 and 365 days.'];
        }

        if (!$errors) {
            q(
                'INSERT INTO settings (key_name, value, updated_by_account_id)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = NOW(),
                     updated_by_account_id = VALUES(updated_by_account_id)',
                ['holding_period_days', (string) $days, $user_id]
            );
            audit_log('settings.update_holding_period', 'settings', 0);
            flash_set('success', 'Holding period saved.');
            go(url('/index.php?p=admin.settings'));
        }
    }
}

// Re-load after save
$raw_settings = q_all('SELECT key_name, value FROM settings');
$settings = [];
foreach ($raw_settings as $row) {
    $settings[(string) $row['key_name']] = $row['value'];
}
$setting = static fn(string $key, string $default = '') => $settings[$key] ?? $default;

layout_open('Settings');

$flash_success = flash_get('success');
if ($flash_success) {
    echo '<div class="alert alert-success" role="status">' . e($flash_success) . '</div>';
}

if ($errors) {
    echo '<div class="alert alert-error" role="alert"><ul>';
    foreach ($errors as $field_errs) {
        foreach ((array) $field_errs as $err) {
            echo '<li>' . e($err) . '</li>';
        }
    }
    echo '</ul></div>';
}

page_header('System Settings');
?>

<!-- Match Scoring Weights -->
<section class="card" aria-labelledby="weights-title">
  <h2 class="card-title" id="weights-title">Match scoring weights</h2>
  <p class="card-subtitle">
    The five weights must total exactly <strong>100</strong>. They control how the
    system scores candidate matches between lost reports and found items.
  </p>

  <form method="POST" class="stack-4">
    <?= csrf_field() ?>
    <input type="hidden" name="section" value="match_weights">

    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: var(--space-3);">
      <?php
        $weight_fields = [
            'match_weight_category'    => ['Category',    '30'],
            'match_weight_color'       => ['Color',       '20'],
            'match_weight_location'    => ['Location',    '15'],
            'match_weight_date'        => ['Date',        '10'],
            'match_weight_description' => ['Description', '25'],
        ];
        foreach ($weight_fields as $key => [$label, $default]):
      ?>
        <div class="form-group">
          <label for="<?= e($key) ?>" class="form-label"><?= e($label) ?></label>
          <?php if (!empty($errors[$key])): ?>
            <div class="form-error"><?= e($errors[$key][0]) ?></div>
          <?php endif; ?>
          <input type="number" id="<?= e($key) ?>" name="<?= e($key) ?>"
                 value="<?= e($_POST[$key] ?? $setting($key, $default)) ?>"
                 min="0" max="100" class="form-control<?= !empty($errors[$key]) ? ' is-invalid' : '' ?>">
        </div>
      <?php endforeach; ?>
    </div>

    <div class="form-group" style="max-width: 200px;">
      <label for="match_threshold" class="form-label">
        Match threshold
        <span class="form-hint">(minimum score to propose)</span>
      </label>
      <?php if (!empty($errors['match_threshold'])): ?>
        <div class="form-error"><?= e($errors['match_threshold'][0]) ?></div>
      <?php endif; ?>
      <input type="number" id="match_threshold" name="match_threshold"
             value="<?= e($_POST['match_threshold'] ?? $setting('match_threshold', '30')) ?>"
             min="0" max="100" class="form-control<?= !empty($errors['match_threshold']) ? ' is-invalid' : '' ?>">
    </div>

    <button type="submit" class="btn btn-primary">Save weights</button>
  </form>
</section>

<!-- Holding Period -->
<section class="card" aria-labelledby="holding-title">
  <h2 class="card-title" id="holding-title">Holding period</h2>
  <p class="card-subtitle">
    Number of days a found item is held before it is automatically marked
    <strong>EXPIRED</strong> by the daily expiry job.
  </p>

  <form method="POST" class="stack-4">
    <?= csrf_field() ?>
    <input type="hidden" name="section" value="holding_period">

    <div class="form-group" style="max-width: 200px;">
      <label for="holding_period_days" class="form-label">Days</label>
      <?php if (!empty($errors['holding_period_days'])): ?>
        <div class="form-error"><?= e($errors['holding_period_days'][0]) ?></div>
      <?php endif; ?>
      <input type="number" id="holding_period_days" name="holding_period_days"
             value="<?= e($_POST['holding_period_days'] ?? $setting('holding_period_days', '30')) ?>"
             min="1" max="365"
             class="form-control<?= !empty($errors['holding_period_days']) ? ' is-invalid' : '' ?>">
    </div>

    <button type="submit" class="btn btn-primary">Save holding period</button>
  </form>
</section>

<?php
layout_close();
