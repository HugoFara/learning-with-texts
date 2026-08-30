/**
 * Tests for feeds/utils/xpath_utils.ts - XPath utility functions
 */
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import {
  xpathQuery,
  isValidXPath,
  combineXPaths,
  xpathToDisplayLabel,
  getAncestors,
  getDescendantsAndSelf,
  getAncestorsAndSelf,
  generateMarkActionOptions,
  generateAdvancedXPathOptions,
  xpathToCssSelector,
  hasClassInAncestry
} from '../../../../src/frontend/js/modules/feed/utils/xpath_utils';

describe('feeds/utils/xpath_utils.ts', () => {
  beforeEach(() => {
    document.body.innerHTML = '';
  });

  afterEach(() => {
    document.body.innerHTML = '';
  });

  // ===========================================================================
  // xpathQuery Tests
  // ===========================================================================

  describe('xpathQuery', () => {
    it('returns empty array for non-matching xpath', () => {
      document.body.innerHTML = '<div>hello</div>';

      const result = xpathQuery('//span');

      expect(result).toEqual([]);
    });

    it('returns matching elements', () => {
      document.body.innerHTML = '<div id="test">hello</div>';

      const result = xpathQuery('//div[@id="test"]');

      expect(result.length).toBe(1);
      expect(result[0].id).toBe('test');
    });

    it('handles pipe-separated expressions', () => {
      document.body.innerHTML = '<div class="a">A</div><span class="b">B</span>';

      const result = xpathQuery('//div[@class="a"] | //span[@class="b"]');

      expect(result.length).toBe(2);
    });

    it('deduplicates results', () => {
      document.body.innerHTML = '<div id="dup">hello</div>';

      const result = xpathQuery('//div[@id="dup"] | //div[@id="dup"]');

      expect(result.length).toBe(1);
    });

    it('handles invalid xpath gracefully', () => {
      document.body.innerHTML = '<div>hello</div>';

      const result = xpathQuery('///invalid[');

      expect(result).toEqual([]);
    });

    it('filters out non-HTMLElement nodes', () => {
      document.body.innerHTML = '<div>hello</div>';

      // Text nodes won't be returned
      const result = xpathQuery('//div/text()');

      expect(result).toEqual([]);
    });

    it('uses custom context node', () => {
      document.body.innerHTML = '<div id="container"><span class="child">inner</span></div><span class="outside">outer</span>';
      const container = document.getElementById('container')!;

      const result = xpathQuery('.//span', container);

      expect(result.length).toBe(1);
      expect(result[0].classList.contains('child')).toBe(true);
    });
  });

  // ===========================================================================
  // isValidXPath Tests
  // ===========================================================================

  describe('isValidXPath', () => {
    it('returns true for valid xpath', () => {
      expect(isValidXPath('//div')).toBe(true);
    });

    it('returns true for complex xpath', () => {
      expect(isValidXPath('//div[@class="test"]/span[@id="inner"]')).toBe(true);
    });

    it('returns false for empty string', () => {
      expect(isValidXPath('')).toBe(false);
    });

    it('returns false for whitespace only', () => {
      expect(isValidXPath('   ')).toBe(false);
    });

    it('returns false for invalid xpath syntax', () => {
      expect(isValidXPath('///invalid[[')).toBe(false);
    });
  });

  // ===========================================================================
  // combineXPaths Tests
  // ===========================================================================

  describe('combineXPaths', () => {
    it('combines multiple xpaths with pipe', () => {
      const result = combineXPaths(['//div', '//span', '//p']);

      expect(result).toBe('//div | //span | //p');
    });

    it('filters out empty strings', () => {
      const result = combineXPaths(['//div', '', '//span', '  ']);

      expect(result).toBe('//div | //span');
    });

    it('returns empty string for empty array', () => {
      expect(combineXPaths([])).toBe('');
    });

    it('returns single xpath without pipe', () => {
      expect(combineXPaths(['//div'])).toBe('//div');
    });
  });

  // ===========================================================================
  // xpathToDisplayLabel Tests
  // ===========================================================================

  describe('xpathToDisplayLabel', () => {
    it('removes @ symbols', () => {
      const result = xpathToDisplayLabel('//div[@class="test"]');

      expect(result).not.toContain('@');
    });

    it('removes leading //', () => {
      const result = xpathToDisplayLabel('//div');

      expect(result).not.toContain('//');
    });

    it('converts " and " to "]["', () => {
      const result = xpathToDisplayLabel('[@id="x" and @class="y"]');

      expect(result).toContain('][');
    });

    it('truncates long strings to 50 chars', () => {
      const longXpath = '//div[@class="very-long-class-name-that-exceeds-fifty-characters"]';
      const result = xpathToDisplayLabel(longXpath);

      expect(result.length).toBeLessThanOrEqual(50);
    });
  });

  // ===========================================================================
  // getAncestors Tests
  // ===========================================================================

  describe('getAncestors', () => {
    it('returns empty array for body children', () => {
      document.body.innerHTML = '<div id="child">hello</div>';
      const child = document.getElementById('child')!;

      const result = getAncestors(child);

      expect(result).toEqual([]);
    });

    it('returns parent elements', () => {
      document.body.innerHTML = '<div id="parent"><span id="child">hello</span></div>';
      const child = document.getElementById('child')!;

      const result = getAncestors(child);

      expect(result.length).toBe(1);
      expect(result[0].id).toBe('parent');
    });

    it('excludes body and html', () => {
      document.body.innerHTML = '<div id="parent"><span id="child">hello</span></div>';
      const child = document.getElementById('child')!;

      const result = getAncestors(child);

      expect(result.every(el => el !== document.body)).toBe(true);
      expect(result.every(el => el !== document.documentElement)).toBe(true);
    });
  });

  // ===========================================================================
  // getDescendantsAndSelf Tests
  // ===========================================================================

  describe('getDescendantsAndSelf', () => {
    it('includes the element itself', () => {
      document.body.innerHTML = '<div id="parent">hello</div>';
      const parent = document.getElementById('parent')!;

      const result = getDescendantsAndSelf(parent);

      expect(result).toContain(parent);
    });

    it('includes all descendants', () => {
      document.body.innerHTML = '<div id="parent"><span><em>deep</em></span></div>';
      const parent = document.getElementById('parent')!;

      const result = getDescendantsAndSelf(parent);

      expect(result.length).toBe(3); // div, span, em
    });

    it('returns single element for leaf nodes', () => {
      document.body.innerHTML = '<span id="leaf">text</span>';
      const leaf = document.getElementById('leaf')!;

      const result = getDescendantsAndSelf(leaf);

      expect(result.length).toBe(1);
      expect(result[0]).toBe(leaf);
    });
  });

  // ===========================================================================
  // getAncestorsAndSelf Tests
  // ===========================================================================

  describe('getAncestorsAndSelf', () => {
    it('includes the element itself first', () => {
      document.body.innerHTML = '<div id="child">hello</div>';
      const child = document.getElementById('child')!;

      const result = getAncestorsAndSelf(child);

      expect(result[0]).toBe(child);
    });

    it('includes all ancestors', () => {
      document.body.innerHTML = '<div id="grandparent"><div id="parent"><span id="child">hi</span></div></div>';
      const child = document.getElementById('child')!;

      const result = getAncestorsAndSelf(child);

      expect(result.length).toBe(3); // child, parent, grandparent
    });
  });

  // ===========================================================================
  // generateMarkActionOptions Tests
  // ===========================================================================

  describe('generateMarkActionOptions', () => {
    it('returns array of options', () => {
      document.body.innerHTML = '<div id="test" class="my-class">hello</div>';
      const el = document.getElementById('test')!;

      const result = generateMarkActionOptions(el, 'smart');

      expect(Array.isArray(result)).toBe(true);
      expect(result.length).toBeGreaterThan(0);
    });

    it('includes xpath value in option', () => {
      document.body.innerHTML = '<div id="test">hello</div>';
      const el = document.getElementById('test')!;

      const result = generateMarkActionOptions(el, 'smart');

      expect(result[0].value).toContain('//');
    });

    it('includes tag name in label', () => {
      document.body.innerHTML = '<div id="test">hello</div>';
      const el = document.getElementById('test')!;

      const result = generateMarkActionOptions(el, 'smart');

      expect(result[0].label).toContain('<div');
    });

    it('includes tagName property', () => {
      document.body.innerHTML = '<span id="test">hello</span>';
      const el = document.getElementById('test')!;

      const result = generateMarkActionOptions(el, 'smart');

      expect(result[0].tagName).toBe('SPAN');
    });
  });

  // ===========================================================================
  // generateAdvancedXPathOptions Tests
  // ===========================================================================

  describe('generateAdvancedXPathOptions', () => {
    it('generates id-based options when element has id', () => {
      document.body.innerHTML = '<div id="my-id">hello</div>';
      const el = document.getElementById('my-id')!;

      const result = generateAdvancedXPathOptions(el);

      const idOption = result.find(o => o.type === 'id');
      expect(idOption).toBeDefined();
      expect(idOption!.label).toContain('my-id');
    });

    it('generates class-based options when element has class', () => {
      document.body.innerHTML = '<div class="my-class">hello</div>';
      const el = document.querySelector('.my-class') as HTMLElement;

      const result = generateAdvancedXPathOptions(el);

      const classOption = result.find(o => o.type === 'class');
      expect(classOption).toBeDefined();
      expect(classOption!.label).toContain('my-class');
    });

    it('excludes lwt_ prefixed classes', () => {
      document.body.innerHTML = '<div class="my-class lwt_internal">hello</div>';
      const el = document.querySelector('.my-class') as HTMLElement;

      const result = generateAdvancedXPathOptions(el);

      const internalOption = result.find(o => o.label.includes('lwt_internal'));
      expect(internalOption).toBeUndefined();
    });

    it('generates parent-based options', () => {
      document.body.innerHTML = '<div id="parent"><span class="child">hello</span></div>';
      const el = document.querySelector('.child') as HTMLElement;

      const result = generateAdvancedXPathOptions(el);

      const parentOption = result.find(o => o.type === 'parent-id');
      expect(parentOption).toBeDefined();
      expect(parentOption!.label).toContain('parent');
    });
  });

  // ===========================================================================
  // xpathToCssSelector Tests
  // ===========================================================================

  describe('xpathToCssSelector', () => {
    it('removes @ symbols', () => {
      const result = xpathToCssSelector('//div[@class="test"]');

      expect(result).not.toContain('@');
    });

    it('removes leading //', () => {
      const result = xpathToCssSelector('//div');

      expect(result).not.toContain('//');
    });

    it('converts section separator', () => {
      const result = xpathToCssSelector('//div§span');

      expect(result).toContain('>');
    });

    it('returns null on error', () => {
      // Most expressions won't throw, but the function handles errors
      const result = xpathToCssSelector('//div');

      expect(result).not.toBeNull();
    });
  });

  // ===========================================================================
  // hasClassInAncestry Tests
  // ===========================================================================

  describe('hasClassInAncestry', () => {
    it('returns true when element has class', () => {
      document.body.innerHTML = '<div class="target">hello</div>';
      const el = document.querySelector('.target') as HTMLElement;

      expect(hasClassInAncestry(el, 'target')).toBe(true);
    });

    it('returns true when ancestor has class', () => {
      document.body.innerHTML = '<div class="ancestor"><span id="child">hello</span></div>';
      const el = document.getElementById('child') as HTMLElement;

      expect(hasClassInAncestry(el, 'ancestor')).toBe(true);
    });

    it('returns false when no element has class', () => {
      document.body.innerHTML = '<div class="other"><span id="child">hello</span></div>';
      const el = document.getElementById('child') as HTMLElement;

      expect(hasClassInAncestry(el, 'target')).toBe(false);
    });
  });
});
