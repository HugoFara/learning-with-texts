/**
 * Review View - Client-side rendered review interface.
 *
 * Renders the entire review UI using Alpine.js and vanilla JavaScript.
 * No server-side HTML generation - fully reactive SPA-style interface.
 *
 * @license Unlicense <http://unlicense.org/>
 * @since   3.0.0
 */

import { onDomReady } from '@shared/utils/dom_ready';
import Alpine from 'alpinejs';
import type { ReviewStoreState, ReviewConfig, LangSettings } from '../stores/review_store';
import { getReviewStore, initReviewStore } from '../stores/review_store';
import { ReviewApi, type TableReviewWord } from '@modules/review/api/review_api';
import { speechDispatcher } from '@shared/utils/user_interactions';
import { saveSetting } from '@shared/utils/ajax_utilities';
import { t } from '@shared/i18n/translator';
import { openTermEditModal } from '@modules/vocabulary/components/term_edit_modal';

/**
 * Review types configuration. Labels/titles resolved via i18n at build time.
 */
function getReviewTypes(): { id: number; label: string; title: string }[] {
  return [
    { id: 1, label: t('review.type.sentence_to_translation.label'),
      title: t('review.type.sentence_to_translation.title') },
    { id: 2, label: t('review.type.sentence_to_term.label'),
      title: t('review.type.sentence_to_term.title') },
    { id: 3, label: t('review.type.sentence_to_both.label'),
      title: t('review.type.sentence_to_both.title') },
    { id: 4, label: t('review.type.term_to_translation.label'),
      title: t('review.type.term_to_translation.title') },
    { id: 5, label: t('review.type.translation_to_term.label'),
      title: t('review.type.translation_to_term.title') },
  ];
}

/**
 * The four FSRS grades, in the order they are shown and keyed 1-4.
 *
 * Again is the only one that counts as a failure, which is why it alone gets
 * the danger colour; Hard/Good/Easy all raise the term's status by one, and
 * differ in what they tell the scheduler.
 */
const GRADES: { value: 1 | 2 | 3 | 4; labelKey: string; style: string }[] = [
  { value: 1, labelKey: 'review.card.grade_again', style: 'is-danger' },
  { value: 2, labelKey: 'review.card.grade_hard', style: 'is-warning' },
  { value: 3, labelKey: 'review.card.grade_good', style: 'is-success' },
  { value: 4, labelKey: 'review.card.grade_easy', style: 'is-info' },
];

/**
 * Render the complete review interface.
 */
export function renderReviewApp(container: HTMLElement): void {
  // Build the complete HTML structure
  container.innerHTML = buildReviewAppHTML();

  // Initialize Alpine on the container
  Alpine.initTree(container);
}

/**
 * Build the complete review app HTML.
 */
function buildReviewAppHTML(): string {
  return `
    <div x-data="reviewApp" class="review-page" x-cloak>
      ${buildReviewToolbar()}
      ${buildProgressBar()}
      ${buildMainContent()}
      ${buildWordModal()}
    </div>
    ${buildStyles()}
  `;
}

/**
 * Build review toolbar HTML (below main navbar).
 */
function buildReviewToolbar(): string {
  return `
    <div class="box py-2 px-4 mb-0" style="border-radius: 0;">
      <div class="level is-mobile">
        <div class="level-left">
          <div class="level-item">
            <strong>${escapeHtml(t('review.toolbar.review_label'))} <span x-text="store.title"></span></strong>
          </div>
        </div>
        <div class="level-right">
          <div class="level-item">
            <div class="field is-grouped is-grouped-multiline">
              <!-- Review type buttons -->
              <div class="control">
                <div class="buttons are-small">
                  ${getReviewTypes().map(rt => `
                    <button class="button"
                            :class="{ 'is-primary': store.reviewType === ${rt.id} && !store.isTableMode }"
                            @click="switchReviewType(${rt.id})"
                            title="${escapeHtml(rt.title)}">
                      ${escapeHtml(rt.label)}
                    </button>
                  `).join('')}
                  <button class="button"
                          :class="{ 'is-primary': store.isTableMode }"
                          @click="switchToTable">
                    ${escapeHtml(t('review.header.button_table'))}
                  </button>
                </div>
              </div>
              <div class="control">
                <label class="checkbox">
                  <input type="checkbox" :checked="store.readAloudEnabled" @change="toggleReadAloud($event)">
                  ${escapeHtml(t('review.toolbar.read_aloud'))}
                </label>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  `;
}

