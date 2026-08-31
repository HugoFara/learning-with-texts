<?php

/**
 * Anki deck import — the summary after terms have been created.
 *
 * Expected variables:
 * - $result:     DeckImportResult   Counts and samples from the import
 * - $languageId: int                Language the terms landed in
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

use Lwt\Modules\Vocabulary\Application\Services\Anki\DeckImportResult;
use Lwt\Modules\Vocabulary\Domain\ValueObject\TermStatus;

// Extracted from the controller's data array, so their types are asserted here
// rather than declared -- the same shape every view in this module uses.
assert($result instanceof DeckImportResult);
assert(is_int($languageId));

$statusName = static function (int $status): string {
    // 1-5 are levels and want their number; 98 and 99 have names of their own.
    return TermStatus::isValid($status) && !TermStatus::isLearningValue($status)
        ? TermStatus::fromInt($status)->displayName()
        : __('vocabulary.anki.deck.status_level', ['level' => $status]);
};

?>
<h1><?= __e('vocabulary.anki.deck.title') ?></h1>

<div class="notification is-success">
  <p><strong><?= __e(
      $result->created === 1
            ? 'vocabulary.anki.deck.imported'
            : 'vocabulary.anki.deck.imported_plural',
      ['count' => $result->created]
  ) ?></strong></p>
</div>

<table class="table is-narrow">
  <tbody>
    <tr>
      <th><?= __e('vocabulary.anki.deck.notes_read') ?></th>
      <td><?= $result->totalNotes ?></td>
    </tr>
    <tr>
      <th><?= __e('vocabulary.anki.deck.terms_created') ?></th>
      <td><?= $result->created ?></td>
    </tr>
    <tr>
      <th><?= __e('vocabulary.anki.deck.already_in_lwt') ?></th>
      <td><?= $result->skippedExisting ?></td>
    </tr>
    <?php if ($result->skippedEmpty > 0) { ?>
      <tr>
        <th><?= __e('vocabulary.anki.deck.skipped_empty') ?></th>
        <td><?= $result->skippedEmpty ?></td>
      </tr>
    <?php } ?>
    <?php if ($result->skippedTooLong > 0) { ?>
      <tr>
        <th><?= __e('vocabulary.anki.deck.skipped_too_long') ?></th>
        <td><?= $result->skippedTooLong ?></td>
      </tr>
    <?php } ?>
  </tbody>
</table>

<?php if ($result->statusCounts !== []) { ?>
  <h2 class="title is-5 mt-5"><?= __e('vocabulary.anki.deck.status_breakdown') ?></h2>
  <ul>
    <?php foreach ($result->statusCounts as $status => $count) { ?>
      <li>
        <?= htmlspecialchars($statusName($status), ENT_QUOTES, 'UTF-8') ?>:
        <?= $count ?>
      </li>
    <?php } ?>
  </ul>
<?php } ?>

<?php if ($result->samples !== []) { ?>
  <p class="mt-4">
    <strong><?= __e('vocabulary.anki.deck.samples') ?></strong>
    <?= htmlspecialchars(implode(', ', $result->samples), ENT_QUOTES, 'UTF-8') ?>…
  </p>
<?php } ?>

<?php // Every note empty means the wrong field was picked, not an empty deck. ?>
<?php if ($result->created === 0 && $result->skippedEmpty === $result->totalNotes) { ?>
  <div class="notification is-warning mt-4"><?= __e('vocabulary.anki.deck.all_empty_warning') ?></div>
<?php } ?>

<p class="mt-5">
  <a class="button" href="/words/edit?lang=<?= $languageId ?>">
    <?= __e('vocabulary.anki.deck.view_terms') ?>
  </a>
  <a class="button is-light" href="/vocabulary/anki-deck/import">
    <?= __e('vocabulary.anki.deck.import_another') ?>
  </a>
</p>
