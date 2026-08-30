<?php

declare(strict_types=1);

/**
 * Desktop Text Reading Layout View
 *
 * Modern text reading interface using Alpine.js
 * with client-side rendering and reactive word state.
 *
 * Variables expected:
 * - $textId: int - Text ID
 * - $langId: int - Language ID (optional, will be fetched from API)
 * - $title: string - Text title (optional)
 * - $sourceUri: string|null - Source URI (optional)
 *
 * PHP version 8.2
 *
 * @category Views
 * @package  Lwt
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 *
 * @var int $textId
 * @var int $langId
 * @var string $title
 * @var string|null $sourceUri
 */

namespace Lwt\Views\Text;

use Lwt\Shared\UI\Helpers\PageLayoutHelper;
use Lwt\Shared\UI\Helpers\ConfigIsland;

// Type-safe variable extraction from controller context
assert(is_int($textId));
assert(is_int($langId));
assert(is_string($title));
// Note: $sourceUri is typed as string|null in file-level docblock

?>
<!-- Main navigation -->
<?php echo PageLayoutHelper::buildNavbarPlaceholder('texts'); ?>

<div x-data="textReader" class="reading-page" x-cloak>
  <!-- Reading toolbar -->
  <div class="box py-2 px-4 mb-0" style="border-radius: 0;">
    <div class="level is-mobile">
      <div class="level-left">
        <div class="level-item">
          <strong x-text="title || 'Loading...'"></strong>
          <?php
            /**
             * @var string|null $sourceUri
             */
            $sourceUriTyped = $sourceUri;
            if (
                $sourceUriTyped !== null
                && $sourceUriTyped !== ''
                && !str_starts_with(trim($sourceUriTyped), '#')
            ) : ?>
                <?php
                echo \Lwt\Shared\UI\Helpers\IconHelper::link(
                    'external-link',
                    $sourceUriTyped,
                    ['alt' => 'Source'],
                    ['target' => '_blank', 'rel' => 'noopener', 'class' => 'ml-2', 'title' => 'Source']
                );
                ?>
            <?php endif; ?>
        </div>
      </div>
      <div class="level-right">
        <div class="level-item">
          <div class="field is-grouped is-grouped-multiline">
            <div class="control">
              <a href="/review?text=<?php echo $textId; ?>"
                class="button is-small"><?= __e('text.common.review') ?></a>
            </div>
            <div class="control">
              <a href="/texts/<?php echo $textId; ?>/edit"
                class="button is-small"><?= __e('text.common.edit') ?></a>
            </div>
            <!-- Display settings dropdown -->
            <div class="control">
              <div class="dropdown is-hoverable is-right">
                <div class="dropdown-trigger">
                  <button class="button is-small">
                    <span class="icon is-small">
                      <i data-lucide="sliders"
                        style="width:14px;height:14px"></i>
                    </span>
                    <span><?= __e('text.read.display') ?></span>
                  </button>
                </div>
                <div class="dropdown-menu" style="min-width:220px">
                  <div class="dropdown-content">
                    <!-- Toggles -->
                    <a class="dropdown-item"
                      @click.prevent="toggleShowAll()">
                      <span class="icon is-small mr-2">
                        <i x-show="showAll" data-lucide="square-check-big"
                          style="width:14px;height:14px"></i>
                        <i x-show="!showAll" data-lucide="square"
                          style="width:14px;height:14px"></i>
                      </span>
                      <?= __e('text.read.multi_word') ?>
                    </a>
                    <a class="dropdown-item"
                      @click.prevent="toggleTranslations()">
                      <span class="icon is-small mr-2">
                        <i x-show="showTranslations"
                          data-lucide="square-check-big"
                          style="width:14px;height:14px"></i>
                        <i x-show="!showTranslations"
                          data-lucide="square"
                          style="width:14px;height:14px"></i>
                      </span>
                      <?= __e('text.read.translations') ?>
                    </a>
                    <hr class="dropdown-divider">
                    <!-- Text size -->
                    <div class="dropdown-item">
                      <label class="label is-small mb-1">
                        <?= __e('text.read.text_size') ?>
                      </label>
                      <div class="field has-addons">
                        <p class="control">
                          <button class="button is-small"
                            @click="decreaseTextSize()">
                            <span class="icon is-small">
                              <i data-lucide="minus"
                                style="width:12px;height:12px">
                              </i>
                            </span>
                          </button>
                        </p>
                        <p class="control">
                          <span class="button is-small is-static"
                            x-text="readerTextSize + '%'"
                            style="min-width:3.5em">
                          </span>
                        </p>
                        <p class="control">
                          <button class="button is-small"
                            @click="increaseTextSize()">
                            <span class="icon is-small">
                              <i data-lucide="plus"
                                style="width:12px;height:12px">
                              </i>
                            </span>
                          </button>
                        </p>
                      </div>
                    </div>
                    <!-- Reader width -->
                    <div class="dropdown-item">
                      <label class="label is-small mb-1">
                        <?= __e('text.read.reading_width') ?>
                      </label>
                      <input type="range" min="40" max="100"
                        step="5"
                        x-model.number="readerWidth"
                        @input="onReaderWidthChange()"
                        style="width:100%"
                        title="Reading area width">
                    </div>
                    <hr class="dropdown-divider">
                    <!-- Print -->
                    <a class="dropdown-item"
                      href="/text/<?php echo $textId; ?>/print-plain">
                      <span class="icon is-small mr-2">
                        <i data-lucide="printer"
                          style="width:14px;height:14px"></i>
                      </span>
                      <?= __e('text.common.print') ?>
                    </a>
                  </div>
                </div>
              </div>
            </div>
            <!-- Actions dropdown -->
            <div class="control">
              <div class="dropdown is-hoverable is-right">
                <div class="dropdown-trigger">
                  <button class="button is-small"><?= __e('text.read.actions') ?></button>
                </div>
                <div class="dropdown-menu">
                  <div class="dropdown-content">
                    <a class="dropdown-item"
                      @click.prevent="markAllWellKnown()">
                      <?= __e('text.read.mark_all_well_known') ?>
                    </a>
                    <a class="dropdown-item"
                      @click.prevent="markAllIgnored()">
                      <?= __e('text.read.mark_all_ignored') ?>
                    </a>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Audio player: markup-only; the audioPlayer component fetches
       /texts/{id}/audio and reveals itself only when the text has audio. -->
  <?php require __DIR__ . '/audio_player.php'; ?>

  <!-- Chapter navigation: client-rendered from /texts/{id}/book-context
       (book_nav_renderer.ts) so the reader carries no server-rendered chrome.
       Stays empty for a standalone text. -->
  <div id="book-context-nav"></div>

  <!-- Loading state -->
  <div x-show="isLoading" class="has-text-centered py-6">
    <div class="loading-spinner"></div>
    <p class="mt-4 has-text-grey"><?= __e('text.read.loading') ?></p>
  </div>

  <!-- Error state -->
  <template x-if="error">
    <div class="notification is-danger mx-4 mt-4">
      <button class="delete" @click="error = null"></button>
      <p x-text="error"></p>
    </div>
  </template>

  <!-- Status message (mark-all feedback) -->
  <template x-if="statusMessage">
    <div class="notification is-info is-light mx-4 mt-2 py-2 px-4">
      <button class="delete is-small" @click="statusMessage = null"></button>
      <span x-text="statusMessage"></span>
    </div>
  </template>

  <!-- Text content -->
  <div x-show="!isLoading && !error" class="reading-content p-4">
    <div
      id="thetext"
      class="content"
      :class="{ 'hide-translations': !showTranslations }"
      :style="store.rightToLeft ? 'direction: rtl' : ''"
    >
      <!-- Content rendered by JavaScript via textReader.renderTextContent() -->
    </div>
  </div>

  <!-- Word popover (info view - non-blocking) -->
  <?php require __DIR__ . '/word_popover.php'; ?>

  <!-- Word modal (edit view only) -->
  <?php require __DIR__ . '/word_modal.php'; ?>

  <!-- Multi-word modal -->
  <?php require __DIR__ . '/multi_word_modal.php'; ?>