/**
 * Build progress bar HTML.
 */
function buildProgressBar(): string {
  return `
    <div class="box py-2 px-4 mb-0 review-progress-section">
      <div class="level is-mobile">
        <div class="level-left">
          <div class="level-item">
            <span>${escapeHtml(t('review.progress.time'))} </span>
            <span x-text="store.timer.elapsed">00:00</span>
          </div>
        </div>

        <div class="level-item is-flex-grow-1 mx-4">
          <div class="review-progress">
            <div class="review-progress-remaining"
                 :style="'width: ' + progressPercent.remaining + '%'"></div>
            <div class="review-progress-wrong"
                 :style="'width: ' + progressPercent.wrong + '%'"></div>
            <div class="review-progress-correct"
                 :style="'width: ' + progressPercent.correct + '%'"></div>
          </div>
        </div>

        <div class="level-right">
          <div class="level-item">
            <span class="tag is-medium is-light" title="${escapeHtml(t('review.progress.remaining_total_title'))}">
              <span class="has-text-weight-semibold"
                    title="${escapeHtml(t('review.progress.remaining_title'))}"
                    x-text="store.progress.remaining"></span>
              <span class="mx-1 has-text-grey">/</span>
              <span class="has-text-grey"
                    title="${escapeHtml(t('review.progress.total_title'))}"
                    x-text="store.progress.total"></span>
            </span>
          </div>
        </div>
      </div>
    </div>
  `;
}

/**
 * Build main content area HTML.
 */
function buildMainContent(): string {
  return `
    <!-- Loading state -->
    <div x-show="store.isLoading && !store.isInitialized" class="has-text-centered py-6">
      <div class="loading-spinner"></div>
      <p class="mt-4 has-text-grey">${escapeHtml(t('review.loading'))}</p>
    </div>

    <!-- Error state -->
    <template x-if="store.error">
      <div class="notification is-danger mx-4 mt-4">
        <button class="delete" @click="store.error = null"></button>
        <p x-text="store.error"></p>
      </div>
    </template>

    <!-- Word review content -->
    <div x-show="!store.isTableMode && !store.error && store.isInitialized" class="review-content p-4">
      ${buildFinishedMessage()}
      ${buildWordReviewArea()}
    </div>

    <!-- Table review content -->
    <div x-show="store.isTableMode && !store.error && store.isInitialized" class="table-review-content p-4">
      <div x-data="tableReview" x-init="init()">
        ${buildTableReview()}
      </div>
    </div>
  `;
}

/**
 * Build finished message HTML.
 */
function buildFinishedMessage(): string {
  return `
    <div x-show="store.isFinished" class="has-text-centered py-6">
      <div class="notification is-success is-light">
        <p class="is-size-5 has-text-weight-bold" x-text="getFinishedTitle()"></p>
        <p class="mt-2 has-text-grey-dark" x-show="hasNoVocabulary()" x-text="getNoVocabularyHint()"></p>
        <p class="mt-3" x-show="hasTomorrowWords()" x-text="getTomorrowMessage()"></p>
        <div class="buttons is-centered mt-5">
          <a href="/texts" class="button is-primary">${escapeHtml(t('review.back_to_texts'))}</a>
        </div>
      </div>
    </div>
  `;
}

/**
 * Build word review area HTML.
 */
