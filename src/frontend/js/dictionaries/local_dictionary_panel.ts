/**
 * Local Dictionary Inline Panel Component
 *
 * Displays local dictionary lookup results in a collapsible panel
 * below the clicked word in the text reading interface.
 *
 * @license Unlicense <http://unlicense.org/>
 * @since   3.0.0
 */

import Alpine from 'alpinejs';
import {
  lookupLocal,
  formatResults,
  hasLocalDictionaries,
  shouldUseOnline,
  type LocalDictResult
} from './local_dictionary_api';

/**
 * Panel state interface for Alpine.js component.
 */
interface LocalDictPanelState {
  visible: boolean;
  loading: boolean;
  term: string;
  results: LocalDictResult[];
  error: string | null;
  panelElement: HTMLElement | null;

  // Methods
  show(langId: number, term: string): Promise<void>;
  hide(): void;
  toggle(): void;
}

/**
 * Current panel instance reference.
 */
let currentPanel: HTMLElement | null = null;

/**
 * Create a local dictionary panel element.
 *
 * @returns HTMLElement for the panel
 */
export function createPanelElement(): HTMLElement {
  const panel = document.createElement('div');
  panel.className = 'local-dict-panel';
  panel.setAttribute('x-data', 'localDictPanel');
  panel.setAttribute('x-show', 'visible');
  panel.setAttribute('x-transition:enter', 'transition ease-out duration-200');
  panel.setAttribute('x-transition:enter-start', 'opacity-0 transform translate-y-[-10px]');
  panel.setAttribute('x-transition:enter-end', 'opacity-100 transform translate-y-0');
  panel.setAttribute('x-transition:leave', 'transition ease-in duration-150');
  panel.setAttribute('x-transition:leave-start', 'opacity-100');
  panel.setAttribute('x-transition:leave-end', 'opacity-0');

  panel.innerHTML = `
    <div class="local-dict-panel-header">
      <span class="local-dict-panel-title">
        Local Dictionary
        <span x-show="loading" class="local-dict-loading">...</span>
      </span>
      <button class="local-dict-panel-close" @click="hide()" title="Close">
        <span>&times;</span>
      </button>
    </div>
    <div class="local-dict-panel-content">
      <template x-if="error">
        <div class="local-dict-error" x-text="error"></div>
      </template>
      <template x-if="!error && results.length === 0 && !loading">
        <div class="local-dict-empty">No results found for "<span x-text="term"></span>"</div>
      </template>
      <template x-if="!error && results.length > 0">
        <div class="local-dict-results">
          <template x-for="result in results" :key="result.term + result.dictionary">
            <div class="local-dict-entry">
              <div class="local-dict-term">
                <span dir="auto" class="local-dict-headword" x-text="result.term"></span>
                <span x-show="result.reading" class="local-dict-reading"
                      x-text="'[' + (result.reading || '') + ']'"></span>
                <span x-show="result.pos" class="local-dict-pos"
                      x-text="'(' + (result.pos || '') + ')'"></span>
              </div>
              <div class="local-dict-definition" x-text="result.definition"></div>
              <div class="local-dict-source" x-text="'— ' + result.dictionary"></div>
            </div>
          </template>
        </div>
      </template>
    </div>
  `;

  return panel;
}

/**
 * Register the Alpine.js component for local dictionary panel.
 */
export function registerPanelComponent(): void {
  Alpine.data('localDictPanel', () => ({
    visible: false,
    loading: false,
    term: '',
    results: [] as LocalDictResult[],
    error: null as string | null,
    panelElement: null as HTMLElement | null,

    async show(langId: number, term: string) {
      this.term = term;
      this.loading = true;
      this.error = null;
      this.results = [];
      this.visible = true;

      try {
        const response = await lookupLocal(langId, term);
        if (response.error) {
          this.error = response.error;
        } else if (response.data) {
          this.results = response.data.results;
        }
      } catch (err) {
        this.error = 'Failed to look up term';
        console.error('Local dictionary lookup error:', err);
      } finally {
        this.loading = false;
      }
    },

    hide() {
      this.visible = false;
    },

    toggle() {
      this.visible = !this.visible;
    }
  }));
}

