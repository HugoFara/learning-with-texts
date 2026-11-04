/**
 * Text Print App - Alpine.js component for text printing.
 *
 * This component manages:
 * - Plain print mode with annotation filters (status, type, placement)
 * - Annotated text display mode
 * - Annotated text edit mode
 * - Client-side filtering without page reloads
 * - Settings persistence
 *
 * @license Unlicense <http://unlicense.org/>
 * @since   3.0.0
 */

import { confirmAction, confirmDeletion } from '@shared/components/confirm_dialog';
import Alpine from 'alpinejs';
import { initIcons } from '@shared/icons/lucide_icons';
import { apiPost, getCsrfToken } from '@shared/api/client';
import {
  TextsApi,
  type PrintItem,
  type PrintConfig,
  type AnnotationItem,
  type AnnotationConfig
} from '@modules/text/api/texts_api';

/**
 * Print mode types.
 */
type PrintMode = 'plain' | 'annotated' | 'edit';

/**
 * Annotation flags (bitmask).
 */
const ANN_SHOW_TRANS = 1;
const ANN_SHOW_ROM = 2;
const ANN_SHOW_TAGS = 4;

/**
 * Annotation placement options.
 */
const ANN_PLACEMENT_INFRONT = 1;
const ANN_PLACEMENT_RUBY = 2;

/**
 * Page configuration from PHP.
 */
interface PageConfig {
  textId: number;
  mode: PrintMode;
  savedAnn?: number;
  savedStatus?: number;
  savedPlacement?: number;
}

/**
 * Alpine.js component data interface.
 */
export interface TextPrintAppData {
  // State
  loading: boolean;
  mode: PrintMode;
  textId: number;

  // Plain print state
  items: PrintItem[];
  config: PrintConfig | null;

  // Annotated state
  annItems: AnnotationItem[] | null;
  annConfig: AnnotationConfig | null;

  // Filter state (for plain print)
  statusFilter: number;
  annotationFlags: number;
  placementMode: number;

  // Computed
  showRom: boolean;
  showTrans: boolean;
  showTags: boolean;

  // Lifecycle
  init(): Promise<void>;

  // Data loading
  loadPrintItems(): Promise<void>;
  loadAnnotation(): Promise<void>;

  // Filter handlers
  handleStatusChange(event: Event): void;
  handleAnnotationChange(event: Event): void;
  handlePlacementChange(event: Event): void;
  saveSettings(): Promise<void>;

  // Status range checking
  checkStatusInRange(status: number | null): boolean;

  // Rendering
  formatItem(item: PrintItem): string;
  formatAnnotationItem(item: AnnotationItem): string;

  // Actions
  handlePrint(): void;
  navigateTo(url: string): void;
  confirmNavigateTo(url: string, message: string): Promise<void>;
  confirmDeleteAnnotation(textId: number): Promise<void>;
  openWindow(url: string): void;

  // CSP-compatible innerHTML setters (use with x-effect)
  setItemHtml(el: HTMLElement, item: PrintItem): void;
  setAnnotationItemHtml(el: HTMLElement, item: AnnotationItem): void;

  // Safe accessors (CSP-compatible - avoid ?. in templates)
  getConfigTitle(fallback: string): string;
  getConfigTextSize(fallback: number): number;
  getAnnConfigTitle(fallback: string): string;
  getAnnConfigTextSize(fallback: number): number;

  // Internal formatting helpers
  formatTermBehind(
    term: string,
    rom: string,
    trans: string,
    showRom: boolean,
    showTrans: boolean
  ): string;
  formatTermInFront(
    term: string,
    rom: string,
    trans: string,
    showRom: boolean,
    showTrans: boolean
  ): string;
  formatTermRuby(
    term: string,
    rom: string,
    trans: string,
    showRom: boolean,
    showTrans: boolean
  ): string;
}

/**
 * Read page configuration from the embedded JSON script tag.
 */