function buildWordReviewArea(): string {
  return `
    <div x-show="!store.isFinished && store.currentWord" class="review-word-area">
      <!-- Loading next word -->
      <div x-show="store.isLoading" class="has-text-centered py-4">
        <div class="loading-spinner"></div>
      </div>

      <div x-show="!store.isLoading" class="has-text-centered">
        <!-- Term display -->
        <div class="review-term-display mb-5"
             :style="'font-size: ' + store.langSettings.textSize + '%; direction: ' + (store.langSettings.rtl ? 'rtl' : 'ltr')"
             x-effect="setTermDisplayHtml($el)">
        </div>

        <!-- Solution (hidden until revealed) -->
        <div x-show="store.answerRevealed" class="notification is-info is-light mb-5">
          <p class="is-size-4" x-text="getCurrentWordSolution()"></p>
        </div>

        <!-- Action buttons -->
        <div class="buttons is-centered mb-5">
          <button x-show="!store.answerRevealed"
                  class="button is-primary is-large"
                  @click="revealAnswer">
            ${escapeHtml(t('review.card.show_answer'))}
          </button>
        </div>

        <!-- After answer revealed -->
        <div x-show="store.answerRevealed" class="mb-5">
          <div class="buttons is-centered">
            ${GRADES.map(grade => `
              <button class="button ${grade.style}"
                      @click="gradeAnswer(${grade.value})"
                      title="${escapeHtml(t(grade.labelKey))} (${grade.value})">
                <span>${escapeHtml(t(grade.labelKey))}</span>
                <span class="is-size-7 ml-2 has-text-weight-normal"
                      x-text="intervalHint(${grade.value})"></span>
              </button>
            `).join('')}
            <button class="button" @click="skipWord" title="Escape">
              ${escapeHtml(t('review.card.skip'))}
            </button>
          </div>
        </div>

        <!-- Status buttons -->
        <div x-show="store.answerRevealed" class="mb-5">
          <p class="is-size-7 has-text-grey mb-2">
            ${escapeHtml(t('review.card.set_status_directly'))}
            <span class="is-italic">(⇧1-5)</span>
          </p>
          <div class="buttons is-centered are-small">
            ${[1, 2, 3, 4, 5].map(s => `
              <button class="button status-btn"
                      :class="{ 'status-${s}': getCurrentWordStatus() === ${s} }"
                      @click="setStatus(${s})">${s}</button>
            `).join('')}
            <button class="button" @click="setStatus(98)" title="Press I">
              ${escapeHtml(t('review.card.ignore'))}
            </button>
            <button class="button" @click="setStatus(99)" title="Press W">
              ${escapeHtml(t('review.card.well_known'))}
            </button>
          </div>
        </div>

        <!-- Details button -->
        <div x-show="store.answerRevealed">
          <button class="button is-text is-small" @click="store.openModal()" title="Press E">
            ${escapeHtml(t('review.card.details_edit'))}
          </button>
        </div>
      </div>
    </div>
  `;
}

/**
 * Build table review HTML.
 */
