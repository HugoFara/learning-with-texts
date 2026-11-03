/**
 * Result Panel - In-page panel to replace frame-based result display.
 *
 * This module provides a panel component that can display operation results,
 * word details, and other content that was previously shown in the right frame.
 *
 * Part of Phase 4: Frame Architecture Removal
 *
 * @license Unlicense <http://unlicense.org/>
 * @since   3.0.0
 */

/**
 * Configuration options for showing the result panel.
 */
export interface ResultPanelOptions {
  /** Title for the panel header */
  title?: string;
  /** Auto-close the panel after showing */
  autoClose?: boolean;
  /** Duration in ms before auto-closing (default: 3000) */
  duration?: number;
  /** CSS class to add to the panel */
  className?: string;
  /** Position: 'right', 'bottom', or 'center' */
  position?: 'right' | 'bottom' | 'center';
  /** Show close button */
  showCloseButton?: boolean;
}

const DEFAULT_OPTIONS: ResultPanelOptions = {
  title: 'Result',
  autoClose: false,
  duration: 3000,
  position: 'right',
  showCloseButton: true
};

let panelElement: HTMLElement | null = null;
let autoCloseTimer: ReturnType<typeof setTimeout> | null = null;

/**
 * Ensure the result panel container exists in the DOM.
 */
function ensurePanelElement(): HTMLElement {
  if (panelElement && document.body.contains(panelElement)) {
    return panelElement;
  }

  // Create panel structure
  const panel = document.createElement('div');
  panel.id = 'lwt-result-panel';
  panel.className = 'lwt-result-panel';
  panel.innerHTML = `
    <div class="lwt-result-panel-header">
      <span class="lwt-result-panel-title">Result</span>
      <button class="lwt-result-panel-close" type="button" aria-label="Close">&times;</button>
    </div>
    <div class="lwt-result-panel-content"></div>
  `;

  // Add close button handler
  const closeBtn = panel.querySelector('.lwt-result-panel-close');
  closeBtn?.addEventListener('click', hideResultPanel);

  document.body.appendChild(panel);
  panelElement = panel;

  return panel;
}

/**
 * Show the result panel with content.
 *
 * @param content HTML content or string to display
 * @param options Display options
 */
export function showResultPanel(
  content: string | HTMLElement,
  options: ResultPanelOptions = {}
): void {
  const opts = { ...DEFAULT_OPTIONS, ...options };
  const panel = ensurePanelElement();

  // Clear any existing auto-close timer
  if (autoCloseTimer) {
    clearTimeout(autoCloseTimer);
    autoCloseTimer = null;
  }

  // Set title
  const titleEl = panel.querySelector('.lwt-result-panel-title');
  if (titleEl) {
    titleEl.textContent = opts.title || 'Result';
  }

  // Set content
  const contentEl = panel.querySelector('.lwt-result-panel-content');
  if (contentEl) {
    if (typeof content === 'string') {
      contentEl.innerHTML = content;
    } else {
      contentEl.innerHTML = '';
      contentEl.appendChild(content);
    }
  }

  // Show/hide close button
  const closeBtn = panel.querySelector('.lwt-result-panel-close') as HTMLElement;
  if (closeBtn) {
    closeBtn.style.display = opts.showCloseButton ? '' : 'none';
  }

  // Apply position class
  panel.className = 'lwt-result-panel';
  if (opts.position) {
    panel.classList.add(`lwt-result-panel--${opts.position}`);
  }
  if (opts.className) {
    panel.classList.add(opts.className);
  }

  // Show the panel
  panel.classList.add('lwt-result-panel--visible');

  // Set up auto-close if requested
  if (opts.autoClose && opts.duration) {
    autoCloseTimer = setTimeout(() => {
      hideResultPanel();
    }, opts.duration);
  }
}

/**
 * Hide the result panel.
 */
export function hideResultPanel(): void {
  if (autoCloseTimer) {
    clearTimeout(autoCloseTimer);
    autoCloseTimer = null;
  }

  if (panelElement) {
    panelElement.classList.remove('lwt-result-panel--visible');
  }
}

/**
 * Show an error message in the result panel.
 *
 * @param message Error message to display
 */
export function showErrorInPanel(message: string): void {
  showResultPanel(
    `<div class="lwt-result-panel-error">${escapeHtml(message)}</div>`,
    {
      title: 'Error',
      className: 'lwt-result-panel--error',
      autoClose: true,
      duration: 5000
    }
  );
}

/**
 * Show a success message in the result panel.
 *
 * @param message Success message to display
 */
export function showSuccessInPanel(message: string): void {
  showResultPanel(
    `<div class="lwt-result-panel-success">${escapeHtml(message)}</div>`,
    {
      title: 'Success',
      className: 'lwt-result-panel--success',
      autoClose: true,
      duration: 2000
    }
  );
}

