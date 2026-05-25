<?php
declare(strict_types=1);

/**
 * Modal partial — accessible <div role="dialog"> helpers.
 *
 *   modal_open(string $id, string $title, array $opts = [])
 *       Opens the modal markup. $opts:
 *         'size'        => 'sm' | 'md' (default) | 'lg'
 *         'role'        => 'dialog' (default) | 'alertdialog'
 *         'no_dismiss'  => bool — disable backdrop click + ESC if true (e.g. destructive flow)
 *         'describedby' => element id whose text describes the dialog (announced by SR)
 *
 *   modal_close()
 *       Closes the dialog wrapper. Caller is responsible for emitting the body
 *       and footer between the two calls.
 *
 *   modal_footer_open() / modal_footer_close()
 *       Convenience wrappers for the footer row (right-aligned button group).
 *
 * Pairs with assets/js/modal.js, which wires up:
 *   - data-modal-open="<id>"   — opens the modal with that id
 *   - data-modal-close          — closes the closest .modal
 *   - data-modal-hold="<ms>"    — button must be held N ms before its enclosing
 *                                 form submits (default 1500 if attribute is empty)
 */

function modal_open(string $id, string $title, array $opts = []): void
{
    $size        = $opts['size']        ?? 'md';
    $role        = $opts['role']        ?? 'dialog';
    $no_dismiss  = !empty($opts['no_dismiss']);
    $describedby = $opts['describedby'] ?? null;

    $title_id = $id . '-title';
    $size_class = match ($size) {
        'sm' => ' modal-sm',
        'lg' => ' modal-lg',
        default => '',
    };
    ?>
    <div class="modal" id="<?= e($id) ?>" hidden
         data-modal<?= $no_dismiss ? ' data-modal-no-dismiss' : '' ?>>
      <div class="modal-backdrop" data-modal-backdrop></div>
      <div class="modal-dialog<?= e($size_class) ?>"
           role="<?= e($role) ?>"
           aria-modal="true"
           aria-labelledby="<?= e($title_id) ?>"
           <?= $describedby ? 'aria-describedby="' . e($describedby) . '"' : '' ?>>
        <header class="modal-header">
          <h2 class="modal-title" id="<?= e($title_id) ?>"><?= e($title) ?></h2>
          <?php if (!$no_dismiss): ?>
            <button type="button" class="modal-close-btn" data-modal-close
                    aria-label="Close dialog">
              <span aria-hidden="true">&times;</span>
            </button>
          <?php endif; ?>
        </header>
        <div class="modal-body">
    <?php
}

function modal_close(): void
{
    ?>
        </div>
      </div>
    </div>
    <?php
}

function modal_footer_open(): void
{
    echo '<footer class="modal-footer">';
}

function modal_footer_close(): void
{
    echo '</footer>';
}
