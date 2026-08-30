/**
 * Tests for modules/dictionary/pages/dictionary_list.ts — create() path.
 *
 * The list previously created dictionaries through a same-origin form POST;
 * these cover the API-backed replacement.
 */
import { describe, it, expect, beforeEach, vi } from 'vitest';

vi.mock('alpinejs', () => ({
  default: { data: vi.fn() }
}));

vi.mock('../../../src/frontend/js/modules/dictionary/api/local_dictionaries_api', () => ({
  LocalDictionariesApi: {
    list: vi.fn(),
    create: vi.fn(),
    setEnabled: vi.fn(),
    remove: vi.fn()
  }
}));

import { LocalDictionariesApi } from
  '../../../src/frontend/js/modules/dictionary/api/local_dictionaries_api';
import { dictionaryListData } from
  '../../../src/frontend/js/modules/dictionary/pages/dictionary_list';

const createMock = LocalDictionariesApi.create as ReturnType<typeof vi.fn>;
const listMock = LocalDictionariesApi.list as ReturnType<typeof vi.fn>;

/** Build a dictionary row as the API returns it. */
function makeDictionary(id: number, name: string) {
  return {
    id,
    language_id: 3,
    name,
    description: null,
    source_format: 'csv',
    entry_count: 0,
    priority: 0,
    enabled: true,
    created: '2026-01-01'
  };
}

describe('dictionary_list.ts create()', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    document.body.innerHTML =
      '<script type="application/json" id="dictionary-list-config">'
      + '{"languageId":3}</script>';
  });

  it('posts the trimmed name against the rendered language', async () => {
    createMock.mockResolvedValue({
      data: { success: true, dictionary: makeDictionary(7, 'Wiktionary') }
    });

    const app = dictionaryListData();
    app.newName = '  Wiktionary  ';
    await app.create();

    expect(createMock).toHaveBeenCalledWith(3, 'Wiktionary');
  });

  it('appends the created dictionary without refetching the list', async () => {
    createMock.mockResolvedValue({
      data: { success: true, dictionary: makeDictionary(7, 'Wiktionary') }
    });

    const app = dictionaryListData();
    app.dictionaries = [makeDictionary(1, 'Existing')];
    app.newName = 'Wiktionary';
    await app.create();

    expect(app.dictionaries.map(d => d.name)).toEqual(['Existing', 'Wiktionary']);
    expect(listMock).not.toHaveBeenCalled();
    expect(app.newName).toBe('');
    expect(app.isCreating).toBe(false);
  });

  it('refetches when the endpoint omits the created row', async () => {
    createMock.mockResolvedValue({ data: { success: true } });
    listMock.mockResolvedValue({ data: { dictionaries: [makeDictionary(7, 'Wiktionary')] } });

    const app = dictionaryListData();
    app.newName = 'Wiktionary';
    await app.create();

    expect(listMock).toHaveBeenCalledWith(3);
    expect(app.dictionaries).toHaveLength(1);
  });

  it('ignores a blank name', async () => {
    const app = dictionaryListData();
    app.newName = '   ';
    await app.create();

    expect(createMock).not.toHaveBeenCalled();
  });

  it('surfaces the endpoint error and keeps the typed name', async () => {
    createMock.mockResolvedValue({
      data: { success: false, error: 'Language not found or access denied' }
    });

    const app = dictionaryListData();
    app.newName = 'Wiktionary';
    await app.create();

    expect(app.error).toBe('Language not found or access denied');
    expect(app.dictionaries).toEqual([]);
    expect(app.newName).toBe('Wiktionary');
    expect(app.isCreating).toBe(false);
  });

  it('does not submit twice while a create is in flight', async () => {
    const app = dictionaryListData();
    app.isCreating = true;
    app.newName = 'Wiktionary';
    await app.create();

    expect(createMock).not.toHaveBeenCalled();
  });
});
