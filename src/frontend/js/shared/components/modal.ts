/**
 * Modal dialog component for LWT.
 *
 * Provides a reusable modal dialog that can display HTML content
 * as a modern replacement for popup windows (openEditWindow/openDictionaryPopup).
 *
 * @license Unlicense <http://unlicense.org/>
 * @since   3.0.0
 */

import { trapFocus, releaseFocus } from '@shared/accessibility/focus_trap';

interface ModalOptions {
  title?: string;
  width?: string;
  maxWidth?: string;
  maxHeight?: string;
  closeOnOverlayClick?: boolean;
  closeOnEscape?: boolean;
}

const defaultOptions: ModalOptions = {
  title: '',
  width: 'auto',
  maxWidth: '800px',
  maxHeight: '80vh',
  closeOnOverlayClick: true,
  closeOnEscape: true
};

let modalInstance: HTMLElement | null = null;
let overlayInstance: HTMLElement | null = null;
let escapeHandler: ((e: KeyboardEvent) => void) | null = null;

/**
 * Create the modal HTML structure if it doesn't exist.
 */
function ensureModalExists(): void {
  if (!document.getElementById('lwt-modal-overlay')) {
    const modalHtml = `
      <div id="lwt-modal-overlay" class="lwt-modal-overlay" style="display:none;">
        <div id="lwt-modal" class="lwt-modal" role="dialog" aria-modal="true" aria-labelledby="lwt-modal-title">
          <div class="lwt-modal-header">
            <h2 class="lwt-modal-title" id="lwt-modal-title"></h2>
            <button type="button" class="lwt-modal-close" aria-label="Close">&times;</button>
          </div>
          <div class="lwt-modal-body"></div>
        </div>
      </div>
    `;
    document.body.insertAdjacentHTML('beforeend', modalHtml);

    // Add CSS if not already present
    if (!document.getElementById('lwt-modal-styles')) {
      const styles = `
        <style id="lwt-modal-styles">
          .lwt-modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 10000;
            display: flex;
            justify-content: center;
            align-items: center;
          }
          .lwt-modal {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
            max-width: 800px;
            width: 90%;
            max-height: 80vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
          }
          .lwt-modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 20px;
            border-bottom: 1px solid #ddd;
            background: #f5f5f5;
          }
          .lwt-modal-title {
            margin: 0;
            font-size: 1.25em;
            font-weight: bold;
            color: #333;
          }
          .lwt-modal-close {
            background: none;
            border: none;
            font-size: 1.5em;
            cursor: pointer;
            color: #666;
            padding: 0;
            line-height: 1;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 4px;
          }
          .lwt-modal-close:hover {
            background: #ddd;
            color: #333;
          }
          .lwt-modal-body {
            padding: 20px;
            overflow-y: auto;
            flex: 1;
          }
          .lwt-modal-body table {
            width: 100%;
            border-collapse: collapse;
          }
          .lwt-modal-body th,
          .lwt-modal-body td {
            padding: 8px 12px;
            border: 1px solid #ddd;
            text-align: start;
          }
          .lwt-modal-body th {
            background: #f0f0f0;
          }
          .lwt-modal-body tr:nth-child(even) {
            background: #fafafa;
          }
          .lwt-modal-body code {
            background: #f4f4f4;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: monospace;
          }
          .lwt-modal-body .center {
            text-align: center;
          }

          /* Dark theme support */
          @media (prefers-color-scheme: dark) {
            .lwt-modal {
              background: #2d2d2d;
              color: #e0e0e0;
            }
            .lwt-modal-header {
              background: #333;
              border-bottom-color: #444;
            }
            .lwt-modal-title {
              color: #e0e0e0;
            }
            .lwt-modal-close {
              color: #aaa;
            }
            .lwt-modal-close:hover {
              background: #444;
              color: #fff;
            }
            .lwt-modal-body th,
            .lwt-modal-body td {
              border-color: #444;
            }
            .lwt-modal-body th {
              background: #383838;
            }
            .lwt-modal-body tr:nth-child(even) {
              background: #333;
            }
            .lwt-modal-body code {
              background: #383838;
            }
          }
        </style>
      `;
      document.head.insertAdjacentHTML('beforeend', styles);
    }

    overlayInstance = document.getElementById('lwt-modal-overlay');
    modalInstance = document.getElementById('lwt-modal');

    // Close button click
    overlayInstance?.addEventListener('click', (e) => {
      const target = e.target as HTMLElement;
      if (target.closest('.lwt-modal-close')) {
        closeModal();
      }
    });
  } else {
    overlayInstance = document.getElementById('lwt-modal-overlay');
    modalInstance = document.getElementById('lwt-modal');
  }
}