/**
 * Show word details in the result panel.
 *
 * @param wordData Word data to display
 */
export function showWordDetails(wordData: {
  text: string;
  translation?: string;
  romanization?: string;
  status?: number;
  statusName?: string;
}): void {
  let html = `<div class="lwt-result-panel-word">`;
  html += `<div class="lwt-word-text">${escapeHtml(wordData.text)}</div>`;

  if (wordData.romanization) {
    html += `<div class="lwt-word-romanization">${escapeHtml(wordData.romanization)}</div>`;
  }

  if (wordData.translation) {
    html += `<div class="lwt-word-translation">${escapeHtml(wordData.translation)}</div>`;
  }

  if (wordData.statusName) {
    html += `<div class="lwt-word-status">Status: ${escapeHtml(wordData.statusName)}</div>`;
  }

  html += `</div>`;

  showResultPanel(html, {
    title: 'Word Details',
    showCloseButton: true
  });
}

/**
 * Show loading state in the result panel.
 *
 * @param message Optional loading message
 */
export function showLoadingInPanel(message: string = 'Loading...'): void {
  showResultPanel(
    `<div class="lwt-result-panel-loading">
      <span class="lwt-loading-spinner"></span>
      <span>${escapeHtml(message)}</span>
    </div>`,
    {
      title: 'Loading',
      showCloseButton: false,
      autoClose: false
    }
  );
}

/**
 * Update the content of the result panel without changing visibility.
 *
 * @param content New content to display
 */
export function updatePanelContent(content: string | HTMLElement): void {
  if (!panelElement) return;

  const contentEl = panelElement.querySelector('.lwt-result-panel-content');
  if (contentEl) {
    if (typeof content === 'string') {
      contentEl.innerHTML = content;
    } else {
      contentEl.innerHTML = '';
      contentEl.appendChild(content);
    }
  }
}

/**
 * Check if the result panel is currently visible.
 */
export function isPanelVisible(): boolean {
  return panelElement?.classList.contains('lwt-result-panel--visible') ?? false;
}

/**
 * Escape HTML special characters.
 */
function escapeHtml(text: string): string {
  const div = document.createElement('div');
  div.textContent = text;
  return div.innerHTML;
}