/**
 * Show a local dictionary panel for a term.
 *
 * @param langId Language ID
 * @param term   Term to look up
 * @param targetElement Element to position panel near
 * @returns Promise that resolves when panel is shown
 */
export async function showLocalDictPanel(
  langId: number,
  term: string,
  targetElement: HTMLElement
): Promise<{ results: LocalDictResult[]; showOnline: boolean }> {
  // Remove any existing panel
  hideLocalDictPanel();

  // Check if local dictionaries are enabled
  const hasLocal = await hasLocalDictionaries(langId);
  if (!hasLocal) {
    return { results: [], showOnline: true };
  }

  // Create and insert panel
  const panel = createPanelElement();
  panel.style.position = 'absolute';

  // Position panel below target element
  const rect = targetElement.getBoundingClientRect();
  const scrollTop = window.scrollY || document.documentElement.scrollTop;
  const scrollLeft = window.scrollX || document.documentElement.scrollLeft;

  panel.style.top = `${rect.bottom + scrollTop + 5}px`;
  panel.style.left = `${rect.left + scrollLeft}px`;
  panel.style.zIndex = '1000';

  document.body.appendChild(panel);
  currentPanel = panel;

  // Initialize Alpine on the panel
  Alpine.initTree(panel);

  // Get the Alpine component instance and trigger lookup
  const panelData = Alpine.$data(panel) as LocalDictPanelState;
  await panelData.show(langId, term);

  // Determine if online dictionaries should also be shown
  const showOnline = await shouldUseOnline(langId, panelData.results.length > 0);

  return {
    results: panelData.results,
    showOnline
  };
}

/**
 * Hide and remove any visible local dictionary panel.
 */
export function hideLocalDictPanel(): void {
  if (currentPanel) {
    currentPanel.remove();
    currentPanel = null;
  }
}

/**
 * Check if a local dictionary panel is currently visible.
 */
export function isPanelVisible(): boolean {
  return currentPanel !== null;
}

/**
 * Simple inline display of local dictionary results (non-Alpine version).
 * Useful for simpler integration without full component.
 *
 * @param langId Language ID
 * @param term   Term to look up
 * @param targetElement Element to append results to
 */
export async function showInlineResults(
  langId: number,
  term: string,
  targetElement: HTMLElement
): Promise<{ results: LocalDictResult[]; showOnline: boolean }> {
  const hasLocal = await hasLocalDictionaries(langId);
  if (!hasLocal) {
    return { results: [], showOnline: true };
  }

  // Show loading indicator
  const loadingDiv = document.createElement('div');
  loadingDiv.className = 'local-dict-loading-inline';
  loadingDiv.textContent = 'Looking up...';
  targetElement.appendChild(loadingDiv);

  try {
    const response = await lookupLocal(langId, term);

    // Remove loading indicator
    loadingDiv.remove();

    if (response.error) {
      const errorDiv = document.createElement('div');
      errorDiv.className = 'local-dict-error';
      errorDiv.textContent = response.error;
      targetElement.appendChild(errorDiv);
      return { results: [], showOnline: true };
    }

    const results = response.data?.results || [];

    if (results.length > 0) {
      const resultsDiv = document.createElement('div');
      resultsDiv.className = 'local-dict-results-inline';
      resultsDiv.innerHTML = formatResults(results);
      targetElement.appendChild(resultsDiv);
    }

    const showOnline = await shouldUseOnline(langId, results.length > 0);
    return { results, showOnline };

  } catch {
    loadingDiv.remove();
    const errorDiv = document.createElement('div');
    errorDiv.className = 'local-dict-error';
    errorDiv.textContent = 'Failed to look up term';
    targetElement.appendChild(errorDiv);
    return { results: [], showOnline: true };
  }
}

// Register component when this module is imported
registerPanelComponent();

// Export types
export type { LocalDictPanelState };
