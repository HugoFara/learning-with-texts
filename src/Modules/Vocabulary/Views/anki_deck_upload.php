<?php

/**
 * Anki deck import — step 1, the upload form.
 *
 * Expected variables:
 * - $error: string|null  Message to show above the form, if the last attempt failed
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Vocabulary\Views
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.7.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Vocabulary\Views;

use Lwt\Shared\UI\Helpers\FormHelper;

assert($error === null || is_string($error));

?>
<h1><?= __e('vocabulary.anki.deck.title') ?></h1>

<?php if ($error !== null) { ?>
  <div class="notification is-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php } ?>

<?php // Contains <strong> naming Anki's own menu path, so it is not escaped. ?>
<p class="mb-4"><?= __('vocabulary.anki.deck.intro') ?></p>

<form method="post" enctype="multipart/form-data" action="/vocabulary/anki-deck/import">
  <?= FormHelper::csrfField() ?>
  <div class="field">
    <label class="label" for="apkg-file"><?= __e('vocabulary.anki.deck.file_label') ?></label>
    <div class="control">
      <input class="input" type="file" name="apkg" id="apkg-file" accept=".apkg" required>
    </div>
  </div>
  <div class="field">
    <div class="control">
      <button class="button is-primary" type="submit"><?= __e('vocabulary.anki.deck.continue') ?></button>
    </div>
  </div>
</form>

<p class="help mt-4"><?= __e('vocabulary.anki.deck.creates_new_only') ?></p>
