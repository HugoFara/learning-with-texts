/**
 * Step Icons - hydrate the Lucide placeholders a wizard step brings with it.
 *
 * `IconHelper::render()` emits `<i data-lucide="…">` for a script to replace,
 * and that replacement runs once on `alpine:initialized`. That was enough
 * while each wizard step was a page load of its own; now that the steps are
 * `x-if` panels of one page, a step's markup enters the DOM long after that
 * pass has run, and its icons stay as empty placeholders.
 *
 * The frame's wait lets Alpine finish rendering the step's own templates
 * first, so icons inside `x-for` and nested `x-if` are hydrated too.
 *
 * @license Unlicense <http://unlicense.org/>
 * @since   3.5.1
 */

import { initIcons } from '@shared/icons/lucide_icons';

/**
 * Replace the icon placeholders of the step that has just mounted.
 */
export function hydrateStepIcons(): void {
  requestAnimationFrame(() => initIcons());
}
