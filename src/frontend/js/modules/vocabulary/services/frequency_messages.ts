/**
 * Progress and result messages for frequency-word imports.
 *
 * Shared by the term-import page's frequency tab and the starter vocabulary
 * page. Each message is one translation with its numbers inside it, rather
 * than numbers placed between word fragments, so a translator can order the
 * sentence and pick the plural form the count needs (#319).
 *
 * @license Unlicense <http://unlicense.org/>
 * @since   3.8.0
 */

import { t, tn } from '@shared/i18n/translator';

/** Which kind of gloss enrichment fetches. */
export type EnrichMode = 'translation' | 'definition' | string;

/** "Fetching translations..." or "Fetching definitions...". */
export function enrichingLabel(mode: EnrichMode): string {
  return mode === 'translation'
    ? t('vocabulary.upload.freq.fetching_translations')
    : t('vocabulary.upload.freq.fetching_definitions');
}

/** "12 of 100 words enriched". */
export function enrichProgress(done: number, total: number): string {
  return tn('vocabulary.upload.freq.enrich_progress', total, { done });
}

/** "(3 not found)". */
export function notFound(failed: number): string {
  return tn('vocabulary.upload.freq.not_found_count', failed);
}

/** "Imported 100 words for French." */
export function importedResult(imported: number, language: string): string {
  return tn('vocabulary.upload.freq.imported_result', imported, { lang: language });
}

/** "4 already existed." */
export function skippedResult(skipped: number): string {
  return tn('vocabulary.upload.freq.skipped_result', skipped);
}

/** "96 words enriched with translations." */
export function enrichedResult(done: number, mode: EnrichMode): string {
  return mode === 'translation'
    ? tn('vocabulary.upload.freq.enriched_translations', done)
    : tn('vocabulary.upload.freq.enriched_definitions', done);
}

/** "3 of 5". */
export function progressOf(current: number, total: number): string {
  return t('vocabulary.upload.freq.progress_of', { current, total });
}

/**
 * "Wiktionary: imported 1200 entries. 30 vocabulary terms created.
 * 4 skipped: headword too long."
 */
export function dictionaryImported(
  name: string,
  imported: number,
  skipped = 0,
  vocabCreated = 0
): string {
  const parts = [tn('vocabulary.upload.freq.dict_imported', imported, { name })];
  if (vocabCreated > 0) {
    parts.push(tn('vocabulary.upload.freq.dict_vocab_created', vocabCreated));
  }
  if (skipped > 0) {
    parts.push(tn('vocabulary.upload.freq.dict_skipped', skipped));
  }
  return parts.join(' ');
}
