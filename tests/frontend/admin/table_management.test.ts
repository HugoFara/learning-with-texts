/**
 * Tests for table_management.ts - Table set management Alpine component
 */
import { answerConfirms, settle, trackSubmissions } from '../helpers/confirm_answers';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import {
  tableManagementApp,
  initTableManagementAlpine
} from '../../../src/frontend/js/modules/admin/pages/table_management';

describe('table_management.ts', () => {
  beforeEach(() => {
    document.body.innerHTML = '';
    vi.clearAllMocks();
  });

  afterEach(() => {
    vi.restoreAllMocks();
    document.body.innerHTML = '';
  });

  // ===========================================================================
  // tableManagementApp Tests (Alpine.js component)
  // ===========================================================================

  describe('tableManagementApp', () => {
    it('initializes with default state', () => {
      const app = tableManagementApp();

      expect(app.selectedPrefix).toBe('-');
      expect(app.newPrefix).toBe('');
      expect(app.createError).toBeNull();
      expect(app.deletePrefix).toBe('-');
      expect(app.confirmDelete).toBe(false);
    });

    describe('validatePrefix', () => {
      it('returns true for valid prefix', () => {
        const app = tableManagementApp();
        app.newPrefix = 'valid_prefix';

        expect(app.validatePrefix()).toBe(true);
        expect(app.createError).toBeNull();
      });

      it('returns false for empty prefix', () => {
        const app = tableManagementApp();
        app.newPrefix = '';

        expect(app.validatePrefix()).toBe(false);
        expect(app.createError).toBe('Table Set Name must not be empty');
      });

      it('returns false for invalid characters', () => {
        const app = tableManagementApp();
        app.newPrefix = 'test-prefix';

        expect(app.validatePrefix()).toBe(false);
        expect(app.createError).toBe('Only letters, numbers, and underscores allowed');
      });

      it('returns false for prefix exceeding 20 characters', () => {
        const app = tableManagementApp();
        app.newPrefix = 'this_is_a_very_long_prefix_name';

        expect(app.validatePrefix()).toBe(false);
        expect(app.createError).toBe('Maximum 20 characters');
      });

      it('returns true for valid alphanumeric prefix', () => {
        const app = tableManagementApp();
        app.newPrefix = 'test_prefix_123';

        expect(app.validatePrefix()).toBe(true);
        expect(app.createError).toBeNull();
      });

      it('returns true for exactly 20 characters', () => {
        const app = tableManagementApp();
        app.newPrefix = '12345678901234567890';

        expect(app.validatePrefix()).toBe(true);
        expect(app.createError).toBeNull();
      });
    });

    describe('submitCreate', () => {
      it('prevents submission when validation fails', () => {
        const app = tableManagementApp();
        app.newPrefix = '';

        const event = new Event('submit', { cancelable: true });
        app.submitCreate(event);

        expect(event.defaultPrevented).toBe(true);
      });

      it('allows submission when validation passes', () => {
        const app = tableManagementApp();
        app.newPrefix = 'valid_prefix';

        const event = new Event('submit', { cancelable: true });
        app.submitCreate(event);

        expect(event.defaultPrevented).toBe(false);
      });
    });

    describe('submitDelete', () => {
      it('prevents submission when no prefix selected', () => {
        const app = tableManagementApp();
        app.deletePrefix = '-';
        app.confirmDelete = true;

        const event = new Event('submit', { cancelable: true });
        app.submitDelete(event);

        expect(event.defaultPrevented).toBe(true);
      });

      it('prevents submission when confirmDelete is false', () => {
        const app = tableManagementApp();
        app.deletePrefix = 'test_prefix';
        app.confirmDelete = false;

        const event = new Event('submit', { cancelable: true });
        app.submitDelete(event);

        expect(event.defaultPrevented).toBe(true);
      });

      it('deletes only after the user confirms', async () => {
        const confirmSpy = answerConfirms(true);
        const submissions = trackSubmissions();
        document.body.innerHTML = '<form></form>';
        const form = document.querySelector('form') as HTMLFormElement;
        const app = tableManagementApp();
        app.deletePrefix = 'test_prefix';
        app.confirmDelete = true;
        form.addEventListener('submit', (event) => app.submitDelete(event));

        form.requestSubmit();

        expect(submissions).toHaveLength(0);
        await settle();
        expect(confirmSpy).toHaveBeenCalled();
        expect(submissions).toHaveLength(1);
      });

      it('prevents submission when confirmation is cancelled', async () => {
        const confirmSpy = answerConfirms(false);
        const app = tableManagementApp();
        app.deletePrefix = 'test_prefix';
        app.confirmDelete = true;

        const event = new Event('submit', { cancelable: true });
        app.submitDelete(event);

        await settle();
        expect(confirmSpy).toHaveBeenCalled();
        expect(event.defaultPrevented).toBe(true);
      });
    });
  });

  // ===========================================================================
  // initTableManagementAlpine Tests
  // ===========================================================================

  describe('initTableManagementAlpine', () => {
    it('does not throw when called', () => {
      expect(() => initTableManagementAlpine()).not.toThrow();
    });
  });

  // ===========================================================================
  // Window Exports Tests
  // ===========================================================================

  describe('window exports', () => {
    it('exports tableManagementApp to window', () => {
      expect(typeof window.tableManagementApp).toBe('function');
    });
  });
});
