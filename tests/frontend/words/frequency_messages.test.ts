/**
 * Frequency-import messages are whole translations with the counts inside,
 * so each language can order the sentence and pick its plural form (#319).
 */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

const ARABIC = {
  'vocabulary.upload.freq.imported_result_one': 'تم استيراد كلمة واحدة لـ{lang}.',
  'vocabulary.upload.freq.imported_result_two': 'تم استيراد كلمتين لـ{lang}.',
  'vocabulary.upload.freq.imported_result_few': 'تم استيراد {count} كلمات لـ{lang}.',
  'vocabulary.upload.freq.imported_result_many': 'تم استيراد {count} كلمةً لـ{lang}.',
  'vocabulary.upload.freq.imported_result_other': 'تم استيراد {count} كلمة لـ{lang}.',
};

const ENGLISH = {
  'vocabulary.upload.freq.fetching_translations': 'Fetching translations...',
  'vocabulary.upload.freq.fetching_definitions': 'Fetching definitions...',
  'vocabulary.upload.freq.enrich_progress_one': '{done} of {count} word enriched',
  'vocabulary.upload.freq.enrich_progress_other': '{done} of {count} words enriched',
  'vocabulary.upload.freq.enriched_translations_other': '{count} words enriched with translations.',
  'vocabulary.upload.freq.enriched_definitions_other': '{count} words enriched with definitions.',
  'vocabulary.upload.freq.dict_imported_one': '{name}: imported {count} entry.',
  'vocabulary.upload.freq.dict_imported_other': '{name}: imported {count} entries.',
  'vocabulary.upload.freq.dict_vocab_created_other': '{count} vocabulary terms created.',
  'vocabulary.upload.freq.dict_skipped_other': '{count} skipped: headword too long.',
  'vocabulary.upload.freq.progress_of': '{current} of {total}',
};

async function load(lang: string, strings: Record<string, string>) {
  vi.resetModules();
  document.documentElement.lang = lang;
  const blob = document.createElement('script');
  blob.id = 'lwt-i18n';
  blob.type = 'application/json';
  blob.textContent = JSON.stringify(strings);
  document.body.appendChild(blob);
  return import('../../../src/frontend/js/modules/vocabulary/services/frequency_messages');
}

describe('modules/vocabulary/services/frequency_messages.ts', () => {
  beforeEach(() => {
    document.getElementById('lwt-i18n')?.remove();
  });

  afterEach(() => {
    document.getElementById('lwt-i18n')?.remove();
    document.documentElement.lang = '';
  });

  it('builds English messages around the counts', async () => {
    const m = await load('en', ENGLISH);

    expect(m.enrichingLabel('translation')).toBe('Fetching translations...');
    expect(m.enrichingLabel('definition')).toBe('Fetching definitions...');
    expect(m.enrichProgress(1, 1)).toBe('1 of 1 word enriched');
    expect(m.enrichProgress(12, 100)).toBe('12 of 100 words enriched');
    expect(m.enrichedResult(96, 'definition')).toBe('96 words enriched with definitions.');
    expect(m.progressOf(3, 5)).toBe('3 of 5');
  });

  it('adds the vocabulary and skipped notes only when there are any', async () => {
    const m = await load('en', ENGLISH);

    expect(m.dictionaryImported('Wiktionary', 1)).toBe('Wiktionary: imported 1 entry.');
    expect(m.dictionaryImported('Wiktionary', 1200, 4, 30)).toBe(
      'Wiktionary: imported 1200 entries. 30 vocabulary terms created. 4 skipped: headword too long.'
    );
  });

  it('lets Arabic pick its form for each count', async () => {
    const m = await load('ar', ARABIC);

    expect(m.importedResult(2, 'الفرنسية')).toBe('تم استيراد كلمتين لـالفرنسية.');
    expect(m.importedResult(5, 'الفرنسية')).toBe('تم استيراد 5 كلمات لـالفرنسية.');
    expect(m.importedResult(50, 'الفرنسية')).toBe('تم استيراد 50 كلمةً لـالفرنسية.');
    expect(m.importedResult(100, 'الفرنسية')).toBe('تم استيراد 100 كلمة لـالفرنسية.');
  });
});
