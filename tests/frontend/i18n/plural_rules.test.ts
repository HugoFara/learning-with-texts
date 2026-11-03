/**
 * Pins the frontend plural table against the fixture the PHP table is tested
 * against too, so server and browser always pick the same form (#319).
 */
import { describe, it, expect } from 'vitest';
import fixture from '../../fixtures/plural_rules.json';
import { pluralCategory } from '../../../src/frontend/js/shared/i18n/plural_rules';

const cases = Object.entries(fixture.cases as Record<string, Record<string, string>>)
  .flatMap(([locale, expectations]) =>
    Object.entries(expectations).map(([count, category]) => [locale, Number(count), category] as const)
  );

describe('shared/i18n/plural_rules.ts', () => {
  it.each(cases)('%s %i is %s', (locale, count, category) => {
    expect(pluralCategory(locale, count)).toBe(category);
  });

  it('uses the magnitude of negative counts', () => {
    expect(pluralCategory('en', -1)).toBe('one');
    expect(pluralCategory('ar', -2)).toBe('two');
  });
});
