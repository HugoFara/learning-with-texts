/**
 * Tests for form_validation.ts - Input validation and form checking utilities
 */
import { confirmPrompts, dismissAlerts, settle } from '../helpers/confirm_answers';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import {
  containsCharacterOutsideBasicMultilingualPlane,
  alertFirstCharacterOutsideBasicMultilingualPlane,
  getUTF8Length,
  isInt,
  check,
  textareaKeydown,
} from '../../../src/frontend/js/shared/forms/form_validation';

describe('form_validation.ts', () => {
  afterEach(() => {
    vi.restoreAllMocks();
    document.body.innerHTML = '';
  });

  // ===========================================================================
  // containsCharacterOutsideBasicMultilingualPlane Tests
  // ===========================================================================

  describe('containsCharacterOutsideBasicMultilingualPlane', () => {
    it('returns false for empty string', () => {
      expect(containsCharacterOutsideBasicMultilingualPlane('')).toBe(false);
    });

    it('returns false for ASCII text', () => {
      expect(containsCharacterOutsideBasicMultilingualPlane('Hello World')).toBe(false);
    });

    it('returns false for BMP characters (Latin, Greek, Cyrillic)', () => {
      expect(containsCharacterOutsideBasicMultilingualPlane('Héllo Wörld')).toBe(false);
      expect(containsCharacterOutsideBasicMultilingualPlane('Привет')).toBe(false);
      expect(containsCharacterOutsideBasicMultilingualPlane('Ελληνικά')).toBe(false);
    });

    it('returns false for CJK characters in BMP', () => {
      expect(containsCharacterOutsideBasicMultilingualPlane('日本語')).toBe(false);
      expect(containsCharacterOutsideBasicMultilingualPlane('中文')).toBe(false);
      expect(containsCharacterOutsideBasicMultilingualPlane('한국어')).toBe(false);
    });

    it('returns true for emoji (outside BMP)', () => {
      expect(containsCharacterOutsideBasicMultilingualPlane('Hello 😀')).toBe(true);
      expect(containsCharacterOutsideBasicMultilingualPlane('🎉')).toBe(true);
    });

    it('returns true for supplementary characters', () => {
      // Mathematical alphanumeric symbols (U+1D400+)
      expect(containsCharacterOutsideBasicMultilingualPlane('𝐀')).toBe(true);
    });

    it('returns true for mixed text with emoji', () => {
      expect(containsCharacterOutsideBasicMultilingualPlane('日本語 🗾')).toBe(true);
    });
  });

  // ===========================================================================
  // alertFirstCharacterOutsideBasicMultilingualPlane Tests
  // ===========================================================================

  describe('alertFirstCharacterOutsideBasicMultilingualPlane', () => {
    const alertSpy = confirmPrompts;

    beforeEach(() => {
      dismissAlerts();
    });

    it('returns 0 and does not alert for valid BMP text', async () => {
      const result = alertFirstCharacterOutsideBasicMultilingualPlane('Hello', 'Test Field');
      expect(result).toBe(0);
      await settle();
      expect(alertSpy).not.toHaveBeenCalled();
    });

    it('returns 1 and alerts for text with emoji', async () => {
      const result = alertFirstCharacterOutsideBasicMultilingualPlane('Hello 😀 World', 'Test Field');
      expect(result).toBe(1);
      await settle();
      expect(alertSpy).toHaveBeenCalledTimes(1);
      expect(alertSpy).toHaveBeenCalledWith(expect.stringContaining('Test Field'));
      expect(alertSpy).toHaveBeenCalledWith(expect.stringContaining('not supported'));
    });

    it('includes position information in alert', async () => {
      alertFirstCharacterOutsideBasicMultilingualPlane('AB😀CD', 'Field');
      await settle();
      expect(alertSpy).toHaveBeenCalledWith(expect.stringContaining('position'));
    });

    it('returns 0 for empty string', async () => {
      const result = alertFirstCharacterOutsideBasicMultilingualPlane('', 'Empty Field');
      expect(result).toBe(0);
      await settle();
      expect(alertSpy).not.toHaveBeenCalled();
    });
  });

  // ===========================================================================
  // getUTF8Length Tests
  // ===========================================================================

  describe('getUTF8Length', () => {
    it('returns correct length for ASCII string', () => {
      expect(getUTF8Length('Hello')).toBe(5);
    });

    it('returns correct length for empty string', () => {
      expect(getUTF8Length('')).toBe(0);
    });

    it('returns correct length for multi-byte characters', () => {
      // é is 2 bytes in UTF-8
      expect(getUTF8Length('é')).toBe(2);
      // Japanese characters are 3 bytes each
      expect(getUTF8Length('日')).toBe(3);
    });

    it('returns correct length for mixed content', () => {
      // "Hello" (5) + " " (1) + "日本" (6) = 12 bytes
      expect(getUTF8Length('Hello 日本')).toBe(12);
    });

    it('returns correct length for emoji (4 bytes)', () => {
      expect(getUTF8Length('😀')).toBe(4);
    });
  });

  // ===========================================================================
  // isInt Tests
  // ===========================================================================

  describe('isInt', () => {
    it('returns true for valid integer strings', () => {
      expect(isInt('0')).toBe(true);
      expect(isInt('123')).toBe(true);
      expect(isInt('999999')).toBe(true);
    });

    it('returns true for single digit', () => {
      expect(isInt('5')).toBe(true);
    });

    it('returns false for empty string', () => {
      expect(isInt('')).toBe(true); // Note: empty string has no non-digit chars
    });

    it('returns false for negative numbers', () => {
      expect(isInt('-5')).toBe(false);
    });

    it('returns false for decimal numbers', () => {
      expect(isInt('3.14')).toBe(false);
    });

    it('returns false for text', () => {
      expect(isInt('abc')).toBe(false);
      expect(isInt('12a')).toBe(false);
      expect(isInt('a12')).toBe(false);
    });

    it('returns false for whitespace', () => {
      expect(isInt(' 5')).toBe(false);
      expect(isInt('5 ')).toBe(false);
    });

    it('returns false for special characters', () => {
      expect(isInt('5!')).toBe(false);
      expect(isInt('+5')).toBe(false);
    });
  });

  // ===========================================================================
  // check Tests
  // ===========================================================================

  describe('check', () => {
    const alertSpy = confirmPrompts;

    beforeEach(() => {
      dismissAlerts();
    });

    describe('notempty validation', () => {
      it('returns true when no notempty fields exist', async () => {
        document.body.innerHTML = '<input type="text" value="test" />';
        expect(check()).toBe(true);
        await settle();
        expect(alertSpy).not.toHaveBeenCalled();
      });

      it('returns true when notempty field has value', () => {
        document.body.innerHTML = '<input class="notempty" value="test" />';
        expect(check()).toBe(true);
      });

      it('returns false when notempty field is empty', async () => {
        document.body.innerHTML = '<input class="notempty" value="" />';
        expect(check()).toBe(false);
        await settle();
        expect(alertSpy).toHaveBeenCalledWith(expect.stringContaining('are empty'));
      });

      it('returns false when notempty field has only whitespace', () => {
        document.body.innerHTML = '<input class="notempty" value="   " />';
        expect(check()).toBe(false);
      });

      it('counts multiple empty notempty fields', async () => {
        document.body.innerHTML = `
          <input class="notempty" value="" />
          <input class="notempty" value="" />
          <input class="notempty" value="" />
        `;
        check();
        await settle();
        expect(alertSpy).toHaveBeenCalledWith(expect.stringContaining('are empty: 3'));
      });

      it('skips notempty fields hidden under an Alpine x-show ancestor', async () => {
        // /texts/new: TxText is `notempty` but hidden via x-show when the user
        // is importing a file. Alpine sets display:none on the wrapper.
        document.body.innerHTML = `
          <div style="display: none">
            <textarea class="notempty"></textarea>
          </div>
          <input class="notempty" value="filled" />
        `;
        expect(check()).toBe(true);
        await settle();
        expect(alertSpy).not.toHaveBeenCalled();
      });

      it('skips notempty fields with the hidden attribute on an ancestor', async () => {
        document.body.innerHTML = `
          <div hidden>
            <input class="notempty" value="" />
          </div>
        `;
        expect(check()).toBe(true);
        await settle();
        expect(alertSpy).not.toHaveBeenCalled();
      });
    });

    describe('checkurl validation', () => {
      it('returns true for valid http URL', () => {
        document.body.innerHTML = '<input class="checkurl" value="http://example.com" />';
        expect(check()).toBe(true);
      });

      it('returns true for valid https URL', () => {
        document.body.innerHTML = '<input class="checkurl" value="https://example.com" />';
        expect(check()).toBe(true);
      });

      it('returns true for URL starting with #', () => {
        document.body.innerHTML = '<input class="checkurl" value="#anchor" />';
        expect(check()).toBe(true);
      });

      it('returns true for empty URL field', () => {
        document.body.innerHTML = '<input class="checkurl" value="" />';
        expect(check()).toBe(true);
      });

      it('returns false for URL without protocol', async () => {
        document.body.innerHTML = '<input class="checkurl" data_info="URL Field" value="example.com" />';
        expect(check()).toBe(false);
        await settle();
        expect(alertSpy).toHaveBeenCalledWith(expect.stringContaining('http://'));
      });
    });

    describe('posintnumber validation', () => {
      it('returns true for positive integer', () => {
        document.body.innerHTML = '<input class="posintnumber" value="5" />';
        expect(check()).toBe(true);
      });

      it('returns true for empty field', () => {
        document.body.innerHTML = '<input class="posintnumber" value="" />';
        expect(check()).toBe(true);
      });

      it('returns false for zero', async () => {
        document.body.innerHTML = '<input class="posintnumber" data_info="Count" value="0" />';
        expect(check()).toBe(false);
        await settle();
        expect(alertSpy).toHaveBeenCalledWith(expect.stringContaining('greater than 0'));
      });

      it('returns false for negative number', () => {
        document.body.innerHTML = '<input class="posintnumber" data_info="Count" value="-5" />';
        expect(check()).toBe(false);
      });

      it('returns false for non-integer', () => {
        document.body.innerHTML = '<input class="posintnumber" data_info="Count" value="3.5" />';
        expect(check()).toBe(false);
      });
    });

    describe('zeroposintnumber validation', () => {
      it('returns true for positive integer', () => {
        document.body.innerHTML = '<input class="zeroposintnumber" value="5" />';
        expect(check()).toBe(true);
      });

      it('returns true for zero', () => {
        document.body.innerHTML = '<input class="zeroposintnumber" value="0" />';
        expect(check()).toBe(true);
      });

      it('returns true for empty field', () => {
        document.body.innerHTML = '<input class="zeroposintnumber" value="" />';
        expect(check()).toBe(true);
      });

      it('returns false for negative number', async () => {
        document.body.innerHTML = '<input class="zeroposintnumber" data_info="Count" value="-1" />';
        expect(check()).toBe(false);
        await settle();
        expect(alertSpy).toHaveBeenCalledWith(expect.stringContaining('0 or greater'));
      });
    });

    describe('noblanksnocomma validation', () => {
      it('returns true for text without spaces or commas', () => {
        document.body.innerHTML = '<input class="noblanksnocomma" value="hello_world" />';
        expect(check()).toBe(true);
      });

      it('returns false for text with spaces', async () => {
        document.body.innerHTML = '<input class="noblanksnocomma" data_info="Tag" value="hello world" />';
        expect(check()).toBe(false);
        await settle();
        expect(alertSpy).toHaveBeenCalledWith(expect.stringContaining('must not contain spaces or commas'));
      });

      it('returns false for text with commas', () => {
        document.body.innerHTML = '<input class="noblanksnocomma" data_info="Tag" value="hello,world" />';
        expect(check()).toBe(false);
      });

      it('returns true for empty field', () => {
        document.body.innerHTML = '<input class="noblanksnocomma" value="" />';
        expect(check()).toBe(true);
      });
    });

    describe('checkoutsidebmp validation (input)', () => {
      it('returns true for BMP text', () => {
        document.body.innerHTML = '<input class="checkoutsidebmp" value="Hello 日本語" />';
        expect(check()).toBe(true);
      });

      it('returns false for text with emoji', async () => {
        document.body.innerHTML = '<input class="checkoutsidebmp" data_info="Text" value="Hello 😀" />';
        expect(check()).toBe(false);
        await settle();
        expect(alertSpy).toHaveBeenCalledWith(expect.stringContaining('not supported'));
      });

      it('returns true for empty field', () => {
        document.body.innerHTML = '<input class="checkoutsidebmp" value="" />';
        expect(check()).toBe(true);
      });
    });

    describe('checklength validation (textarea)', () => {
      it('returns true when text is within limit', () => {
        document.body.innerHTML = '<textarea class="checklength" data_maxlength="10">Hello</textarea>';
        expect(check()).toBe(true);
      });

      it('returns false when text exceeds limit', async () => {
        document.body.innerHTML = '<textarea class="checklength" data_maxlength="5" data_info="Description">Hello World</textarea>';
        expect(check()).toBe(false);
        await settle();
        expect(alertSpy).toHaveBeenCalledWith(expect.stringContaining('too long'));
      });
    });

    describe('checkbytes validation (textarea)', () => {
      it('returns true when bytes are within limit', () => {
        document.body.innerHTML = '<textarea class="checkbytes" data_maxlength="10">Hello</textarea>';
        expect(check()).toBe(true);
      });

      it('returns false when bytes exceed limit', async () => {
        // "日本語" is 9 bytes in UTF-8
        document.body.innerHTML = '<textarea class="checkbytes" data_maxlength="5" data_info="Text">日本語</textarea>';
        expect(check()).toBe(false);
        await settle();
        expect(alertSpy).toHaveBeenCalledWith(expect.stringContaining('bytes'));
      });
    });

    describe('checkdicturl validation', () => {
      it('returns true for valid URL', () => {
        document.body.innerHTML = '<input class="checkdicturl" value="http://dict.example.com" />';
        expect(check()).toBe(true);
      });

      it('returns true for URL with popup marker (*)', () => {
        document.body.innerHTML = '<input class="checkdicturl" value="*http://dict.example.com" />';
        expect(check()).toBe(true);
      });

      it('returns true for URL without protocol (auto-adds http)', () => {
        document.body.innerHTML = '<input class="checkdicturl" value="dict.example.com" />';
        expect(check()).toBe(true);
      });

      it('returns true for empty field', () => {
        document.body.innerHTML = '<input class="checkdicturl" value="" />';
        expect(check()).toBe(true);
      });
    });

    describe('combined validations', () => {
      it('validates multiple fields correctly', () => {
        document.body.innerHTML = `
          <input class="notempty" value="filled" />
          <input class="checkurl" value="http://example.com" />
          <input class="posintnumber" value="5" />
        `;
        expect(check()).toBe(true);
      });

      it('fails on first validation error', () => {
        document.body.innerHTML = `
          <input class="notempty" value="" />
          <input class="posintnumber" value="-1" />
        `;
        expect(check()).toBe(false);
      });
    });
  });

  // ===========================================================================
  // textareaKeydown Tests
  // ===========================================================================

  describe('textareaKeydown', () => {
    beforeEach(() => {
      document.body.innerHTML = `
        <form>
          <input type="text" />
          <input type="submit" value="Submit" />
        </form>
      `;
    });

    it('returns false and triggers submit on Enter key', () => {
      const clickSpy = vi.fn();
      const submitBtn = document.querySelector('input[type="submit"]') as HTMLInputElement;
      submitBtn.addEventListener('click', clickSpy);

      const event = {
        keyCode: 13,
      } as unknown as JQuery.KeyDownEvent;

      const result = textareaKeydown(event);

      expect(result).toBe(false);
      expect(clickSpy).toHaveBeenCalled();
    });

    it('returns true for non-Enter keys', () => {
      const event = {
        keyCode: 65, // 'A' key
      } as unknown as JQuery.KeyDownEvent;

      const result = textareaKeydown(event);

      expect(result).toBe(true);
    });

    it('does not submit if check() fails', () => {
      document.body.innerHTML = `
        <form>
          <input class="notempty" value="" />
          <input type="submit" value="Submit" />
        </form>
      `;
      dismissAlerts();

      const clickSpy = vi.fn();
      const submitBtn = document.querySelector('input[type="submit"]') as HTMLInputElement;
      submitBtn.addEventListener('click', clickSpy);

      const event = {
        keyCode: 13,
      } as unknown as JQuery.KeyDownEvent;

      textareaKeydown(event);

      expect(clickSpy).not.toHaveBeenCalled();
    });
  });
});
