<?php

/**
 * Multi-Word Modal View (Bulma + Alpine.js)
 *
 * Displays multi-word expression form for creating/editing terms.
 * Works with the multiWordModal Alpine.js component and multiWordForm store.
 *
 * PHP version 8.2
 *
 * @category Views
 * @package  Lwt
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Views\Text;

?>
<div x-data="multiWordModal" x-cloak>
  <div class="modal reading-modal" :class="modalClasses"
       role="dialog" aria-modal="true" aria-labelledby="multi-word-modal-title">
    <div class="modal-background" @click="close"></div>
    <div class="modal-card" style="max-width: 500px;">
      <header class="modal-card-head py-3">
        <p class="modal-card-title is-size-6" id="multi-word-modal-title" x-text="modalTitle"></p>
        <button class="delete" aria-label="Close dialog" @click="close" :disabled="isLoading || isSubmitting"></button>
      </header>
      <section class="modal-card-body">
        <!-- Loading overlay -->
        <div x-show="isLoading" class="has-text-centered py-4">
          <span class="icon is-large">
            <i data-lucide="loader-2" class="icon-spin" style="width:24px;height:24px" aria-hidden="true"></i>
          </span>
          <p class="mt-2">Loading...</p>
        </div>

        <!-- Form content -->
        <template x-if="!isLoading">
          <div>
            <!-- General error message -->
            <template x-if="hasGeneralError">
              <div class="notification is-danger is-light mb-4">
                <button class="delete" @click="clearGeneralError()"></button>
                <span x-text="generalError"></span>
              </div>
            </template>

            <!-- Multi-word text (read-only) -->
            <div class="field">
              <label class="label is-small">Multi-Word Expression</label>
              <div class="control">
                <input class="input" type="text" :value="formText" disabled>
              </div>
              <p class="help" x-text="wordCountLabel"></p>
            </div>

            <!-- Translation -->
            <div class="field">
              <label class="label is-small">Translation</label>
              <div class="control">
                <textarea
                  class="textarea"
                  :class="{ 'is-danger': hasTranslationError }"
                  x-model="translation"
                  @blur="validateField('translation')"
                  rows="2"
                  placeholder="Enter translation..."
                ></textarea>
              </div>
              <template x-if="hasTranslationError">
                <p class="help is-danger" x-text="translationError"></p>
              </template>
            </div>

            <!-- Romanization (if enabled for language) -->
            <template x-if="showRomanization">
              <div class="field">
                <label class="label is-small">Romanization</label>
                <div class="control">
                  <input
                    class="input"
                    :class="{ 'is-danger': hasRomanizationError }"
                    type="text"
                    x-model="romanization"
                    @blur="validateField('romanization')"
                    placeholder="Enter romanization..."
                  >
                </div>
                <template x-if="hasRomanizationError">
                  <p class="help is-danger" x-text="romanizationError"></p>
                </template>
              </div>
            </template>

            <!-- Sentence -->
            <div class="field">
              <label class="label is-small">Example Sentence</label>
              <div class="control">
                <textarea
                  class="textarea"
                  :class="{ 'is-danger': hasSentenceError }"
                  x-model="sentence"
                  @blur="validateField('sentence')"
                  rows="2"
                  placeholder="Example sentence with {term} in braces..."
                ></textarea>
              </div>
              <template x-if="hasSentenceError">
                <p class="help is-danger" x-text="sentenceError"></p>
              </template>
              <p class="help">Use {curly braces} around the term</p>
            </div>

            <!-- Status (1-5 only for multi-words) -->
            <div class="field">
              <label class="label is-small">Status</label>
              <div class="buttons are-small">
                <template x-for="s in statuses" :key="s.value">
                  <button
                    type="button"
                    class="button"
                    :class="getStatusButtonClass(s.value)"
                    @click="setStatus(s.value)"
                    x-text="s.abbr"
                    :title="s.label"
                  ></button>
                </template>
              </div>
            </div>

            <!-- Action buttons -->
            <div class="field is-grouped mt-5">
              <div class="control">
                <button
                  type="button"
                  class="button is-primary"
                  :class="{ 'is-loading': isSubmitting }"
                  :disabled="!canSubmit"
                  @click="save"
                >
                  <?php echo \Lwt\Shared\UI\Helpers\IconHelper::render('save', ['size' => 16]); ?>
                  <span class="ml-1">Save</span>
                </button>
              </div>
              <div class="control">
                <button type="button" class="button" @click="close" :disabled="isSubmitting">
                  Cancel
                </button>
              </div>
            </div>
          </div>
        </template>
      </section>
    </div>
  </div>
</div>