function buildTableReview(): string {
  return `
    <!-- Loading -->
    <div x-show="isLoading" class="has-text-centered py-6">
      <div class="loading-spinner"></div>
      <p class="mt-4 has-text-grey">${escapeHtml(t('review.loading_words'))}</p>
    </div>

    <!-- No words -->
    <div x-show="!isLoading && words.length === 0" class="notification is-info is-light">
      <p>${escapeHtml(t('review.table.no_words'))}</p>
    </div>

    <!-- Table -->
    <div x-show="!isLoading && words.length > 0">
      <!-- Column toggles -->
      <div class="field is-grouped is-grouped-multiline mb-4">
        <div class="control">
          <label class="checkbox"><input type="checkbox" x-model="columns.edit"
            @change="saveColumnSettings"> ${escapeHtml(t('review.table.col_edit'))}</label>
        </div>
        <div class="control">
          <label class="checkbox"><input type="checkbox" x-model="columns.status"
            @change="saveColumnSettings"> ${escapeHtml(t('review.table.col_status'))}</label>
        </div>
        <div class="control">
          <label class="checkbox"><input type="checkbox" x-model="columns.term"
            @change="saveColumnSettings"> ${escapeHtml(t('review.table.col_term'))}</label>
        </div>
        <div class="control">
          <label class="checkbox"><input type="checkbox" x-model="columns.trans"
            @change="saveColumnSettings"> ${escapeHtml(t('review.table.col_translation'))}</label>
        </div>
        <div class="control">
          <label class="checkbox"><input type="checkbox" x-model="columns.rom"
            @change="saveColumnSettings"> ${escapeHtml(t('review.table.col_romanization'))}</label>
        </div>
        <div class="control">
          <label class="checkbox"><input type="checkbox" x-model="columns.sentence"
            @change="saveColumnSettings"> ${escapeHtml(t('review.table.col_sentence'))}</label>
        </div>
        <div class="control ml-4">
          <label class="checkbox"><input type="checkbox" x-model="hideTermContent"
            @change="saveColumnSettings"> ${escapeHtml(t('review.table.hide_terms'))}</label>
        </div>
        <div class="control">
          <label class="checkbox"><input type="checkbox" x-model="hideTransContent"
            @change="saveColumnSettings"> ${escapeHtml(t('review.table.hide_translations'))}</label>
        </div>
      </div>
      <!-- Context annotation toggles (affects sentence mode tests) -->
      <div class="field is-grouped is-grouped-multiline mb-4">
        <div class="control">
          <span class="has-text-grey-dark is-size-7 mr-2">${escapeHtml(t('review.table.context_annotations'))}</span>
        </div>
        <div class="control">
          <label class="checkbox"><input type="checkbox" x-model="contextAnnotations.rom"
            @change="saveContextAnnotationSettings"> ${escapeHtml(t('review.table.col_romanization'))}</label>
        </div>
        <div class="control">
          <label class="checkbox"><input type="checkbox" x-model="contextAnnotations.trans"
            @change="saveContextAnnotationSettings"> ${escapeHtml(t('review.table.col_translation'))}</label>
        </div>
      </div>

      <div class="table-container">
        <table class="table is-striped is-hoverable is-fullwidth">
          <thead>
            <tr>
              <th x-show="columns.edit" class="has-text-centered" style="width: 50px;">
                ${escapeHtml(t('review.table.col_ed_short'))}
              </th>
              <th x-show="columns.status" class="has-text-centered" style="width: 180px;">
                ${escapeHtml(t('review.table.col_status'))}
              </th>
              <th x-show="columns.term" class="has-text-centered">
                ${escapeHtml(t('review.table.col_term'))}
              </th>
              <th x-show="columns.trans" class="has-text-centered">
                ${escapeHtml(t('review.table.col_translation'))}
              </th>
              <th x-show="columns.rom" class="has-text-centered">
                ${escapeHtml(t('review.table.col_rom_short'))}
              </th>
              <th x-show="columns.sentence">${escapeHtml(t('review.table.col_sentence'))}</th>
            </tr>
          </thead>
          <tbody>
            <template x-for="word in words" :key="word.id">
              <tr>
                <td x-show="columns.edit" class="has-text-centered">
                  <button type="button" class="button is-small is-text"
                          @click="editWord(word.id)">
                    ${escapeHtml(t('review.table.col_edit'))}
                  </button>
                </td>
                <td x-show="columns.status" class="has-text-centered">
                  <div class="buttons are-small is-centered">
                    <template x-for="s in [1, 2, 3, 4, 5]" :key="s">
                      <button class="button status-btn"
                              :class="{ ['status-' + s]: word.status === s }"
                              @click="setWordStatus(word.id, s)"
                              x-text="s"></button>
                    </template>
                  </div>
                </td>
                <td x-show="columns.term"
                    class="has-text-centered"
                    :class="{ 'cell-hidden': hideTermContent && !revealedTerms[word.id] }"
                    @click="revealTerm(word.id)">
                  <span x-text="word.text"></span>
                </td>
                <td x-show="columns.trans"
                    class="has-text-centered"
                    :class="{ 'cell-hidden': hideTransContent && !revealedTrans[word.id] }"
                    @click="revealTrans(word.id)">
                  <span x-text="word.translation"></span>
                </td>
                <td x-show="columns.rom" class="has-text-centered" x-text="word.romanization"></td>
                <td x-show="columns.sentence" x-effect="setSentenceHtml($el, word)"></td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>
    </div>
  `;
}

/**
 * Build word modal HTML.
 */
