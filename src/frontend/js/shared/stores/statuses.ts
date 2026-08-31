/**
 * Status store — the single frontend source of truth for the word-status model.
 *
 * Word status is an integer: 1-5 (learning stages), 98 (ignored), 99 (well-known),
 * plus the frontend-only pseudo-status 0 (a word not yet saved to the vocabulary).
 *
 * The structural model here (values, order, abbreviations, CSS classes, predicates)
 * mirrors the backend value object `TermStatus` (PHP), which serves the same data via
 * `GET /api/v1/settings/status-definitions`. Labels are localized through the shared
 * `common.status_*` i18n keys, so PHP and TS resolve identical text from one source.
 *
 * Before this store, label/abbr/order/class tables were re-defined across half a dozen
 * components; consume the helpers here instead of hardcoding new ones.
 *
 * @license Unlicense <http://unlicense.org/>
 * @since   3.2.2
 */

import type { WordStatus } from '@/types/globals';
import { t } from '@shared/i18n/translator';

/**
 * Canonical display order, including the pseudo-status 0 (Unknown / not yet saved).
 * Well-known (99) precedes ignored (98), matching the reading view and charts.
 */
export const STATUS_DISPLAY_ORDER: readonly number[] = [0, 1, 2, 3, 4, 5, 99, 98];

/**
 * Real, saveable term statuses (excludes the 0 pseudo-status), in canonical order.
 */
export const TERM_STATUS_VALUES: readonly number[] = [1, 2, 3, 4, 5, 99, 98];

/**
 * Fallback colour per status, mirroring the `--lwt-status*` custom properties
 * in `css/base/styles.css` and the `TermStatus::COLOURS` table in PHP.
 *
 * Only a fallback: {@link statusColour} prefers the live custom property, so a
 * theme that repaints the reading view repaints anything reading colours from
 * here along with it. These values are what to use when there is no document
 * to ask -- under Vitest, or before styles have loaded.
 */
export const STATUS_COLOURS: Readonly<Record<number, string>> = {
  0: '#5ABAFF',
  1: '#E85A3C',
  2: '#E8893C',
  3: '#E8B83C',
  4: '#E8E23C',
  5: '#66CC66',
  98: '#888888',
  99: '#CCFFCC'
};

/**
 * Bulma modifier class per status, for tags and buttons.
 *
 * One table because there were four, and they had drifted: status 3 was
 * `is-info` in the popover and `is-warning` in the word list, and status 99 was
 * `is-success is-light`, `is-success` and `is-info` in three different places.
 * This is the majority reading of the three that agreed.
 */
const STATUS_TAG_CLASSES: Readonly<Record<number, string>> = {
  1: 'is-danger',
  2: 'is-warning',
  3: 'is-info',
  4: 'is-primary',
  5: 'is-success',
  98: 'is-light',
  99: 'is-success is-light'
};

/** A status and its display metadata. */
export interface StatusDefinition {
  value: number;
  /** Localized base name, e.g. "Learning", "Learned". */
  name: string;
  /** Localized label with a level suffix for learning stages, e.g. "Learning (1)". */
  label: string;
  /** Language-neutral abbreviation ('1'..'5'); empty for 0/98/99 (use the name). */
  abbr: string;
  /** Reading-view / chart CSS class, e.g. "status1". */
  cssClass: string;
  /** Bulma modifier class for a tag or button, e.g. "is-danger". */
  tagClass: string;
  /** Resolved colour, from the active theme where one is readable. */
  colour: string;
  /** Position in {@link STATUS_DISPLAY_ORDER} (0-based). */
  order: number;
}

/**
 * Localized base name for a status (no level suffix).
 *
 * @param value Status value (0, 1-5, 98, 99)
 */
export function statusName(value: number): string {
  switch (value) {
    case 0: return t('common.status_unknown');
    case 5: return t('common.status_learned');
    case 98: return t('common.status_ignored');
    case 99: return t('common.status_well_known');
    default: return t('common.status_learning'); // learning stages 1-4
  }
}

/**
 * Localized display label: learning stages 1-5 get a level suffix
 * ("Learning (1)" … "Learned (5)"); others use the bare name.
 *
 * @param value Status value (0, 1-5, 98, 99)
 */
export function statusLabel(value: number): string {
  if (value >= 1 && value <= 5) {
    return `${statusName(value)} (${value})`;
  }
  return statusName(value);
}

/**
 * Language-neutral abbreviation: '1'..'5' for learning stages, '' otherwise
 * (display code should fall back to {@link statusName}).
 *
 * @param value Status value
 */
export function statusAbbr(value: number): string {
  return value >= 1 && value <= 5 ? String(value) : '';
}

/**
 * CSS class for a status (e.g. "status1", "status99", "status0" for unknown).
 *
 * @param value Status value
 */
