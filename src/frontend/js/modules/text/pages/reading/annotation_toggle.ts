/**
 * Annotation Toggle - Show/hide translations and annotations in text display.
 *
 * Drives the toggle buttons in the annotated display and print views.
 *
 * @license unlicense
 * @since 3.0.0
 */

import { onDomReady } from '@shared/utils/dom_ready';

/**
 * Hide translations (text display).
 * Sets the translation ruby text to match background color.
 */
export function doHideTranslations(): void {
  const showt = document.getElementById('showt');
  const hidet = document.getElementById('hidet');
  if (showt) showt.style.display = '';
  if (hidet) hidet.style.display = 'none';
  document.querySelectorAll<HTMLElement>('.anntermruby').forEach(el => {
    el.style.color = '#E5E4E2';
    el.style.backgroundColor = '#E5E4E2';
  });
}

/**
 * Show translations (text display).
 * Restores the translation ruby text to normal visibility.
 */
export function doShowTranslations(): void {
  const showt = document.getElementById('showt');
  const hidet = document.getElementById('hidet');
  if (showt) showt.style.display = 'none';
  if (hidet) hidet.style.display = '';
  document.querySelectorAll<HTMLElement>('.anntermruby').forEach(el => {
    el.style.color = 'inherit';
    el.style.backgroundColor = '';
  });
}

/**
 * Hide annotations (text display).
 * Sets the annotation ruby text to match background color.
 */
export function doHideAnnotations(): void {
  const show = document.getElementById('show');
  const hide = document.getElementById('hide');
  if (show) show.style.display = '';
  if (hide) hide.style.display = 'none';
  document.querySelectorAll<HTMLElement>('.anntransruby2').forEach(el => {
    el.style.color = '#C8DCF0';
    el.style.backgroundColor = '#C8DCF0';
  });
}

/**
 * Show annotations (text display).
 * Restores the annotation ruby text to normal visibility.
 */
export function doShowAnnotations(): void {
  const show = document.getElementById('show');
  const hide = document.getElementById('hide');
  if (show) show.style.display = 'none';
  if (hide) hide.style.display = '';
  document.querySelectorAll<HTMLElement>('.anntransruby2').forEach(el => {
    el.style.color = '';
    el.style.backgroundColor = '';
  });
}

/**
 * Close the current window.
 * Used for the close button in print/display views.
 */
export function closeWindow(): void {
  window.top?.close();
}

/**
 * Initialize annotation toggle buttons.
 * Sets up click handlers using data-action attributes.
 */
export function initAnnotationToggles(): void {
  // Translation toggles
  const hideTransBtn = document.querySelector('[data-action="hide-translations"]');
  const showTransBtn = document.querySelector('[data-action="show-translations"]');

  if (hideTransBtn) {
    hideTransBtn.addEventListener('click', doHideTranslations);
  }
  if (showTransBtn) {
    showTransBtn.addEventListener('click', doShowTranslations);
  }

  // Annotation toggles
  const hideAnnBtn = document.querySelector('[data-action="hide-annotations"]');
  const showAnnBtn = document.querySelector('[data-action="show-annotations"]');

  if (hideAnnBtn) {
    hideAnnBtn.addEventListener('click', doHideAnnotations);
  }
  if (showAnnBtn) {
    showAnnBtn.addEventListener('click', doShowAnnotations);
  }

  // Close window button
  const closeBtn = document.querySelector('[data-action="close-window"]');
  if (closeBtn) {
    closeBtn.addEventListener('click', closeWindow);
  }
}

// Auto-initialize on DOM ready if toggle elements are present
onDomReady(() => {
  // Only initialize if we're on a page with annotation toggles
  if (
    document.getElementById('hidet') ||
    document.getElementById('hide') ||
    document.querySelector('[data-action="hide-translations"]')
  ) {
    initAnnotationToggles();
  }
});
