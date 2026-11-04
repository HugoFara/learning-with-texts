/**
 * Tests for preferences_app.ts - saving a new interface language
 */
import { describe, it, expect, afterEach, beforeEach, vi } from 'vitest';
import { preferencesAppData } from '../../../src/frontend/js/modules/user/pages/preferences_app';
import { ProfileApi } from '../../../src/frontend/js/modules/user/api/profile_api';

describe('preferences_app.ts', () => {
  const reload = vi.fn();

  beforeEach(() => {
    vi.stubGlobal('location', { ...window.location, reload });
    vi.spyOn(ProfileApi, 'getPreferences').mockResolvedValue({
      data: { settings: { app_language: 'en', 'set-tts': '0' } }
    } as never);
  });

  afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
    reload.mockClear();
  });

  async function loaded(): Promise<ReturnType<typeof preferencesAppData>> {
    const app = preferencesAppData();
    await app.load();
    return app;
  }

  function saveAnswers(data: unknown): void {
    vi.spyOn(ProfileApi, 'savePreferences').mockResolvedValue(data as never);
  }

  it('reloads after a new interface language is saved', async () => {
    saveAnswers({ data: { success: true } });
    const app = await loaded();
    app.setValue('app_language', 'de');

    await app.save();

    expect(reload).toHaveBeenCalledTimes(1);
  });

  it('stays on the page when the interface language is unchanged', async () => {
    saveAnswers({ data: { success: true } });
    const app = await loaded();
    app.setValue('set-tts', '1');

    await app.save();

    expect(reload).not.toHaveBeenCalled();
    expect(app.success).not.toBe('');
  });

  it('does not reload when the save fails', async () => {
    saveAnswers({ error: 'nope' });
    const app = await loaded();
    app.setValue('app_language', 'de');

    await app.save();

    expect(reload).not.toHaveBeenCalled();
    expect(app.error).toBe('nope');
  });
});