function getPageConfig(): PageConfig {
  const configEl = document.getElementById('print-config');
  if (configEl) {
    try {
      return JSON.parse(configEl.textContent || '{}');
    } catch {
      // Invalid JSON
    }
  }
  // Fallback: try to get text ID from URL
  const pathname = window.location.pathname;

  // RESTful URL pattern: /text/{id}/print, /text/{id}/print/edit, or /text/{id}/print-plain
  const printMatch = pathname.match(/\/text\/(\d+)\/print(?:-plain|\/edit)?$/);
  if (printMatch) {
    const textId = parseInt(printMatch[1], 10);
    const isEdit = pathname.endsWith('/print/edit');
    const isPlain = pathname.endsWith('/print-plain');
    return {
      textId,
      mode: isEdit ? 'edit' : isPlain ? 'plain' : 'annotated'
    };
  }

  // Legacy plain print URL pattern: /text/print-plain?text={id}
  const params = new URLSearchParams(window.location.search);
  const textId = parseInt(params.get('text') || '0', 10);

  return {
    textId,
    mode: 'plain'
  };
}

/**
 * Escape HTML entities.
 */
function escapeHtml(text: string): string {
  const div = document.createElement('div');
  div.textContent = text;
  return div.innerHTML;
}

/**
 * Check if a word status is within the given range.
 *
 * Status range is a bitmask:
 * - Bit 0 (1): Status 1
 * - Bit 1 (2): Status 2
 * - Bit 2 (4): Status 3
 * - Bit 3 (8): Status 4
 * - Bit 4 (16): Status 5
 * - Bit 5 (32): Status 98 (ignored)
 * - Bit 6 (64): Status 99 (well-known)
 */
function checkStatusRange(status: number | null, range: number): boolean {
  if (status === null) return false;

  switch (status) {
    case 1:
      return (range & 1) !== 0;
    case 2:
      return (range & 2) !== 0;
    case 3:
      return (range & 4) !== 0;
    case 4:
      return (range & 8) !== 0;
    case 5:
      return (range & 16) !== 0;
    case 98:
      return (range & 32) !== 0;
    case 99:
      return (range & 64) !== 0;
    default:
      return false;
  }
}

/**
 * Create the text print app Alpine.js component.
 */
