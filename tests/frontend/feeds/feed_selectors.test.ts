/**
 * Tests for feed_selectors.ts - reading and writing a feed's XPath lists.
 */
import { describe, it, expect } from 'vitest';

import {
  splitTags,
  parseSectionTags,
  buildSectionTags
} from '../../../src/frontend/js/modules/feed/utils/feed_selectors';

describe('feed_selectors.ts', () => {
  describe('splitTags', () => {
    it('splits on pipes and trims', () => {
      expect(splitTags('//article | //main')).toEqual(['//article', '//main']);
    });

    it('accepts the legacy !?! separator', () => {
      expect(splitTags('//article!?!//main')).toEqual(['//article', '//main']);
    });

    it('drops empty entries', () => {
      expect(splitTags(' | //article |  | ')).toEqual(['//article']);
    });

    it('answers with nothing for an empty list', () => {
      expect(splitTags('')).toEqual([]);
    });
  });

  describe('parseSectionTags', () => {
    it('separates the redirect hop from the selectors', () => {
      const parsed = parseSectionTags('redirect://a/@href | //article | //main');

      expect(parsed.redirect).toBe('redirect://a/@href');
      expect(parsed.selectors).toEqual(['//article', '//main']);
    });

    it('reports no hop when the links are direct', () => {
      const parsed = parseSectionTags('//article');

      expect(parsed.redirect).toBe('');
      expect(parsed.selectors).toEqual(['//article']);
    });
  });

  describe('buildSectionTags', () => {
    it('puts the hop back at the head of the list', () => {
      expect(buildSectionTags('redirect://a/@href', ['//article']))
        .toBe('redirect://a/@href | //article');
    });

    it('omits the hop when there is none', () => {
      expect(buildSectionTags('', ['//article', '//main'])).toBe('//article | //main');
    });

    it('round-trips what it parsed', () => {
      const raw = 'redirect://a/@href | //article | //main';

      const parsed = parseSectionTags(raw);
      expect(buildSectionTags(parsed.redirect, parsed.selectors)).toBe(raw);
    });
  });
});
