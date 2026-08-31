<?php

/**
 * Word Modal View (Bulma + Alpine.js)
 *
 * Displays word information and action buttons in a centered Bulma modal.
 * Supports two views: info (default) and edit (for creating/editing terms).
 * Works with the wordModal and wordEditForm Alpine.js components.
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
<div x-data="wordModal" x-cloak>
  <div
    class="modal reading-modal"
    :class="modalClasses"
    role="dialog"
    aria-modal="true"
    aria-labelledby="word-modal-title">
    <div class="modal-background" @click="close"></div>
    <div class="modal-card" style="max-width: 500px;">
      <header class="modal-card-head py-3">
        <p class="modal-card-title is-size-6" id="word-modal-title" x-text="modalTitle"></p>
        <button
          class="delete"
          aria-label="<?= __e('text.modal.close') ?>"
          @click="close"
          :disabled="isLoading"></button>
      </header>
      <section class="modal-card-body">
        <!-- Loading overlay -->
        <div x-show="isLoading" class="has-text-centered py-4">
          <span class="icon is-large">
            <i data-lucide="loader-2" class="icon-spin" style="width:24px;height:24px" aria-hidden="true"></i>
          </span>
          <p class="mt-2"><?= __e('text.common.loading') ?></p>
        </div>

        <!-- INFO VIEW -->
        <template x-if="viewMode === 'info' && word && !isLoading">
          <div>
            <!-- Word text and audio -->
            <div class="is-flex is-justify-content-space-between is-align-items-center mb-3">
              <span class="is-size-4 has-text-weight-bold" x-text="wordText"></span>
              <button class="button is-small is-rounded" @click="speakWord" title="<?= __e('text.modal.listen') ?>">
                <?php echo \Lwt\Shared\UI\Helpers\IconHelper::render('volume-2', ['size' => 16]); ?>
              </button>
            </div>

            <!-- Translation/Romanization for known words -->
            <template x-if="hasTranslation">
              <div class="mb-3">
                <p class="has-text-grey-dark" x-text="$markdown(wordTranslation)"></p>
                <template x-if="hasRomanization">
                  <p class="is-size-7 has-text-grey" x-text="wordRomanization"></p>
                </template>
              </div>
            </template>

            <!-- Notes for known words -->
            <template x-if="hasNotes">
              <div class="mb-3">
                <p class="is-size-7 has-text-grey mb-1"><?= __e('text.modal.notes_label') ?></p>
                <p class="has-text-grey-dark is-size-7" x-text="$markdown(wordNotes)"></p>
              </div>
            </template>

            <!-- Tags if present -->
            <template x-if="hasTags">
              <div class="mb-3">
                <span class="tag is-info is-light" x-text="wordTags"></span>
              </div>
            </template>

            <!-- Status buttons for known words -->
            <template x-if="!isUnknown">
              <div class="mb-4">
                <p class="is-size-7 has-text-grey mb-2"><?= __e('text.modal.status') ?>:</p>
                <div class="buttons are-small">
                  <template x-for="s in [1,2,3,4,5]" :key="s">
                    <button
                      class="button"
                      :class="getStatusButtonClass(s)"
                      :disabled="isLoading"
                      @click="setStatus(s)"
                      x-text="s"
                    ></button>
                  </template>
                  <button
                    class="button"
                    :class="isCurrentStatus(99) ? 'is-success' : 'is-outlined is-success'"
                    :disabled="isLoading"
                    @click="setStatus(99)"
                  ><?= __e('common.status_well_known') ?></button>
                  <button
                    class="button"
                    :class="isCurrentStatus(98) ? 'is-warning' : 'is-outlined is-warning'"
                    :disabled="isLoading"
                    @click="setStatus(98)"
                  ><?= __e('common.status_ignored') ?></button>
                </div>
              </div>
            </template>

            <!-- Quick actions for unknown words -->
            <template x-if="isUnknown">
              <div class="mb-4">
                <p class="is-size-7 has-text-grey mb-2"><?= __e('text.modal.quick_actions') ?></p>
                <div class="buttons">
                  <button class="button is-success" :disabled="isLoading" @click="markWellKnown">
                    <?php echo \Lwt\Shared\UI\Helpers\IconHelper::render('check', ['size' => 16]); ?>
                    <span class="ml-1"><?= __e('text.modal.know_well') ?></span>
                  </button>
                  <button class="button is-warning" :disabled="isLoading" @click="markIgnored">
                    <?php echo \Lwt\Shared\UI\Helpers\IconHelper::render('x', ['size' => 16]); ?>
                    <span class="ml-1"><?= __e('text.modal.ignore') ?></span>
                  </button>
                </div>
              </div>
            </template>

            <!-- Edit/Delete for known words -->
            <template x-if="!isUnknown && hasWordId">
              <div class="mb-4">
                <div class="buttons are-small">
                  <button class="button is-info is-outlined" @click="showEditForm" :disabled="isLoading">
                    <?php echo \Lwt\Shared\UI\Helpers\IconHelper::render('edit', ['size' => 14]); ?>
                    <span class="ml-1"><?= __e('text.common.edit') ?></span>
                  </button>
                  <button class="button is-danger is-outlined" :disabled="isLoading" @click="deleteWord">
                    <?php echo \Lwt\Shared\UI\Helpers\IconHelper::render('trash-2', ['size' => 14]); ?>
                    <span class="ml-1"><?= __e('text.common.delete') ?></span>
                  </button>
                </div>
              </div>
            </template>

            <!-- Edit link for unknown words -->
            <template x-if="isUnknown">
              <div class="mb-4">
                <button class="button is-info" @click="showEditForm" :disabled="isLoading">
                  <?php echo \Lwt\Shared\UI\Helpers\IconHelper::render('edit', ['size' => 16]); ?>
                  <span class="ml-1"><?= __e('text.modal.add_with_translation') ?></span>
                </button>
              </div>
            </template>

            <!-- Dictionary links -->
            <template x-if="hasDictUrl('dict1') || hasDictUrl('dict2') || hasDictUrl('translator')">
            <div class="pt-3" style="border-top: 1px solid #dbdbdb;">
              <p class="is-size-7 has-text-grey mb-2"><?= __e('text.modal.lookup') ?></p>
              <div class="buttons are-small">
                <a
                  x-show="hasDictUrl('dict1')"
                  :href="getDictUrl('dict1')"
                  target="_blank"
                  class="button is-outlined is-link"
                  rel="noopener">
                  <?php echo \Lwt\Shared\UI\Helpers\IconHelper::render('book-open', ['size' => 14]); ?>
                  <span class="ml-1"><?= __e('text.modal.dict1') ?></span>
                </a>
                <a
                  x-show="hasDictUrl('dict2')"
                  :href="getDictUrl('dict2')"
                  target="_blank"
                  class="button is-outlined is-link"
                  rel="noopener">
                  <?php echo \Lwt\Shared\UI\Helpers\IconHelper::render('book-open', ['size' => 14]); ?>
                  <span class="ml-1"><?= __e('text.modal.dict2') ?></span>
                </a>
                <a
                  x-show="hasDictUrl('translator')"
                  :href="getDictUrl('translator')"
                  target="_blank"
                  class="button is-outlined is-link"
                  rel="noopener">
                  <?php echo \Lwt\Shared\UI\Helpers\IconHelper::render('languages', ['size' => 14]); ?>
                  <span class="ml-1"><?= __e('text.modal.translate') ?></span>
                </a>
              </div>
            </div>
            </template>
          </div>
        </template>

        <!-- EDIT VIEW -->
        <template x-if="viewMode === 'edit' && !isLoading">
          <div x-data="wordEditForm">
            <!-- General error message -->
            <template x-if="hasGeneralError">
              <div class="notification is-danger is-light mb-4">
                <button class="delete" @click="clearGeneralError()"></button>
                <span x-text="generalError"></span>
              </div>
            </template>

            <!-- Term (read-only) -->
            <div class="field">
              <label class="label is-small"><?= __e('text.modal.term') ?></label>
              <div class="control">
                <input class="input" type="text" :value="formText" disabled>
              </div>
            </div>

            <!-- Section tabs — one section visible at a time keeps the sheet
                 short enough to leave the reading text visible behind it. -->
            <div class="tabs is-boxed is-small is-fullwidth mb-3">
              <ul>
                <li :class="{ 'is-active': activeTab === 'translate' }">
                  <a @click.prevent="setActiveTab('translate')">
                    <span><?= __e('text.modal.translation') ?></span>
                    <template x-if="tabHasError('translate')">
                      <span class="tag is-danger is-small ml-1"></span>
                    </template>
                  </a>
                </li>
                <li :class="{ 'is-active': activeTab === 'example' }">
                  <a @click.prevent="setActiveTab('example')">
                    <span><?= __e('text.modal.example_sentence') ?></span>
                    <template x-if="tabHasError('example')"><span class="tag is-danger is-small ml-1"></span></template>
                  </a>
                </li>
                <li :class="{ 'is-active': activeTab === 'notes' }">
                  <a @click.prevent="setActiveTab('notes')">
                    <span><?= __e('text.modal.notes') ?></span>
                    <template x-if="tabHasError('notes')"><span class="tag is-danger is-small ml-1"></span></template>
                  </a>
                </li>
                <li :class="{ 'is-active': activeTab === 'tags' }">
                  <a @click.prevent="setActiveTab('tags')">
                    <span><?= __e('text.modal.tags') ?></span>
                  </a>
                </li>
              </ul>
            </div>

            <!-- Translation tab: translation, romanization, status, similar terms -->
            <template x-if="activeTab === 'translate'">
              <div>
                <div class="field">
                  <label class="label is-small">
                    <?= __e('text.modal.translation') ?> <span class="has-text-danger">*</span>
                  </label>
                  <div class="control">
                    <textarea
                      class="textarea"
                      :class="{ 'is-danger': hasFieldError('translation') }"
                      x-model="translation"
                      @blur="validateField('translation')"
                      rows="2"
                      placeholder="<?= __e('text.modal.translation_placeholder') ?>"
                    ></textarea>
                  </div>
                  <template x-if="hasFieldError('translation')">
                    <p class="help is-danger" x-text="getFieldError('translation')"></p>
                  </template>
                </div>

                <template x-if="showRomanization">
                  <div class="field">
                    <label class="label is-small"><?= __e('text.modal.romanization') ?></label>
                    <div class="control">
                      <input
                        class="input"
                        :class="{ 'is-danger': hasFieldError('romanization') }"
                        type="text"
                        x-model="romanization"
                        @blur="validateField('romanization')"
                        placeholder="<?= __e('text.modal.romanization_placeholder') ?>"
                      >
                    </div>
                    <template x-if="hasFieldError('romanization')">
                      <p class="help is-danger" x-text="getFieldError('romanization')"></p>
                    </template>
                  </div>
                </template>

                <div class="field">
                  <label class="label is-small"><?= __e('text.modal.status') ?></label>
                  <div class="buttons are-small">
                    <template x-for="s in statuses" :key="s.value">
                      <button
                        type="button"
                        class="button"
                        :class="getStatusButtonClass(s.value)"
                        @click="setFormStatus(s.value)"
                        x-text="s.abbr"
                      ></button>
                    </template>
                  </div>
                </div>

                <template x-if="hasSimilarTerms">
                  <div class="field">
                    <label class="label is-small"><?= __e('text.modal.similar_terms') ?></label>
                    <div class="is-size-7">
                      <template x-for="term in formSimilarTerms" :key="term.id">
                        <div class="is-flex is-justify-content-space-between is-align-items-center py-1"
                             style="border-bottom: 1px solid #f0f0f0;">
                          <div>
                            <span class="has-text-weight-semibold" x-text="term.text"></span>
                            <span class="has-text-grey" x-text="getSimilarTermDisplay(term)"></span>
                          </div>
                          <button
                            type="button"
                            class="button is-small is-ghost"
                            @click="copyFromSimilar(term)"
                            title="Copy translation"
                            x-show="term.translation"
                          >
                            <?php echo \Lwt\Shared\UI\Helpers\IconHelper::render('copy', ['size' => 12]); ?>
                          </button>
                        </div>
                      </template>
                    </div>
                  </div>
                </template>
              </div>
            </template>

            <!-- Example tab -->
            <template x-if="activeTab === 'example'">
              <div class="field">
                <label class="label is-small"><?= __e('text.modal.example_sentence') ?></label>
                <div class="control">
                  <textarea
                    class="textarea"
                    :class="{ 'is-danger': hasFieldError('sentence') }"
                    x-model="sentence"
                    @blur="validateField('sentence')"
                    rows="3"
                    placeholder="<?= __e('text.modal.sentence_placeholder') ?>"
                  ></textarea>
                </div>
                <template x-if="hasFieldError('sentence')">
                  <p class="help is-danger" x-text="getFieldError('sentence')"></p>
                </template>
                <p class="help"><?= __e('text.modal.sentence_help') ?></p>
              </div>
            </template>

            <!-- Notes tab -->
            <template x-if="activeTab === 'notes'">
              <div class="field">
                <label class="label is-small"><?= __e('text.modal.notes') ?></label>
                <div class="control">
                  <textarea
                    class="textarea"
                    :class="{ 'is-danger': hasFieldError('notes') }"
                    x-model="notes"
                    @blur="validateField('notes')"
                    rows="3"
                    placeholder="<?= __e('text.modal.notes_placeholder') ?>"
                  ></textarea>
                </div>
                <template x-if="hasFieldError('notes')">
                  <p class="help is-danger" x-text="getFieldError('notes')"></p>
                </template>
              </div>
            </template>

            <!-- Tags tab -->
            <template x-if="activeTab === 'tags'">
              <div class="field">
                <label class="label is-small"><?= __e('text.modal.tags') ?></label>
                <div class="control">
                  <!-- Current tags -->
                  <div class="tags mb-2" x-show="hasTags">
                    <template x-for="tag in formTags" :key="tag">
                      <span class="tag is-info is-light">
                        <span x-text="tag"></span>
                        <button type="button" class="delete is-small" @click="removeTag(tag)"></button>
                      </span>
                    </template>
                  </div>
                  <!-- Tag input with autocomplete -->
                  <div class="dropdown" :class="{ 'is-active': showTagSuggestions }">
                    <div class="dropdown-trigger" style="width: 100%;">
                      <input
                        class="input is-small"
                        type="text"
                        x-model="tagInput"
                        @input="filterTags"
                        @keydown.enter.prevent="addTag(tagInput)"
                        @blur="hideTagSuggestions"
                        placeholder="<?= __e('text.modal.add_tag') ?>"
                      >
                    </div>
                    <div class="dropdown-menu" role="menu" style="width: 100%;">
                      <div class="dropdown-content">
                        <template x-for="tag in filteredTags" :key="tag">
                          <a href="#" class="dropdown-item"
                             @mousedown.prevent="selectTagSuggestion(tag)" x-text="tag"></a>
                        </template>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </template>

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
                  <span class="ml-1"><?= __e('text.common.save') ?></span>
                </button>
              </div>
              <div class="control">
                <button type="button" class="button" @click="cancel" :disabled="isSubmitting">
                  <?= __e('text.common.cancel') ?>
                </button>
              </div>
            </div>
          </div>
        </template>
      </section>
    </div>
  </div>
</div>