function buildWordModal(): string {
  return `
    <div class="modal" :class="{ 'is-active': store.isModalOpen }">
      <div class="modal-background" @click="store.closeModal()"></div>
      <div class="modal-card" style="max-width: 500px;">
        <header class="modal-card-head py-3">
          <p class="modal-card-title is-size-5">${escapeHtml(t('review.modal.word_details'))}</p>
          <button class="delete" aria-label="close" @click="store.closeModal()"></button>
        </header>
        <section class="modal-card-body">
          <template x-if="store.currentWord">
            <div>
              <div class="is-flex is-justify-content-space-between is-align-items-center mb-4">
                <span class="is-size-3 has-text-weight-bold"
                      :style="store.langSettings.rtl ? 'direction: rtl' : ''"
                      x-text="store.currentWord.text"></span>
                <button class="button is-small" @click="speakWord">
                  ${escapeHtml(t('review.modal.listen'))}
                </button>
              </div>

              <div class="mb-4" x-show="store.currentWord.solution">
                <p class="has-text-grey-dark is-size-5" x-text="store.currentWord.solution"></p>
              </div>

              <template x-if="store.hasDictUrl('dict1') || store.hasDictUrl('dict2') || store.hasDictUrl('translator')">
              <div class="mb-4">
                <p class="is-size-7 has-text-grey mb-2">${escapeHtml(t('review.modal.lookup_in_dictionary'))}</p>
                <div class="buttons">
                  <a x-show="store.hasDictUrl('dict1')" :href="store.getDictUrl('dict1')"
                     target="_blank" class="button is-outlined" rel="noopener">
                    ${escapeHtml(t('review.modal.dictionary_1'))}
                  </a>
                  <a x-show="store.hasDictUrl('dict2')" :href="store.getDictUrl('dict2')"
                     target="_blank" class="button is-outlined" rel="noopener">
                    ${escapeHtml(t('review.modal.dictionary_2'))}
                  </a>
                  <a x-show="store.hasDictUrl('translator')" :href="store.getDictUrl('translator')"
                     target="_blank" class="button is-outlined" rel="noopener">
                    ${escapeHtml(t('review.modal.translate'))}
                  </a>
                </div>
              </div>
              </template>
            </div>
          </template>
        </section>
        <footer class="modal-card-foot">
          <button type="button" class="button is-info" @click="store.editCurrentWord()">
            ${escapeHtml(t('review.modal.edit_term'))}
          </button>
          <button class="button" @click="store.closeModal()">${escapeHtml(t('review.modal.close'))}</button>
        </footer>
      </div>
    </div>
  `;
}

/**
 * Build CSS styles.
 */
function buildStyles(): string {
  return `
    <style>
      .review-page {
        min-height: 100vh;
        display: flex;
        flex-direction: column;
      }

      .review-progress-section {
        border-radius: 0;
      }

      .review-content, .table-review-content {
        max-width: 900px;
        margin: 0 auto;
        flex: 1;
        width: 100%;
      }

      .review-word-area {
        min-height: 400px;
        display: flex;
        flex-direction: column;
        justify-content: center;
      }

      .review-term-display {
        min-height: 100px;
        line-height: 1.6;
      }

      .review-progress {
        display: flex;
        height: 8px;
        width: 100%;
        border-radius: 4px;
        overflow: hidden;
        background: #e0e0e0;
      }

      .review-progress-remaining { background-color: #808080; transition: width 0.3s ease; }
      .review-progress-wrong { background-color: #ff6347; transition: width 0.3s ease; }
      .review-progress-correct { background-color: #32cd32; transition: width 0.3s ease; }

      .status-btn.status-1 { background-color: #ff6347 !important; color: white !important; border-color: #ff6347 !important; }
      .status-btn.status-2 { background-color: #ffa500 !important; color: white !important; border-color: #ffa500 !important; }
      .status-btn.status-3 { background-color: #ffff00 !important; color: black !important; border-color: #ffff00 !important; }
      .status-btn.status-4 { background-color: #90ee90 !important; color: black !important; border-color: #90ee90 !important; }
      .status-btn.status-5 { background-color: #32cd32 !important; color: white !important; border-color: #32cd32 !important; }

      .word-test { font-weight: bold; text-decoration: underline; }
      .word-test-hidden { background-color: #e0e0e0; padding: 0 0.5em; border-radius: 3px; }

      /* Context annotation styles */
      .annotated-sentence { line-height: 2.5; }
      .annotated-sentence ruby { ruby-position: over; }
      .annotated-sentence ruby rt {
        font-size: 0.65em;
        color: #666;
        font-weight: normal;
      }
      .annotated-sentence ruby .context-trans {
        color: #888;
        font-style: italic;
      }
      .context-word {
        display: inline-block;
        text-align: center;
      }

      .cell-hidden {
        color: transparent !important;
        background-color: #f0f0f0 !important;
        cursor: pointer;
        user-select: none;
      }
      .cell-hidden:hover { background-color: #e0e0e0 !important; }
      .cell-hidden * { color: transparent !important; }

      .loading-spinner {
        width: 40px;
        height: 40px;
        margin: 0 auto;
        border: 3px solid #dbdbdb;
        border-top-color: #3273dc;
        border-radius: 50%;
        animation: spin 0.8s linear infinite;
      }

      @keyframes spin { to { transform: rotate(360deg); } }

      [x-cloak] { display: none !important; }

      @media screen and (max-width: 768px) {
        .review-progress-section .level { flex-wrap: wrap; }
        .review-progress-section .level-item:not(:last-child) { margin-bottom: 0.5rem; }
        .navbar-item .buttons { flex-wrap: wrap; justify-content: center; }
      }
    </style>
  `;
}

