<?php
/**
 * App-shell sidebar fragment. Included by layout_open() in partials/layout.php.
 * Renders the role-aware vertical nav with section dividers and active highlighting.
 */

$current_token = (string) ($_GET['p'] ?? '');
$role          = user_role() ?? 'user';
$items         = sidebar_items($role);
?>
<aside class="app-sidebar" id="app-sidebar" aria-label="Primary navigation">
  <nav>
    <?php
    $in_section = false;
    foreach ($items as $item):
        if (!empty($item['section'])):
            if ($in_section) {
                echo '</div>';
            }
            echo '<div class="app-sidebar-section">';
            $in_section = true;
            if (!empty($item['label'])):
                ?>
                <div class="app-sidebar-section-label"><?= e($item['label']) ?></div>
                <?php
            endif;
            continue;
        endif;

        $is_active = in_array($current_token, $item['active_tokens'] ?? [], true);
        $class = 'app-sidebar-item' . ($is_active ? ' active' : '');
        ?>
        <a href="<?= e($item['href']) ?>"
           class="<?= e($class) ?>"<?= $is_active ? ' aria-current="page"' : '' ?>>
          <?= e($item['label']) ?>
        </a>
    <?php
    endforeach;
    if ($in_section) {
        echo '</div>';
    }
    ?>
  </nav>
</aside>