/**
 * Fade in an element
 */
function fadeIn(element: HTMLElement, duration: number = 200): void {
  element.style.opacity = '0';
  element.style.display = 'flex';

  let start: number | null = null;
  function animate(timestamp: number): void {
    if (!start) start = timestamp;
    const progress = timestamp - start;
    const opacity = Math.min(progress / duration, 1);
    element.style.opacity = String(opacity);
    if (progress < duration) {
      requestAnimationFrame(animate);
    }
  }
  requestAnimationFrame(animate);
}

/**
 * Fade out an element
 */
function fadeOut(element: HTMLElement, duration: number = 200): void {
  let start: number | null = null;
  function animate(timestamp: number): void {
    if (!start) start = timestamp;
    const progress = timestamp - start;
    const opacity = Math.max(1 - progress / duration, 0);
    element.style.opacity = String(opacity);
    if (progress < duration) {
      requestAnimationFrame(animate);
    } else {
      element.style.display = 'none';
    }
  }
  requestAnimationFrame(animate);
}

/**
 * Open a modal dialog with the given content.
 *
 * @param content HTML content to display in the modal
 * @param options Modal configuration options
 */
export function openModal(content: string, options: ModalOptions = {}): void {
  const opts = { ...defaultOptions, ...options };

  ensureModalExists();

  if (!modalInstance || !overlayInstance) return;

  // Set title
  const titleEl = modalInstance.querySelector('.lwt-modal-title');
  const headerEl = modalInstance.querySelector('.lwt-modal-header') as HTMLElement | null;
  if (titleEl) titleEl.textContent = opts.title || '';
  if (headerEl) headerEl.style.display = opts.title ? '' : 'none';

  // Set content
  const bodyEl = modalInstance.querySelector('.lwt-modal-body');
  if (bodyEl) bodyEl.innerHTML = content;

  // Apply styles
  if (opts.width) {
    modalInstance.style.width = opts.width;
  }
  if (opts.maxWidth) {
    modalInstance.style.maxWidth = opts.maxWidth;
  }
  if (opts.maxHeight) {
    modalInstance.style.maxHeight = opts.maxHeight;
  }

  // Show modal
  fadeIn(overlayInstance);

  // Trap focus within modal
  if (modalInstance) {
    trapFocus(modalInstance);
  }

  // Close on overlay click
  if (opts.closeOnOverlayClick) {
    overlayInstance.onclick = function (e) {
      if (e.target === overlayInstance) {
        closeModal();
      }
    };
  } else {
    overlayInstance.onclick = null;
  }

  // Close on Escape key
  if (escapeHandler) {
    document.removeEventListener('keydown', escapeHandler);
  }
  if (opts.closeOnEscape) {
    escapeHandler = function (e: KeyboardEvent) {
      if (e.key === 'Escape') {
        closeModal();
      }
    };
    document.addEventListener('keydown', escapeHandler);
  }

  // Prevent body scroll
  document.body.style.overflow = 'hidden';
}

/**
 * Close the currently open modal.
 */
export function closeModal(): void {
  releaseFocus();
  if (overlayInstance) {
    fadeOut(overlayInstance);
  }
  if (escapeHandler) {
    document.removeEventListener('keydown', escapeHandler);
    escapeHandler = null;
  }
  document.body.style.overflow = '';
}

/**
 * Show the export template help modal.
 * This is a convenience function replacing openEditWindow('export_template.html').
 */