/**
 * Escape HTML entities.
 */
function escapeHtml(str: string): string {
  const div = document.createElement('div');
  div.textContent = str;
  return div.innerHTML;
}

/**
 * Initialize the review application.
 */
export function initReviewApp(): void {
  const container = document.getElementById('review-app');
  const configEl = document.getElementById('review-config');

  if (!container || !configEl) return;

  try {
    const config: ReviewConfig = JSON.parse(configEl.textContent || '{}');

    if (config.error) {
      container.innerHTML = `<div class="notification is-danger m-4">${escapeHtml(config.error)}</div>`;
      return;
    }

    // Re-initialize store with correct initial values to prevent visual glitches
    // (CSP build prohibits direct property assignment, so we recreate the store)
    initReviewStore({
      reviewType: config.reviewType,
      isTableMode: config.isTableMode
    });

    // Register the Alpine components
    registerReviewAppComponent(config);
    registerTableReviewComponent();

    // Render the app
    renderReviewApp(container);

  } catch (err) {
    console.error('Error initializing review app:', err);
    container.innerHTML =
      `<div class="notification is-danger m-4">${escapeHtml(t('review.init_failed'))}</div>`;
  }
}

/**
 * Register the main review app Alpine component.
 */
function registerReviewAppComponent(config: ReviewConfig): void {
  Alpine.data('reviewApp', () => ({
    navbarOpen: false,

    get store(): ReviewStoreState {
      return getReviewStore();
    },

    get progressPercent() {
      const total = this.store.progress.total || 1;
      return {
        remaining: (this.store.progress.remaining / total) * 100,
        wrong: (this.store.progress.wrong / total) * 100,
        correct: (this.store.progress.correct / total) * 100
      };
    },

    async init() {
      this.store.configure(config);

      // Set up keyboard handler
      document.addEventListener('keydown', (e) => this.handleKeydown(e));

      // Start fetching first word if not table mode
      if (!config.isTableMode) {
        await this.store.nextWord();
      }
    },

    revealAnswer() {
      this.store.revealAnswer();
      if (this.store.readAloudEnabled && this.store.currentWord) {
        this.speakWord();
      }
    },

    async incrementStatus() {
      await this.store.incrementStatus();
    },

    async decrementStatus() {
      await this.store.decrementStatus();
    },

    async setStatus(status: number) {
      await this.store.updateStatus(status);
    },

    async gradeAnswer(grade: number) {
      if (grade < 1 || grade > 4) return;
      await this.store.gradeAnswer(grade as 1 | 2 | 3 | 4);
    },

    /**
     * The "in 3d" hint under a grade button.
     *
     * Blank until the intervals arrive, so the buttons never jump about while
     * the request is in flight.
     */
    intervalHint(grade: number): string {
      const days = this.store.intervals[String(grade)];
      if (days === undefined) return '';
      if (days < 1) return t('review.card.interval_today');
      if (days < 30) return t('review.card.interval_days', { count: String(days) });
      if (days < 365) {
        return t('review.card.interval_months', { count: String(Math.round(days / 30)) });
      }
      return t('review.card.interval_years', { count: String(Math.round(days / 365)) });
    },

    async skipWord() {
      await this.store.skipWord();
    },

    switchReviewType(type: number) {
      const url = new URL(window.location.href);
      url.searchParams.set('type', String(type));
      window.location.href = url.toString();
    },

    switchToTable() {
      const url = new URL(window.location.href);
      if (this.store.isTableMode) {
        url.searchParams.delete('type');
      } else {
        url.searchParams.set('type', 'table');
      }
      window.location.href = url.toString();
    },

    speakWord() {
      if (this.store.currentWord && this.store.langSettings.langCode) {
        speechDispatcher(this.store.currentWord.text, this.store.langId);
      }
    },

    toggleReadAloud(event: Event) {
      const target = event.target as HTMLInputElement;
      this.store.setReadAloud(target.checked);
    },

    handleKeydown(e: KeyboardEvent) {
      if (this.store.isModalOpen) return;
      if (e.target instanceof HTMLInputElement || e.target instanceof HTMLTextAreaElement) return;
      if (this.store.isTableMode || this.store.isFinished) return;

      if (this.handleDigitKey(e)) return;

      switch (e.key) {
        case ' ':
          e.preventDefault();
          if (!this.store.answerRevealed) this.revealAnswer();
          break;
        case 'Escape':
          e.preventDefault();
          if (this.store.currentWord) this.skipWord();
          break;
        case 'ArrowUp':
          e.preventDefault();
          if (this.store.answerRevealed) this.gradeAnswer(3);
          break;
        case 'ArrowDown':
          e.preventDefault();
          if (this.store.answerRevealed) this.gradeAnswer(1);
          break;
        case 'i': case 'I':
          e.preventDefault();
          if (this.store.currentWord) this.setStatus(98);
          break;
        case 'w': case 'W':
          e.preventDefault();
          if (this.store.currentWord) this.setStatus(99);
          break;
        case 'e': case 'E':
          e.preventDefault();
          if (this.store.currentWord) this.store.openModal();
          break;
      }
    },

    /**
     * Digit-row shortcuts: 1-4 grade the answer, Shift+1-5 set a status.
     *
     * Read off `e.code` rather than `e.key` so the physical digit row works on
     * layouts where a digit is itself shifted (AZERTY) or where Shift+1 is not
     * "!" — `e.key` would report a different character on each.
     *
     * @returns Whether the key was handled
     */
    handleDigitKey(e: KeyboardEvent): boolean {
      if (!e.code.startsWith('Digit') || !this.store.answerRevealed) return false;

      const digit = parseInt(e.code.slice(5), 10);
      if (digit < 1 || digit > 5) return false;

      e.preventDefault();
      if (e.shiftKey) {
        this.setStatus(digit);
      } else if (digit <= 4) {
        this.gradeAnswer(digit);
      }
      return true;
    },

    // Finished state helpers (CSP-compatible)
    getFinishedTitle(): string {
      if (this.store.progress.total > 0) {
        return t('review.finished.nothing_more');
      }
      return t('review.no_vocabulary_title');
    },

    hasNoVocabulary(): boolean {
      return this.store.progress.total === 0;
    },

    getNoVocabularyHint(): string {
      return t('review.no_vocabulary_hint');
    },

    hasTomorrowWords(): boolean {
      return this.store.tomorrowCount > 0;
    },

    getTomorrowLabel(): string {
      return this.store.tomorrowCount === 1
        ? t('review.finished.tomorrow_one')
        : t('review.finished.tomorrow_many');
    },

    getTomorrowMessage(): string {
      const count = this.store.tomorrowCount;
      return t(
        count === 1
          ? 'review.finished.tomorrow_count_one'
          : 'review.finished.tomorrow_count_other',
        { count }
      );
    },

    // Safe accessors (CSP-compatible - avoid ?. in templates)
    getCurrentWordGroup(): string {
      return this.store.currentWord ? this.store.currentWord.group : '';
    },

    getCurrentWordSolution(): string {
      return this.store.currentWord ? this.store.currentWord.solution : '';
    },

    getCurrentWordStatus(): number {
      return this.store.currentWord ? this.store.currentWord.status : 0;
    },

    // CSP-compatible innerHTML setter (use with x-effect)
    setTermDisplayHtml(el: HTMLElement) {
      el.innerHTML = this.getCurrentWordGroup();
    }
  }));
}