// Inject CSS styles for the result panel
const styles = `
.lwt-result-panel {
  position: fixed;
  z-index: 10000;
  background: #fff;
  border: 1px solid #ccc;
  border-radius: 4px;
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
  min-width: 250px;
  max-width: 400px;
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
  font-size: 14px;
  opacity: 0;
  visibility: hidden;
  transition: opacity 0.2s ease, visibility 0.2s ease, transform 0.2s ease;
}

.lwt-result-panel--visible {
  opacity: 1;
  visibility: visible;
}

/* Position variants */
.lwt-result-panel--right {
  top: 80px;
  right: 20px;
  transform: translateX(20px);
}

.lwt-result-panel--right.lwt-result-panel--visible {
  transform: translateX(0);
}

.lwt-result-panel--bottom {
  bottom: 20px;
  left: 50%;
  transform: translateX(-50%) translateY(20px);
}

.lwt-result-panel--bottom.lwt-result-panel--visible {
  transform: translateX(-50%) translateY(0);
}

.lwt-result-panel--center {
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%) scale(0.95);
}

.lwt-result-panel--center.lwt-result-panel--visible {
  transform: translate(-50%, -50%) scale(1);
}

.lwt-result-panel-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 10px 15px;
  background: #5050A0;
  color: #fff;
  border-radius: 4px 4px 0 0;
}

.lwt-result-panel-title {
  font-weight: 600;
  font-size: 14px;
}

.lwt-result-panel-close {
  background: none;
  border: none;
  color: #fff;
  font-size: 20px;
  cursor: pointer;
  padding: 0 5px;
  line-height: 1;
  opacity: 0.8;
}

.lwt-result-panel-close:hover {
  opacity: 1;
}

.lwt-result-panel-content {
  padding: 15px;
  max-height: 300px;
  overflow-y: auto;
}

/* State variants */
.lwt-result-panel--error .lwt-result-panel-header {
  background: #dc3545;
}

.lwt-result-panel--success .lwt-result-panel-header {
  background: #28a745;
}

.lwt-result-panel-error {
  color: #dc3545;
}

.lwt-result-panel-success {
  color: #28a745;
}

/* Word details styling */
.lwt-result-panel-word {
  text-align: center;
}

.lwt-word-text {
  font-size: 1.5em;
  font-weight: bold;
  margin-bottom: 8px;
}

.lwt-word-romanization {
  color: #666;
  font-style: italic;
  margin-bottom: 8px;
}

.lwt-word-translation {
  font-size: 1.1em;
  margin-bottom: 8px;
}

.lwt-word-status {
  color: #888;
  font-size: 0.9em;
}

/* Loading state */
.lwt-result-panel-loading {
  display: flex;
  align-items: center;
  gap: 10px;
  justify-content: center;
  padding: 10px;
}

.lwt-loading-spinner {
  width: 20px;
  height: 20px;
  border: 2px solid #f3f3f3;
  border-top: 2px solid #5050A0;
  border-radius: 50%;
  animation: lwt-spin 1s linear infinite;
}

@keyframes lwt-spin {
  0% { transform: rotate(0deg); }
  100% { transform: rotate(360deg); }
}

/* Dark mode support */
@media (prefers-color-scheme: dark) {
  .lwt-result-panel {
    background: #2d2d2d;
    border-color: #444;
    color: #e0e0e0;
  }

  .lwt-result-panel-header {
    background: #3d3d8d;
  }

  .lwt-word-romanization,
  .lwt-word-status {
    color: #aaa;
  }

  .lwt-status-btn,
  .lwt-action-btn {
    color: #6699ff;
  }

  .lwt-status-btn:hover:not(:disabled),
  .lwt-action-btn:hover:not(:disabled) {
    color: #ff6666;
  }

  .lwt-status-btn--current {
    color: #e0e0e0;
  }

  .lwt-status-btn:disabled {
    color: #888;
  }

  .lwt-test-btn:hover:not(:disabled) {
    background: rgba(255, 255, 255, 0.05);
  }
}

/* Explicit dark theme */
:root[data-theme="dark"] .lwt-result-panel {
  background: #2d2d2d;
  border-color: #444;
  color: #e0e0e0;
}

:root[data-theme="dark"] .lwt-result-panel-header {
  background: #3d3d8d;
}

:root[data-theme="dark"] .lwt-word-romanization,
:root[data-theme="dark"] .lwt-word-status {
  color: #aaa;
}

:root[data-theme="dark"] .lwt-status-btn,
:root[data-theme="dark"] .lwt-action-btn {
  color: #6699ff;
}

:root[data-theme="dark"] .lwt-status-btn:hover:not(:disabled),
:root[data-theme="dark"] .lwt-action-btn:hover:not(:disabled) {
  color: #ff6666;
}

:root[data-theme="dark"] .lwt-status-btn--current {
  color: #e0e0e0;
}

:root[data-theme="dark"] .lwt-status-btn:disabled {
  color: #888;
}

:root[data-theme="dark"] .lwt-test-btn:hover:not(:disabled) {
  background: rgba(255, 255, 255, 0.05);
}

/* Popup button styles (for word popup API-based buttons) */
.lwt-status-btn {
  background: none;
  border: none;
  color: #0000FF;
  cursor: pointer;
  padding: 2px 4px;
  font-size: inherit;
  font-family: inherit;
}

.lwt-status-btn:hover:not(:disabled) {
  color: #FF0000;
  text-decoration: underline;
}

.lwt-status-btn:disabled {
  cursor: default;
  color: #666;
}

.lwt-status-btn--current {
  color: #000;
}

.lwt-action-btn {
  background: none;
  border: none;
  color: #0000FF;
  cursor: pointer;
  padding: 2px 4px;
  font-size: inherit;
  font-family: inherit;
}

.lwt-action-btn:hover:not(:disabled) {
  color: #FF0000;
  text-decoration: underline;
}

.lwt-action-btn:disabled {
  cursor: not-allowed;
  opacity: 0.5;
}

.lwt-test-btn {
  display: block;
  width: 100%;
  background: none;
  border: none;
  cursor: pointer;
  padding: 8px;
  font-size: inherit;
  font-family: inherit;
  text-align: center;
}

.lwt-test-btn:hover:not(:disabled) {
  background: rgba(0, 0, 0, 0.05);
}

.lwt-test-btn:disabled {
  cursor: not-allowed;
  opacity: 0.5;
}

.lwt-test-btn img {
  vertical-align: middle;
  margin-inline-end: 5px;
}

.lwt-word-popup-content {
  font-size: 13px;
}

.lwt-popup-row {
  margin-bottom: 8px;
}

.lwt-popup-row:last-child {
  margin-bottom: 0;
}

.lwt-popup-audio {
  text-align: center;
  margin-bottom: 10px;
}

.lwt-popup-audio img {
  cursor: pointer;
  vertical-align: middle;
}
`;

// Inject styles when module loads
if (typeof document !== 'undefined') {
  const styleEl = document.createElement('style');
  styleEl.id = 'lwt-result-panel-styles';
  styleEl.textContent = styles;

  // Only add if not already present
  if (!document.getElementById('lwt-result-panel-styles')) {
    document.head.appendChild(styleEl);
  }
}
