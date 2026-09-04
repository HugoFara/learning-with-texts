<?php

/**
 * Anki deck import — step 2, mapping the deck's fields onto LWT's.
 *
 * Expected variables:
 * - $notetypes:  list<ForeignNotetype>   Note types found in the uploaded file
 * - $fieldNames: list<string>            Every field name across those note types
 * - $languages:  array<string, int>      Language name => id
 * - $matureDays: int                     Interval at which a card counts as known
 * - $error:      string|null             Message to show above the form
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

use Lwt\Modules\Vocabulary\Domain\ValueObject\TermStatus;
use Lwt\Modules\Vocabulary\Infrastructure\Anki\ForeignNotetype;
use Lwt\Shared\UI\Helpers\FormHelper;

// Extracted from the controller's data array, so their types are asserted here
// rather than declared -- the same shape every view in this module uses.
/** @var list<ForeignNotetype> $notetypes */
/** @var list<string> $fieldNames */
/** @var array<string, int> $languages */
assert(is_array($notetypes));
assert(is_array($fieldNames));
assert(is_array($languages));
assert(is_int($matureDays));
assert($error === null || is_string($error));

/**
 * A field picker over the names collected from every note type.
 *
 * @param string       $name     Form field name
 * @param string       $label    Already-escaped label
 * @param list<string> $fields   Field names to offer
 * @param bool         $optional Whether "(none)" is an allowed answer
 */
$fieldSelect = static function (string $name, string $label, array $fields, bool $optional): void {
    /** @var list<string> $fields */
    ?>
    <div class="field">
      <label class="label" for="<?= $name ?>"><?= $label ?></label>
      <div class="control">
        <div class="select">
          <select name="<?= $name ?>" id="<?= $name ?>"<?= $optional ? '' : ' required' ?>>
            <?php if ($optional) { ?>
              <option value=""><?= __e('vocabulary.anki.deck.field_none') ?></option>
            <?php } ?>
            <?php foreach ($fields as $field) { ?>
              <option value="<?= htmlspecialchars($field, ENT_QUOTES, 'UTF-8') ?>">
                <?= htmlspecialchars($field, ENT_QUOTES, 'UTF-8') ?>
              </option>
            <?php } ?>
          </select>
        </div>
      </div>
    </div>
    <?php
};

?>
<h1><?= __e('vocabulary.anki.deck.title') ?></h1>

<?php if ($error !== null) { ?>
  <div class="notification is-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php } ?>

<p class="mb-4"><?= __e('vocabulary.anki.deck.mapping_intro') ?></p>

<form method="post" action="/vocabulary/anki-deck/import">
  <?= FormHelper::csrfField() ?>
  <input type="hidden" name="step" value="import">

  <?php // The note type's own fields are listed beside it, so the choice is informed. ?>
  <div class="field">
    <label class="label" for="notetype"><?= __e('vocabulary.anki.deck.notetype') ?></label>
    <div class="control">
      <div class="select">
        <select name="notetype" id="notetype">
          <?php foreach ($notetypes as $notetype) { ?>
            <option value="<?= $notetype->id ?>">
                <?= __e('vocabulary.anki.deck.notetype_option', [
                    'name' => $notetype->name,
                    'count' => $notetype->noteCount,
                    'fields' => implode(', ', $notetype->fields),
              ]) ?>
            </option>
          <?php } ?>
        </select>
      </div>
    </div>
  </div>

  <?php
    $fieldSelect('term_field', __e('vocabulary.anki.deck.term_field'), $fieldNames, false);
    $fieldSelect('translation_field', __e('vocabulary.anki.deck.translation_field'), $fieldNames, true);
    ?>

  <div class="field">
    <label class="label" for="language"><?= __e('vocabulary.anki.deck.language') ?></label>
    <div class="control">
      <div class="select">
        <select name="language" id="language" required>
          <option value=""><?= __e('vocabulary.anki.deck.language_choose') ?></option>
          <?php foreach ($languages as $name => $id) { ?>
            <option value="<?= $id ?>"><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?></option>
          <?php } ?>
        </select>
      </div>
    </div>
  </div>

  <div class="field">
    <label class="label"><?= __e('vocabulary.anki.deck.word_status') ?></label>
    <div class="control">
      <label class="radio">
        <input type="radio" name="status_mode" value="derive" checked>
        <?= __e('vocabulary.anki.deck.status_derive') ?>
      </label>
    </div>
    <?php // Names two statuses in <strong>, so it is deliberately not escaped. ?>
    <p class="help"><?= __('vocabulary.anki.deck.status_derive_help', ['days' => $matureDays]) ?></p>
    <div class="control mt-2">
      <label class="radio">
        <input type="radio" name="status_mode" value="fixed">
        <?= __e('vocabulary.anki.deck.status_fixed') ?>
      </label>
    </div>
    <div class="control mt-2">
      <div class="select is-small">
        <?php // Labels come from TermStatus, the one place that names a status. ?>
        <select name="fixed_status">
          <option value="<?= TermStatus::WELL_KNOWN ?>">
            <?= htmlspecialchars(TermStatus::wellKnown()->displayName(), ENT_QUOTES, 'UTF-8') ?>
          </option>
          <?php foreach ([1, 2, 3, 4, 5] as $level) { ?>
            <option value="<?= $level ?>">
                <?= __e('vocabulary.anki.deck.status_level', ['level' => $level]) ?>
            </option>
          <?php } ?>
          <option value="<?= TermStatus::IGNORED ?>">
            <?= htmlspecialchars(TermStatus::ignored()->displayName(), ENT_QUOTES, 'UTF-8') ?>
          </option>
        </select>
      </div>
    </div>
  </div>

  <div class="field">
    <div class="control">
      <label class="checkbox">
        <input type="checkbox" name="import_tags" value="1" checked>
        <?= __e('vocabulary.anki.deck.import_tags') ?>
      </label>
    </div>
  </div>

  <div class="field">
    <div class="control">
      <button class="button is-primary" type="submit"><?= __e('vocabulary.anki.deck.import') ?></button>
    </div>
  </div>
</form>