</div>

<style>
/* Reading page specific styles */
.reading-page {
  min-height: 100vh;
}

.reading-content {
  max-width: 100%;
  margin: 0 auto;
  transition: max-width 0.2s ease;
}

#thetext {
  line-height: 1.8;
}

#thetext p {
  margin-bottom: 1rem;
}

/* Word styling */
.wsty, .mwsty {
  cursor: pointer;
  padding: 0 0.1em;
  border-radius: 3px;
}

.wsty:hover, .mwsty:hover {
  background-color: rgba(0, 0, 0, 0.1);
}

/* Status colors - underlines instead of backgrounds */
.status0 { border-bottom: solid 2px #5ABAFF; } /* Unknown - blue */
.status1 { border-bottom: solid 2px #E85A3C; } /* Level 1 - red */
.status2 { border-bottom: solid 2px #E8893C; } /* Level 2 - orange */
.status3 { border-bottom: solid 2px #E8B83C; } /* Level 3 - yellow */
.status4 { border-bottom: solid 2px #E8E23C; } /* Level 4 - pale yellow */
.status5 { border-bottom: solid 2px #66CC66; } /* Level 5 - green */
.status98 { border-bottom: dashed 1px #888888; color: #999; } /* Ignored */
.status99 { border-bottom: solid 2px #CCFFCC; } /* Well-known */

/* Hide translations class */
.hide-translations .word-ann {
  display: none !important;
}

/* Hidden items */
.hide {
  display: none !important;
}

/* Alpine.js cloak */
[x-cloak] {
  display: none !important;
}

/* Loading spinner */
.loading-spinner {
  width: 40px;
  height: 40px;
  margin: 0 auto;
  border: 3px solid #dbdbdb;
  border-top-color: #3273dc;
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
}

@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}
</style>

<?php ConfigIsland::render('text-reader-config', [
    'textId' => $textId,
    'langId' => $langId,
    ]); ?>
