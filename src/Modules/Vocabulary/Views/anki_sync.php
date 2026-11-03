<?php

/**
 * Pull reviews from Anki over AnkiConnect (#264).
 *
 * The page only explains and carries data: the ankiSync component talks to
 * AnkiConnect on the learner's own machine and posts the reviews it reads to
 * POST /api/v1/anki/reviews.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Vocabulary\Views
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 */

declare(strict_types=1);

namespace Lwt\Modules\Vocabulary\Views;

use Lwt\Modules\Vocabulary\Application\Services\Anki\AnkiReviewPull;
use Lwt\Modules\Vocabulary\Infrastructure\Anki\AnkiSchema;
use Lwt\Shared\UI\Helpers\ConfigIsland;

ConfigIsland::render('anki-sync-config', [
    'ankiConnectUrl' => 'http://127.0.0.1:8765',
    'noteType' => AnkiSchema::NOTETYPE_NAME,
    'idField' => AnkiSchema::FIELD_LWT_ID,
    'batchSize' => AnkiReviewPull::MAX_REVIEWS,
]);
?>
<div x-data="ankiSync" class="content">
  <p><?= __e('vocabulary.anki.sync.intro') ?></p>

  <?php // Contains <code> around Anki's own names, so it is not escaped. ?>
  <ol>
    <li><?= __('vocabulary.anki.sync.step_addon_html') ?></li>
    <li><?= __('vocabulary.anki.sync.step_export_html') ?></li>
    <li><?= __e('vocabulary.anki.sync.step_open') ?></li>
  </ol>

  <div class="field">
    <div class="control">
      <button type="button" class="button is-primary"
              :class="{ 'is-loading': busy }" :disabled="busy" @click="pull()">
        <?= __e('vocabulary.anki.sync.button') ?>
      </button>
    </div>
  </div>

  <p x-show="progress" class="help" x-text="progress"></p>

  <div x-show="error" class="notification is-danger" x-cloak>
    <p x-text="error"></p>
  </div>

  <div x-show="summary" class="notification is-success" x-cloak>
    <p><strong><?= __e('vocabulary.anki.sync.done') ?></strong></p>
    <ul>
      <li x-text="appliedLabel()"></li>
      <li x-text="termsLabel()"></li>
      <li x-text="duplicatesLabel()"></li>
      <li x-show="hasRebuilt()" x-text="rebuiltLabel()"></li>
      <li x-show="hasSkipped()" x-text="skippedLabel()"></li>
    </ul>
  </div>

  <?php // Contains <code> and <a>, so it is not escaped. ?>
  <div class="notification is-info is-light">
    <?= __('vocabulary.anki.sync.how_html') ?>
  </div>
  <div class="notification is-warning is-light">
    <?= __('vocabulary.anki.sync.hosted_html') ?>
  </div>
</div>
