<?php

/**
 * Round-trip .apkg import — upload form and, once run, what the import did.
 *
 * Expected variables:
 * - $error:            string|null    Message shown above the form
 * - $result:           ImportResult|null  Present once an import has run
 * - $needsLegacyExport bool           Whether this server needs Anki's legacy format
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

use Lwt\Modules\Vocabulary\Application\Services\Anki\ImportResult;
use Lwt\Shared\UI\Helpers\FormHelper;

// Extracted from the controller's data array, so their types are asserted here
// rather than declared -- the same shape every view in this module uses.
assert($error === null || is_string($error));
assert($result === null || $result instanceof ImportResult);
assert(is_bool($needsLegacyExport));

$deckImportUrl = '/vocabulary/anki-deck/import';

// Hoisted so the markup below stays markup: one label key per counter, in the
// order they are reported.
/** @var array<string, int> $counts */
$counts = $result === null ? [] : [
    'notes_read' => $result->totalNotes,
    'updated' => $result->updated,
    'unchanged' => $result->unchanged,
    'skipped_missing' => $result->skippedMissing,
    'not_from_lwt' => $result->skippedUnknown,
    'demoted_ignored' => $result->statusSetToIgnored,
    'tags_changed' => $result->tagsChanged,
    'terms_rescheduled' => $result->termsRescheduled,
    'reviews_replayed' => $result->reviewsApplied,
    'due_dates_moved' => $result->dueDatesMoved,
];

$unknownCount = $result?->skippedUnknown ?? 0;
$unknownNotice = $unknownCount === 1
    ? 'vocabulary.anki.apkg.unknown_notice'
    : 'vocabulary.anki.apkg.unknown_notice_plural';

$settingsExport = $needsLegacyExport
    ? 'vocabulary.anki.apkg.settings_export_legacy'
    : 'vocabulary.anki.apkg.settings_export';

?>
<h1><?= __e('vocabulary.anki.apkg.title') ?></h1>

<?php if ($error !== null) { ?>
  <div class="notification is-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php } ?>

<?php if ($result !== null) { ?>
  <div class="notification is-success">
    <p><strong><?= __e('vocabulary.anki.apkg.complete') ?></strong></p>
    <ul>
      <?php foreach ($counts as $key => $count) { ?>
        <li><?= __e('vocabulary.anki.apkg.' . $key) ?>: <?= $count ?></li>
      <?php } ?>
    </ul>
  </div>

    <?php // A count of notes this page could do nothing with is not an answer:
        // the user still has words in the file that never reached their
        // vocabulary. Say where they go, and only when there are some. ?>
    <?php if ($unknownCount > 0) { ?>
    <div class="notification is-warning">
      <strong><?= __e($unknownNotice, ['count' => $unknownCount]) ?></strong>
        <?= __('vocabulary.anki.apkg.unknown_advice', ['url' => $deckImportUrl]) ?>
    </div>
    <?php } ?>
<?php } ?>

<form method="post" enctype="multipart/form-data" action="/vocabulary/apkg/import">
  <?= FormHelper::csrfField() ?>
  <div class="field">
    <label class="label" for="apkg-file"><?= __e('vocabulary.anki.apkg.file_label') ?></label>
    <div class="control">
      <input class="input" type="file" name="apkg" id="apkg-file" accept=".apkg" required>
    </div>
  </div>
  <div class="field">
    <div class="control">
      <button class="button is-primary" type="submit"><?= __e('vocabulary.anki.apkg.submit') ?></button>
    </div>
  </div>
</form>

<?php // Contains <em> around a status name, so it is not escaped. ?>
<p class="help mt-4"><?= __('vocabulary.anki.apkg.help') ?></p>

<?php
// Anki's export and import defaults both work against the round trip and both
// fail quietly, so the settings are named in Anki's own wording rather than
// described. Verified against Anki 26.08.
//
// The legacy-format half only applies where PHP has no zstd: with the
// extension the compressed format reads fine, and telling people to change a
// setting they do not need to change is how advice stops being read.
?>
<div class="notification is-warning is-light mt-4">
  <strong><?= __e('vocabulary.anki.apkg.settings_title') ?></strong>
  <?= __($settingsExport) ?>
  <?= __e('vocabulary.anki.apkg.settings_scheduling') ?>
  <?= __('vocabulary.anki.apkg.settings_import') ?>
</div>

<?php // The commonest wrong turn: arriving here with a deck built in Anki,
      // which has no LWT guids and so updates nothing at all. ?>
<div class="notification is-info is-light mt-4">
  <strong><?= __e('vocabulary.anki.apkg.deck_notice_title') ?></strong>
  <?= __('vocabulary.anki.apkg.deck_notice', ['url' => $deckImportUrl]) ?>
</div>
