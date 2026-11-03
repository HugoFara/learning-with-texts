/**
 * The streak and the activity calendar speak the interface language: the
 * streak through a plural set, the calendar axes through Intl in the
 * interface locale (not the browser's).
 */
import { describe, it, expect, afterEach, vi } from 'vitest';

vi.mock('alpinejs', () => ({ default: { data: vi.fn() } }));

function loadStrings(lang: string, strings: Record<string, string>): void {
  document.getElementById('lwt-i18n')?.remove();
  document.documentElement.lang = lang;
  const blob = document.createElement('script');
  blob.id = 'lwt-i18n';
  blob.type = 'application/json';
  blob.textContent = JSON.stringify(strings);
  document.body.appendChild(blob);
}

afterEach(() => {
  document.getElementById('lwt-i18n')?.remove();
  document.documentElement.lang = '';
  document.body.innerHTML = '';
});

describe('activity components i18n', () => {
  it('pluralizes the streak in the interface language', async () => {
    vi.resetModules();
    loadStrings('ar', {
      'user.statistics.streak_days_one': 'يوم واحد',
      'user.statistics.streak_days_two': 'يومان',
      'user.statistics.streak_days_few': '{count} أيام',
      'user.statistics.streak_days_many': '{count} يومًا',
      'user.statistics.streak_days_other': '{count} يوم',
    });
    const { streakDisplay } = await import('../../../src/frontend/js/modules/activity/components/streak_display');
    const streak = streakDisplay();

    expect(streak.streakLabel(1)).toBe('يوم واحد');
    expect(streak.streakLabel(2)).toBe('يومان');
    expect(streak.streakLabel(7)).toBe('7 أيام');
    expect(streak.streakLabel(30)).toBe('30 يومًا');
  });

  it('labels the calendar axes in the interface language', async () => {
    vi.resetModules();
    loadStrings('fr', {});
    const { calendarHeatmap } = await import('../../../src/frontend/js/modules/activity/components/calendar_heatmap');
    const container = document.createElement('div');
    document.body.appendChild(container);

    calendarHeatmap().buildCalendar(container, {});

    const labels = Array.from(container.querySelectorAll('text')).map(el => el.textContent);
    expect(labels).toEqual(expect.arrayContaining(['lun.', 'mer.', 'ven.']));
    expect(labels).not.toContain('Mon');
    expect(labels.some(label => /janv\.|févr\.|mars|avr\.|mai|juin|juil\.|août|sept\.|oct\.|nov\.|déc\./.test(label ?? ''))).toBe(true);
  });
});