export function showExportTemplateHelp(): void {
  const content = `
    <p>An export template consists of a string of characters. Some parts of this string are <b>placeholders</b> (beginning with <b>"%", "$" or "\\"</b>) that are <b>replaced</b> by the actual term data, <b>see the following table</b>. For each term (word or expression), that has been selected for export, the placeholders of the export template will be replaced by the term data and the string will be written to the export file.</p>

    <p><b>A template must end with</b> either <b>"\\n"</b> (UNIX, Mac) or <b>"\\r\\n"</b> (Windows). <b>If you omit this, the whole export will be one single line!</b></p>

    <p>If the export template is <b>empty, no terms of this language</b> will be exported.</p>

    <table>
      <tr><th colspan="2">Placeholders: <code>%...</code> = Raw Text</th></tr>
      <tr><td class="has-text-centered"><code>%w</code></td><td>Term (Word/Expression) - as <b>raw text</b>.</td></tr>
      <tr><td class="has-text-centered"><code>%t</code></td><td>Translation - as <b>raw text</b>.</td></tr>
      <tr><td class="has-text-centered"><code>%s</code></td><td>Sentence, curly braces removed - as <b>raw text</b>.</td></tr>
      <tr><td class="has-text-centered"><code>%c</code></td><td>The sentence, but the "{xxx}" parts are replaced by "[...]" (cloze test question) - as <b>raw text</b>.</td></tr>
      <tr><td class="has-text-centered"><code>%d</code></td><td>The sentence, but the "{xxx}" parts are replaced by "[xxx]" (cloze test solution) - as <b>raw text</b>.</td></tr>
      <tr><td class="has-text-centered"><code>%r</code></td><td>Romanization - as <b>raw text</b>.</td></tr>
      <tr><td class="has-text-centered"><code>%a</code></td><td>Status (1..5, 98, 99) - as <b>raw text</b>.</td></tr>
      <tr><td class="has-text-centered"><code>%k</code></td><td>Term in lowercase (key) - as <b>raw text</b>.</td></tr>
      <tr><td class="has-text-centered"><code>%z</code></td><td>Tag List - as <b>raw text</b>.</td></tr>
      <tr><td class="has-text-centered"><code>%l</code></td><td>Language - as <b>raw text</b>.</td></tr>
      <tr><td class="has-text-centered"><code>%n</code></td><td>Word Number in LWT (key in table "words") - as <b>raw text</b>.</td></tr>
      <tr><td class="has-text-centered"><code>%%</code></td><td>Just one percent sign "%".</td></tr>

      <tr><th colspan="2">Placeholders: <code>$...</code> = HTML Text (escaped: &lt; &gt; &amp; &quot;)</th></tr>
      <tr><td class="has-text-centered"><code>$w</code></td><td>Term (Word/Expression) - as <b>HTML text</b>.</td></tr>
      <tr><td class="has-text-centered"><code>$t</code></td><td>Translation - as <b>HTML text</b>.</td></tr>
      <tr><td class="has-text-centered"><code>$s</code></td><td>Sentence, curly braces removed - as <b>HTML text</b>.</td></tr>
      <tr><td class="has-text-centered"><code>$c</code></td><td>The sentence, but the "{xxx}" parts are replaced by "[...]" (cloze test question) - as <b>HTML text</b>.</td></tr>
      <tr><td class="has-text-centered"><code>$d</code></td><td>The sentence, but the "{xxx}" parts are replaced by "[xxx]" (cloze test solution) - as <b>HTML text</b>.</td></tr>
      <tr><td class="has-text-centered"><code>$x</code></td><td>The sentence in Anki2 cloze test notation: the "{xxx}" parts are replaced by "{{c1::xxx}}" - as <b>HTML text</b>.</td></tr>
      <tr><td class="has-text-centered"><code>$y</code></td><td>The sentence in Anki2 cloze test notation, with translation: the "{xxx}" parts are replaced by "{{c1::xxx::translation}}" - as <b>HTML text</b>.</td></tr>
      <tr><td class="has-text-centered"><code>$r</code></td><td>Romanization - as <b>HTML text</b>.</td></tr>
      <tr><td class="has-text-centered"><code>$k</code></td><td>Term in lowercase (key) - as <b>HTML text</b>.</td></tr>
      <tr><td class="has-text-centered"><code>$z</code></td><td>Tag List - as <b>HTML text</b>.</td></tr>
      <tr><td class="has-text-centered"><code>$l</code></td><td>Language - as <b>HTML text</b>.</td></tr>
      <tr><td class="has-text-centered"><code>$$</code></td><td>Just one dollar sign "$".</td></tr>

      <tr><th colspan="2">Special Characters: <code>\\...</code></th></tr>
      <tr><td class="has-text-centered"><code>\\t</code></td><td>TAB character (HEX 9).</td></tr>
      <tr><td class="has-text-centered"><code>\\n</code></td><td>NEWLINE character (HEX 10).</td></tr>
      <tr><td class="has-text-centered"><code>\\r</code></td><td>CARRIAGE RETURN character (HEX 13).</td></tr>
      <tr><td class="has-text-centered"><code>\\\\</code></td><td>Just one backslash "\\".</td></tr>
    </table>
  `;

  openModal(content, {
    title: 'About LWT Export Templates for "Flexible Exports"',
    maxWidth: '900px'
  });
}