export function textPrintAppData(): TextPrintAppData {
  const pageConfig = getPageConfig();

  return {
    loading: true,
    mode: pageConfig.mode,
    textId: pageConfig.textId,

    // Plain print state
    items: [],
    config: null,

    // Annotated state
    annItems: null,
    annConfig: null,

    // Filter state (defaults)
    statusFilter: pageConfig.savedStatus ?? 14, // Status 1-4
    annotationFlags: pageConfig.savedAnn ?? 3, // Translation + Romanization
    placementMode: pageConfig.savedPlacement ?? 0, // Behind

    // Computed getters
    get showRom(): boolean {
      return (this.annotationFlags & ANN_SHOW_ROM) !== 0;
    },
    get showTrans(): boolean {
      return (this.annotationFlags & ANN_SHOW_TRANS) !== 0;
    },
    get showTags(): boolean {
      return (this.annotationFlags & ANN_SHOW_TAGS) !== 0;
    },

    async init() {
      if (this.textId === 0) {
        this.loading = false;
        return;
      }

      if (this.mode === 'plain') {
        await this.loadPrintItems();
      } else {
        await this.loadAnnotation();
      }

      this.loading = false;

      // Refresh icons after render
      setTimeout(() => {
        initIcons();
      }, 0);
    },

    async loadPrintItems() {
      const response = await TextsApi.getPrintItems(this.textId);
      if (response.data) {
        this.items = response.data.items;
        this.config = response.data.config;

        // Apply saved settings from config
        this.statusFilter = response.data.config.savedStatus;
        this.annotationFlags = response.data.config.savedAnn;
        this.placementMode = response.data.config.savedPlacement;
      }
    },

    async loadAnnotation() {
      const response = await TextsApi.getAnnotation(this.textId);
      if (response.data) {
        this.annItems = response.data.items;
        this.annConfig = response.data.config;
      }
    },

    handleStatusChange(event: Event) {
      const select = event.target as HTMLSelectElement;
      this.statusFilter = parseInt(select.value, 10);
      this.saveSettings();
    },

    handleAnnotationChange(event: Event) {
      const select = event.target as HTMLSelectElement;
      this.annotationFlags = parseInt(select.value, 10);
      this.saveSettings();
    },

    handlePlacementChange(event: Event) {
      const select = event.target as HTMLSelectElement;
      this.placementMode = parseInt(select.value, 10);
      this.saveSettings();
    },

    async saveSettings() {
      // Save settings via API
      await apiPost('/settings', {
        key: 'currentprintannotation',
        value: String(this.annotationFlags)
      });
      await apiPost('/settings', {
        key: 'currentprintstatus',
        value: String(this.statusFilter)
      });
      await apiPost('/settings', {
        key: 'currentprintannotationplacement',
        value: String(this.placementMode)
      });
    },

    checkStatusInRange(status: number | null): boolean {
      return checkStatusRange(status, this.statusFilter);
    },

    formatItem(item: PrintItem): string {
      // Handle paragraph markers
      if (item.isParagraph) {
        const textSize = this.config?.textSize ?? 100;
        return `</p><p style="font-size:${textSize}%;line-height: 1.3; margin-bottom: 10px;">`;
      }

      // Non-word items (punctuation) - just return escaped text
      if (!item.isWord) {
        return escapeHtml(item.text);
      }

      // Word items - check if annotation should be shown
      const showAnnotation =
        item.wordId !== null && this.checkStatusInRange(item.status);

      if (!showAnnotation) {
        return escapeHtml(item.text);
      }

      // Get annotation values
      let translation = item.translation;
      const romanization = item.romanization;
      const tags = item.tags;

      // Add tags to translation if showing tags
      if (this.showTags) {
        if (translation === '' && tags !== '') {
          translation = '* ' + tags;
        } else if (tags !== '') {
          translation = (translation + ' ' + tags).trim();
        }
      }

      // Check if we have anything to show
      const hasRom = this.showRom && romanization !== '';
      const hasTrans = this.showTrans && translation !== '';

      if (!hasRom && !hasTrans) {
        return escapeHtml(item.text);
      }

      // Format based on placement mode
      switch (this.placementMode) {
        case ANN_PLACEMENT_INFRONT:
          return this.formatTermInFront(
            item.text,
            romanization,
            translation,
            hasRom,
            hasTrans
          );

        case ANN_PLACEMENT_RUBY:
          return this.formatTermRuby(
            item.text,
            romanization,
            translation,
            hasRom,
            hasTrans
          );

        default:
          return this.formatTermBehind(
            item.text,
            romanization,
            translation,
            hasRom,
            hasTrans
          );
      }
    },

    formatTermBehind(
      term: string,
      rom: string,
      trans: string,
      showRom: boolean,
      showTrans: boolean
    ): string {
      let output = ' <span class="annterm">';
      output += escapeHtml(term);
      output += '</span> ';

      if (showRom && !showTrans) {
        output += `<span class="annrom">${escapeHtml(rom)}</span>`;
      }
      if (showRom && showTrans) {
        output += `<span class="annrom" dir="ltr">[${escapeHtml(rom)}]</span> `;
      }
      if (showTrans) {
        output += `<span class="anntrans">${escapeHtml(trans)}</span>`;
      }
      output += ' ';

      return output;
    },

    formatTermInFront(
      term: string,
      rom: string,
      trans: string,
      showRom: boolean,
      showTrans: boolean
    ): string {
      let output = ' ';

      if (showTrans) {
        output += `<span class="anntrans">${escapeHtml(trans)}</span> `;
      }
      if (showRom && !showTrans) {
        output += `<span class="annrom">${escapeHtml(rom)}</span> `;
      }
      if (showRom && showTrans) {
        output += `<span class="annrom" dir="ltr">[${escapeHtml(rom)}]</span> `;
      }

      output += ' <span class="annterm">';
      output += escapeHtml(term);
      output += '</span> ';

      return output;
    },

    formatTermRuby(
      term: string,
      rom: string,
      trans: string,
      showRom: boolean,
      showTrans: boolean
    ): string {
      let output = ' <ruby><rb><span class="anntermruby">';
      output += escapeHtml(term);
      output += '</span></rb><rt> ';

      if (showTrans) {
        output += `<span class="anntransruby">${escapeHtml(trans)}</span> `;
      }
      if (showRom && !showTrans) {
        output += `<span class="annromrubysolo">${escapeHtml(rom)}</span> `;
      }
      if (showRom && showTrans) {
        output += `<span class="annromruby" dir="ltr">[${escapeHtml(rom)}]</span> `;
      }

      output += '</rt></ruby> ';
      return output;
    },

    formatAnnotationItem(item: AnnotationItem): string {
      if (!item.isWord) {
        // Non-word (punctuation) - check for paragraph marker
        if (item.text.includes('¶')) {
          const textSize = this.annConfig?.textSize ?? 100;
          return `</p><p style="font-size:${textSize}%;line-height: 1.3; margin-bottom: 10px;">`;
        }
        return ' ' + escapeHtml(item.text) + ' ';
      }

      // Word item - render as ruby
      const ttsClass = this.annConfig?.ttsClass ?? '';
      const translation = item.translation;

      return ` <ruby>
        <rb><span class="${ttsClass}anntermruby">${escapeHtml(item.text)}</span></rb>
        <rt><span class="anntransruby2">${escapeHtml(translation)}</span></rt>
      </ruby> `;
    },

    handlePrint() {
      window.print();
    },

    navigateTo(url: string) {
      window.location.href = url;
    },

    async confirmNavigateTo(url: string, message: string) {
      if (await confirmAction(message)) {
        window.location.href = url;
      }
    },

    async confirmDeleteAnnotation(textId: number) {
      if (await confirmDeletion()) {
        const headers: Record<string, string> = {
          'Content-Type': 'application/json'
        };
        const csrf = getCsrfToken();
        if (csrf) {
          headers['X-CSRF-TOKEN'] = csrf;
        }
        fetch(`/text/${textId}/annotation`, { method: 'DELETE', headers })
          .then((response) => {
            if (response.redirected) {
              window.location.href = response.url;
            } else if (response.ok) {
              window.location.href = `/text/${textId}/print-plain`;
            }
          })
          .catch((error) => {
            console.error('Delete annotation failed:', error);
          });
      }
    },

    openWindow(url: string) {
      window.open(url);
    },

    // CSP-compatible innerHTML setter for print items (use with x-effect)
    setItemHtml(el: HTMLElement, item: PrintItem) {
      el.innerHTML = this.formatItem(item);
    },

    // CSP-compatible innerHTML setter for annotation items (use with x-effect)
    setAnnotationItemHtml(el: HTMLElement, item: AnnotationItem) {
      el.innerHTML = this.formatAnnotationItem(item);
    },

    // Safe accessors (CSP-compatible - avoid ?. in templates)
    getConfigTitle(fallback: string): string {
      return this.config ? this.config.title : fallback;
    },

    getConfigTextSize(fallback: number): number {
      return this.config ? this.config.textSize : fallback;
    },

    getAnnConfigTitle(fallback: string): string {
      return this.annConfig ? this.annConfig.title : fallback;
    },

    getAnnConfigTextSize(fallback: number): number {
      return this.annConfig ? this.annConfig.textSize : fallback;
    }
  };
}

/**
 * Initialize the text print app Alpine.js component.
 */
export function initTextPrintAlpine(): void {
  Alpine.data('textPrintApp', textPrintAppData);
}

// Expose for global access
declare global {
  interface Window {
    textPrintAppData: typeof textPrintAppData;
  }
}

window.textPrintAppData = textPrintAppData;

// Register Alpine data component immediately (before Alpine.start() in main.ts)
initTextPrintAlpine();