/**
 * Register the table review Alpine component.
 */
function registerTableReviewComponent(): void {
  Alpine.data('tableReview', () => ({
    words: [] as TableReviewWord[],
    langSettings: null as LangSettings | null,
    columns: { edit: true, status: true, term: true, trans: true, rom: false, sentence: true },
    hideTermContent: false,
    hideTransContent: false,
    contextAnnotations: { rom: false, trans: false },
    revealedTerms: {} as Record<number, boolean>,
    revealedTrans: {} as Record<number, boolean>,
    isLoading: false,

    async init() {
      this.loadColumnSettings();
      this.loadContextAnnotationSettings();
      document.addEventListener('lwt-term-saved', () => {
        void this.loadWords();
      });
      await this.loadWords();
    },

    async loadWords() {
      this.isLoading = true;
      const store = getReviewStore();

      try {
        const response = await ReviewApi.getTableWords(store.reviewKey, store.selection);
        if (response.data) {
          this.words = response.data.words;
          this.langSettings = response.data.langSettings;
        }
      } catch (err) {
        console.error('Error loading words:', err);
      }

      this.isLoading = false;
    },

    async setWordStatus(wordId: number, status: number) {
      try {
        const response = await ReviewApi.updateStatus(wordId, status);
        if (response.data?.status !== undefined) {
          const word = this.words.find((w: TableReviewWord) => w.id === wordId);
          if (word) word.status = response.data.status;
        }
      } catch (err) {
        console.error('Error updating status:', err);
      }
    },

    /**
     * Open the term editor for a row.
     *
     * A saved term fires lwt-term-saved, which the component listens for to
     * pull the row's fresh values back in.
     *
     * @param wordId Term ID
     */
    editWord(wordId: number) {
      void openTermEditModal(0, 0, wordId);
    },

    revealTerm(wordId: number) {
      if (this.hideTermContent) this.revealedTerms[wordId] = true;
    },

    revealTrans(wordId: number) {
      if (this.hideTransContent) this.revealedTrans[wordId] = true;
    },

    saveColumnSettings() {
      localStorage.setItem('lwt-table-review-columns', JSON.stringify({
        columns: this.columns,
        hideTermContent: this.hideTermContent,
        hideTransContent: this.hideTransContent
      }));
    },

    loadColumnSettings() {
      const saved = localStorage.getItem('lwt-table-review-columns');
      if (saved) {
        try {
          const s = JSON.parse(saved);
          if (s.columns) this.columns = { ...this.columns, ...s.columns };
          if (typeof s.hideTermContent === 'boolean') this.hideTermContent = s.hideTermContent;
          if (typeof s.hideTransContent === 'boolean') this.hideTransContent = s.hideTransContent;
        } catch { /* ignore */ }
      }
    },

    saveContextAnnotationSettings() {
      // Save to server via AJAX
      saveSetting('currenttabletestsetting7', this.contextAnnotations.rom ? '1' : '0');
      saveSetting('currenttabletestsetting8', this.contextAnnotations.trans ? '1' : '0');
      // Also save to localStorage for quick reload
      localStorage.setItem('lwt-context-annotations', JSON.stringify(this.contextAnnotations));
    },

    loadContextAnnotationSettings() {
      const saved = localStorage.getItem('lwt-context-annotations');
      if (saved) {
        try {
          const s = JSON.parse(saved);
          if (typeof s.rom === 'boolean') this.contextAnnotations.rom = s.rom;
          if (typeof s.trans === 'boolean') this.contextAnnotations.trans = s.trans;
        } catch { /* ignore */ }
      }
    },

    // CSP-compatible innerHTML setter (use with x-effect)
    setSentenceHtml(el: HTMLElement, word: TableReviewWord) {
      el.innerHTML = word.sentenceHtml;
    }
  }));
}

// Auto-initialize after Alpine has initialized the DOM
// We use onDomReady + check because initReviewApp() needs to inject HTML and then call Alpine.initTree()
onDomReady(() => {
  // Only init if Alpine is available and we're on the review page
  const container = document.getElementById('review-app');
  const configEl = document.getElementById('review-config');
  if (!container || !configEl) return;

  // Alpine is only put on window after main.ts has awaited bootI18n(), so
  // whether it is there yet when the DOM becomes ready is a race — and one the
  // review page lost as soon as this chunk grew enough to shift the timing.
  // Testing the flag once left the page on its spinner for good. Wait for
  // Alpine to announce itself instead, and take the flag as the shortcut for
  // when it has already started.
  if (window.Alpine) {
    initReviewApp();
    return;
  }

  document.addEventListener('alpine:initialized', () => initReviewApp(), { once: true });
});