export function statusCssClass(value: number): string {
  return `status${value}`;
}

/**
 * Bulma modifier class for a status tag or button.
 *
 * @param value Status value
 */
export function statusTagClass(value: number): string {
  return STATUS_TAG_CLASSES[value] ?? '';
}

/**
 * Colour for a status, following the active theme.
 *
 * Reads the `--lwt-status{n}` custom property the reading view is painted with,
 * so a chart drawn from this matches the words on the page in whichever theme
 * the user picked -- the admin statistics chart used to carry its own hardcoded
 * palette and showed different colours from the text for the same status.
 *
 * Falls back to {@link STATUS_COLOURS} when there is no document to ask, and
 * when the property resolves to `transparent`: the underline themes set that
 * for statuses they do not fill, which is meaningful on a word and invisible on
 * a chart.
 *
 * @param value Status value
 */
export function statusColour(value: number): string {
  const fallback = STATUS_COLOURS[value] ?? '';
  if (typeof document === 'undefined' || document.documentElement === null) {
    return fallback;
  }

  const resolved = getComputedStyle(document.documentElement)
    .getPropertyValue(`--lwt-status${value}`)
    .trim();

  return resolved === '' || resolved === 'transparent' ? fallback : resolved;
}

/** Whether a status counts as "known" (learned or well-known). */
export function isKnownStatus(value: number): boolean {
  return value === 5 || value === 99;
}

/** Whether a status is a learning stage (1-5). */
export function isLearningStatus(value: number): boolean {
  return value >= 1 && value <= 5;
}

/** Whether a status is the ignored flag (98). */
export function isIgnoredStatus(value: number): boolean {
  return value === 98;
}

/**
 * Full metadata for a single status.
 *
 * @param value Status value
 */
export function getStatusDefinition(value: number): StatusDefinition {
  return {
    value,
    name: statusName(value),
    label: statusLabel(value),
    abbr: statusAbbr(value),
    cssClass: statusCssClass(value),
    tagClass: statusTagClass(value),
    colour: statusColour(value),
    order: STATUS_DISPLAY_ORDER.indexOf(value),
  };
}

/**
 * Definitions for every real term status (1-5, 99, 98) in canonical order.
 * Pass `includeUnknown` to prepend the 0 pseudo-status (for charts).
 *
 * @param includeUnknown Include the Unknown (0) pseudo-status
 */
export function getStatusDefinitions(includeUnknown = false): StatusDefinition[] {
  const order = includeUnknown ? STATUS_DISPLAY_ORDER : TERM_STATUS_VALUES;
  return order.map(getStatusDefinition);
}

/**
 * The shape the status pickers render: a value, a label, something short to
 * put on the button, and a Bulma modifier.
 *
 * Narrower than {@link StatusDefinition} on purpose -- it is what a template
 * binds to, and two components had grown their own copy of it side by side.
 */
export interface StatusInfo {
  value: number;
  label: string;
  abbr: string;
  class: string;
}

/**
 * Status options for a picker, in the order given.
 *
 * Built per call rather than held in a module constant, because labels come
 * from the translator and it is not ready when a module is evaluated. The
 * tables this replaces were module constants of hardcoded English, so every
 * non-English user saw "Learning (1)" and "Well Known" whatever their locale.
 *
 * @param values Statuses to offer, in the order to offer them
 */
export function statusOptions(values: readonly number[]): StatusInfo[] {
  return values.map((value) => ({
    value,
    label: statusLabel(value),
    // 98 and 99 have no digit to abbreviate to, so their name stands in.
    abbr: statusAbbr(value) || statusName(value),
    class: statusTagClass(value)
  }));
}

/**
 * Word statuses — localized labels, keyed by status value.
 *
 * Numeric statuses use digit abbreviations ("1".."5"); for 98/99 there is no good
 * cross-language abbreviation, so the localized full name doubles as `name` and `abbr`.
 *
 * A getter (Proxy) rather than a static object, so the translator has time to
 * initialize before the strings are read.
 */
export const statuses: Record<number, WordStatus> = new Proxy({} as Record<number, WordStatus>, {
  get(_target, prop: string | symbol): WordStatus | undefined {
    const key = Number(prop);
    if (Number.isNaN(key) || !TERM_STATUS_VALUES.includes(key)) return undefined;
    const name = statusName(key);
    const abbr = statusAbbr(key);
    return { abbr: abbr === '' ? name : abbr, name };
  },
  ownKeys(): string[] {
    return TERM_STATUS_VALUES.map(String);
  },
  getOwnPropertyDescriptor(): PropertyDescriptor {
    return { enumerable: true, configurable: true };
  },
  has(_target, prop: string | symbol): boolean {
    return TERM_STATUS_VALUES.includes(Number(prop));
  }
});
