/**
 * Tests for review_view.ts - Client-side rendered review interface
 */
import { describe, it, expect, beforeEach, afterEach, vi, type Mock } from 'vitest';

// Mock Alpine.js
vi.mock('alpinejs', () => ({
  default: {
    data: vi.fn(),
    initTree: vi.fn()
  }
}));

// Create shared mock store
const mockReviewStore = {
  title: 'Test Review',
  reviewType: 1,
  isTableMode: false,
  readAloudEnabled: false,
  currentWord: null,
  isLoading: false,
  isInitialized: false,
  error: null,
  isFinished: false,
  tomorrowCount: 5,
  progress: { remaining: 10, total: 20, wrong: 2, correct: 8 },
  timer: { elapsed: '00:00' },
  langSettings: { textSize: 100, rtl: false, langCode: 'en' },
  langId: 1,
  answerRevealed: false,
  isModalOpen: false,
  reviewKey: 'test-key',
  selection: {},
  configure: vi.fn(),
  nextWord: vi.fn(),
  revealAnswer: vi.fn(),
  incrementStatus: vi.fn(),
  decrementStatus: vi.fn(),
  updateStatus: vi.fn(),
  gradeAnswer: vi.fn(),
  intervals: {} as Record<string, number>,
  skipWord: vi.fn(),
  setReadAloud: vi.fn(),
  openModal: vi.fn(),
  closeModal: vi.fn(),
  getDictUrl: vi.fn(() => 'http://dict.test'),
  editWord: vi.fn(),
  editCurrentWord: vi.fn()
};

// Mock review_store
vi.mock('../../../src/frontend/js/modules/review/stores/review_store', () => ({
  getReviewStore: vi.fn(() => mockReviewStore),
  initReviewStore: vi.fn()
}));

// Mock review_api
vi.mock('../../../src/frontend/js/modules/review/api/review_api', () => ({
  ReviewApi: {
    getTableWords: vi.fn().mockResolvedValue({ data: { words: [], langSettings: null } }),
    updateStatus: vi.fn().mockResolvedValue({ data: { status: 1 } })
  }
}));

// Mock user_interactions
vi.mock('../../../src/frontend/js/shared/utils/user_interactions', () => ({
  speechDispatcher: vi.fn()
}));

// Mock ajax_utilities
vi.mock('../../../src/frontend/js/shared/utils/ajax_utilities', () => ({
  saveSetting: vi.fn()
}));

import Alpine from 'alpinejs';
import { renderReviewApp, initReviewApp } from '../../../src/frontend/js/modules/review/components/review_view';
import { getReviewStore, initReviewStore } from '../../../src/frontend/js/modules/review/stores/review_store';
import { ReviewApi } from '../../../src/frontend/js/modules/review/api/review_api';
import { saveSetting } from '../../../src/frontend/js/shared/utils/ajax_utilities';

describe('review_view.ts', () => {
  beforeEach(() => {
    document.body.innerHTML = '';
    vi.clearAllMocks();
    localStorage.clear();

    // Reset mock store functions
    mockReviewStore.configure.mockClear();
    mockReviewStore.nextWord.mockClear();
    mockReviewStore.revealAnswer.mockClear();
    mockReviewStore.incrementStatus.mockClear();
    mockReviewStore.decrementStatus.mockClear();
    mockReviewStore.updateStatus.mockClear();
    mockReviewStore.gradeAnswer.mockClear();
    mockReviewStore.skipWord.mockClear();
    mockReviewStore.setReadAloud.mockClear();
    mockReviewStore.openModal.mockClear();
    mockReviewStore.closeModal.mockClear();
  });

  afterEach(() => {
    vi.restoreAllMocks();
    document.body.innerHTML = '';
    localStorage.clear();
  });

  // ===========================================================================
  // renderReviewApp Tests
  // ===========================================================================

  describe('renderReviewApp', () => {
    it('renders review app HTML into container', () => {
      const container = document.createElement('div');

      renderReviewApp(container);

      expect(container.innerHTML).toContain('x-data="reviewApp"');
      expect(container.innerHTML).toContain('review-page');
    });

    it('calls Alpine.initTree on container', () => {
      const container = document.createElement('div');

      renderReviewApp(container);

      expect(Alpine.initTree).toHaveBeenCalledWith(container);
    });

    it('includes review toolbar', () => {
      const container = document.createElement('div');

      renderReviewApp(container);

      expect(container.innerHTML).toContain('Sentence → Translation');
      expect(container.innerHTML).toContain('Table');
    });

    it('includes progress bar section', () => {
      const container = document.createElement('div');

      renderReviewApp(container);

      expect(container.innerHTML).toContain('review-progress');
      expect(container.innerHTML).toContain('Time:');
    });

    it('includes word review area', () => {
      const container = document.createElement('div');

      renderReviewApp(container);

      expect(container.innerHTML).toContain('review-word-area');
      expect(container.innerHTML).toContain('Show Answer (Space)');
    });

    it('includes status buttons', () => {
      const container = document.createElement('div');

      renderReviewApp(container);

      // Check for status buttons 1-5
      expect(container.innerHTML).toContain('setStatus(1)');
      expect(container.innerHTML).toContain('setStatus(5)');
      expect(container.innerHTML).toContain('Ignore');
      expect(container.innerHTML).toContain('Well Known');
    });

    it('includes word modal', () => {
      const container = document.createElement('div');

      renderReviewApp(container);

      expect(container.innerHTML).toContain('modal-card');
      expect(container.innerHTML).toContain('Word Details');
      expect(container.innerHTML).toContain('Dictionary 1');
      expect(container.innerHTML).toContain('Dictionary 2');
    });

    it('includes table review section', () => {
      const container = document.createElement('div');

      renderReviewApp(container);

      expect(container.innerHTML).toContain('tableReview');
      expect(container.innerHTML).toContain('columns.edit');
      expect(container.innerHTML).toContain('columns.status');
    });

    it('includes CSS styles', () => {
      const container = document.createElement('div');

      renderReviewApp(container);

      expect(container.innerHTML).toContain('<style>');
      expect(container.innerHTML).toContain('.review-page');
      expect(container.innerHTML).toContain('.status-btn');
    });
  });

  // ===========================================================================
  // initReviewApp Tests
  // ===========================================================================

  describe('initReviewApp', () => {
    it('does nothing if container not found', () => {
      document.body.innerHTML = '';

      expect(() => initReviewApp()).not.toThrow();
      expect(Alpine.data).not.toHaveBeenCalled();
    });

    it('does nothing if config element not found', () => {
      document.body.innerHTML = '<div id="review-app"></div>';

      expect(() => initReviewApp()).not.toThrow();
      expect(Alpine.data).not.toHaveBeenCalled();
    });

    it('shows error if config has error', () => {
      document.body.innerHTML = `
        <div id="review-app"></div>
        <script type="application/json" id="review-config">
          {"error": "No words to review"}
        </script>
      `;

      initReviewApp();

      const container = document.getElementById('review-app')!;
      expect(container.innerHTML).toContain('No words to review');
      expect(container.innerHTML).toContain('is-danger');
    });

    it('registers reviewApp Alpine component', () => {
      document.body.innerHTML = `
        <div id="review-app"></div>
        <script type="application/json" id="review-config">
          {"reviewType": 1, "isTableMode": false}
        </script>
      `;

      initReviewApp();

      expect(Alpine.data).toHaveBeenCalledWith('reviewApp', expect.any(Function));
    });

    it('registers tableReview Alpine component', () => {
      document.body.innerHTML = `
        <div id="review-app"></div>
        <script type="application/json" id="review-config">
          {"reviewType": 1, "isTableMode": false}
        </script>
      `;

      initReviewApp();

      expect(Alpine.data).toHaveBeenCalledWith('tableReview', expect.any(Function));
    });

    it('initializes review store with config', () => {
      document.body.innerHTML = `
        <div id="review-app"></div>
        <script type="application/json" id="review-config">
          {"reviewType": 3, "isTableMode": true}
        </script>
      `;

      initReviewApp();

      expect(initReviewStore).toHaveBeenCalledWith({
        reviewType: 3,
        isTableMode: true
      });
    });

    it('handles JSON parse errors gracefully', () => {
      const consoleSpy = vi.spyOn(console, 'error').mockImplementation(() => {});

      document.body.innerHTML = `
        <div id="review-app"></div>
        <script type="application/json" id="review-config">
          {invalid json}
        </script>
      `;

      initReviewApp();

      const container = document.getElementById('review-app')!;
      expect(container.innerHTML).toContain('Failed to initialize');
      expect(consoleSpy).toHaveBeenCalled();
    });
  });

  // ===========================================================================
  // reviewApp Component Tests
  // ===========================================================================

  describe('reviewApp component', () => {
    function getReviewAppComponent(): () => Record<string, unknown> {
      document.body.innerHTML = `
        <div id="review-app"></div>
        <script type="application/json" id="review-config">
          {"reviewType": 1, "isTableMode": false}
        </script>
      `;

      initReviewApp();

      const calls = (Alpine.data as Mock).mock.calls;
      const reviewAppCall = calls.find((c: unknown[]) => c[0] === 'reviewApp');
      return reviewAppCall ? reviewAppCall[1] : () => ({});
    }

    it('has navbarOpen state', () => {
      const componentFactory = getReviewAppComponent();
      const component = componentFactory();

      expect(component.navbarOpen).toBe(false);
    });

    it('gets store from getReviewStore', () => {
      const componentFactory = getReviewAppComponent();
      const component = componentFactory();

      expect(component.store).toBeDefined();
    });

    it('computes progressPercent correctly', () => {
      const componentFactory = getReviewAppComponent();
      const component = componentFactory() as {
        store: ReturnType<typeof getReviewStore>;
        progressPercent: { remaining: number; wrong: number; correct: number };
      };

      const progress = component.progressPercent;
      expect(progress.remaining).toBe(50); // 10/20 * 100
      expect(progress.wrong).toBe(10);     // 2/20 * 100
      expect(progress.correct).toBe(40);   // 8/20 * 100
    });

    it('revealAnswer calls store.revealAnswer', () => {
      const componentFactory = getReviewAppComponent();
      const component = componentFactory() as {
        store: ReturnType<typeof getReviewStore>;
        revealAnswer: () => void;
      };

      component.revealAnswer();

      expect(mockReviewStore.revealAnswer).toHaveBeenCalled();
    });

    it('incrementStatus calls store.incrementStatus', async () => {
      const componentFactory = getReviewAppComponent();
      const component = componentFactory() as {
        store: ReturnType<typeof getReviewStore>;
        incrementStatus: () => Promise<void>;
      };

      await component.incrementStatus();

      expect(mockReviewStore.incrementStatus).toHaveBeenCalled();
    });

    it('decrementStatus calls store.decrementStatus', async () => {
      const componentFactory = getReviewAppComponent();
      const component = componentFactory() as {
        store: ReturnType<typeof getReviewStore>;
        decrementStatus: () => Promise<void>;
      };

      await component.decrementStatus();

      expect(mockReviewStore.decrementStatus).toHaveBeenCalled();
    });

    it('setStatus calls store.updateStatus', async () => {
      const componentFactory = getReviewAppComponent();
      const component = componentFactory() as {
        store: ReturnType<typeof getReviewStore>;
        setStatus: (s: number) => Promise<void>;
      };

      await component.setStatus(3);

      expect(mockReviewStore.updateStatus).toHaveBeenCalledWith(3);
    });

    it('skipWord calls store.skipWord', async () => {
      const componentFactory = getReviewAppComponent();
      const component = componentFactory() as {
        store: ReturnType<typeof getReviewStore>;
        skipWord: () => Promise<void>;
      };

      await component.skipWord();

      expect(mockReviewStore.skipWord).toHaveBeenCalled();
    });

    it('toggleReadAloud calls store.setReadAloud', () => {
      const componentFactory = getReviewAppComponent();
      const component = componentFactory() as {
        store: ReturnType<typeof getReviewStore>;
        toggleReadAloud: (e: Event) => void;
      };

      const event = { target: { checked: true } } as unknown as Event;
      component.toggleReadAloud(event);

      expect(mockReviewStore.setReadAloud).toHaveBeenCalledWith(true);
    });

    it('getCurrentWordGroup returns empty string when no word', () => {
      const componentFactory = getReviewAppComponent();
      const component = componentFactory() as {
        store: ReturnType<typeof getReviewStore>;
        getCurrentWordGroup: () => string;
      };

      expect(component.getCurrentWordGroup()).toBe('');
    });

    it('getCurrentWordSolution returns empty string when no word', () => {
      const componentFactory = getReviewAppComponent();
      const component = componentFactory() as {
        store: ReturnType<typeof getReviewStore>;
        getCurrentWordSolution: () => string;
      };

      expect(component.getCurrentWordSolution()).toBe('');
    });

    it('getCurrentWordStatus returns 0 when no word', () => {
      const componentFactory = getReviewAppComponent();
      const component = componentFactory() as {
        store: ReturnType<typeof getReviewStore>;
        getCurrentWordStatus: () => number;
      };

      expect(component.getCurrentWordStatus()).toBe(0);
    });
  });

  // ===========================================================================
  // tableReview Component Tests
  // ===========================================================================

  describe('tableReview component', () => {
    function getTableReviewComponent(): () => Record<string, unknown> {
      document.body.innerHTML = `
        <div id="review-app"></div>
        <script type="application/json" id="review-config">
          {"reviewType": 1, "isTableMode": true}
        </script>
      `;

      initReviewApp();

      const calls = (Alpine.data as Mock).mock.calls;
      const tableCall = calls.find((c: unknown[]) => c[0] === 'tableReview');
      return tableCall ? tableCall[1] : () => ({});
    }

    it('has default column settings', () => {
      const componentFactory = getTableReviewComponent();
      const component = componentFactory() as {
        columns: Record<string, boolean>;
      };

      expect(component.columns.edit).toBe(true);
      expect(component.columns.status).toBe(true);
      expect(component.columns.term).toBe(true);
      expect(component.columns.trans).toBe(true);
      expect(component.columns.rom).toBe(false);
      expect(component.columns.sentence).toBe(true);
    });

    it('has hide content toggles', () => {
      const componentFactory = getTableReviewComponent();
      const component = componentFactory() as {
        hideTermContent: boolean;
        hideTransContent: boolean;
      };

      expect(component.hideTermContent).toBe(false);
      expect(component.hideTransContent).toBe(false);
    });

    it('has context annotations settings', () => {
      const componentFactory = getTableReviewComponent();
      const component = componentFactory() as {
        contextAnnotations: { rom: boolean; trans: boolean };
      };

      expect(component.contextAnnotations.rom).toBe(false);
      expect(component.contextAnnotations.trans).toBe(false);
    });

    it('has revealed state tracking', () => {
      const componentFactory = getTableReviewComponent();
      const component = componentFactory() as {
        revealedTerms: Record<number, boolean>;
        revealedTrans: Record<number, boolean>;
      };

      expect(component.revealedTerms).toEqual({});
      expect(component.revealedTrans).toEqual({});
    });

    it('revealTerm sets revealed state when hidden', () => {
      const componentFactory = getTableReviewComponent();
      const component = componentFactory() as {
        hideTermContent: boolean;
        revealedTerms: Record<number, boolean>;
        revealTerm: (id: number) => void;
      };
      component.hideTermContent = true;

      component.revealTerm(5);

      expect(component.revealedTerms[5]).toBe(true);
    });

    it('revealTerm does nothing when not hidden', () => {
      const componentFactory = getTableReviewComponent();
      const component = componentFactory() as {
        hideTermContent: boolean;
        revealedTerms: Record<number, boolean>;
        revealTerm: (id: number) => void;
      };
      component.hideTermContent = false;

      component.revealTerm(5);

      expect(component.revealedTerms[5]).toBeUndefined();
    });

    it('revealTrans sets revealed state when hidden', () => {
      const componentFactory = getTableReviewComponent();
      const component = componentFactory() as {
        hideTransContent: boolean;
        revealedTrans: Record<number, boolean>;
        revealTrans: (id: number) => void;
      };
      component.hideTransContent = true;

      component.revealTrans(10);

      expect(component.revealedTrans[10]).toBe(true);
    });

    it('saveColumnSettings saves to localStorage', () => {
      const componentFactory = getTableReviewComponent();
      const component = componentFactory() as {
        columns: Record<string, boolean>;
        hideTermContent: boolean;
        hideTransContent: boolean;
        saveColumnSettings: () => void;
      };
      component.columns.rom = true;
      component.hideTermContent = true;

      component.saveColumnSettings();

      const saved = JSON.parse(localStorage.getItem('lwt-table-review-columns') || '{}');
      expect(saved.columns.rom).toBe(true);
      expect(saved.hideTermContent).toBe(true);
    });

    it('loadColumnSettings loads from localStorage', () => {
      localStorage.setItem('lwt-table-review-columns', JSON.stringify({
        columns: { edit: false, status: true, term: true, trans: false, rom: true, sentence: true },
        hideTermContent: true,
        hideTransContent: false
      }));

      const componentFactory = getTableReviewComponent();
      const component = componentFactory() as {
        columns: Record<string, boolean>;
        hideTermContent: boolean;
        hideTransContent: boolean;
        loadColumnSettings: () => void;
      };

      component.loadColumnSettings();

      expect(component.columns.edit).toBe(false);
      expect(component.columns.rom).toBe(true);
      expect(component.hideTermContent).toBe(true);
    });

    it('saveContextAnnotationSettings saves to server and localStorage', () => {
      const componentFactory = getTableReviewComponent();
      const component = componentFactory() as {
        contextAnnotations: { rom: boolean; trans: boolean };
        saveContextAnnotationSettings: () => void;
      };
      component.contextAnnotations.rom = true;
      component.contextAnnotations.trans = true;

      component.saveContextAnnotationSettings();

      expect(saveSetting).toHaveBeenCalledWith('currenttabletestsetting7', '1');
      expect(saveSetting).toHaveBeenCalledWith('currenttabletestsetting8', '1');

      const saved = JSON.parse(localStorage.getItem('lwt-context-annotations') || '{}');
      expect(saved.rom).toBe(true);
      expect(saved.trans).toBe(true);
    });

    it('loadContextAnnotationSettings loads from localStorage', () => {
      localStorage.setItem('lwt-context-annotations', JSON.stringify({
        rom: true,
        trans: false
      }));

      const componentFactory = getTableReviewComponent();
      const component = componentFactory() as {
        contextAnnotations: { rom: boolean; trans: boolean };
        loadContextAnnotationSettings: () => void;
      };

      component.loadContextAnnotationSettings();

      expect(component.contextAnnotations.rom).toBe(true);
      expect(component.contextAnnotations.trans).toBe(false);
    });

    it('setWordStatus calls API and updates local state', async () => {
      (ReviewApi.updateStatus as Mock).mockResolvedValue({ data: { status: 3 } });

      const componentFactory = getTableReviewComponent();
      const component = componentFactory() as {
        words: Array<{ id: number; status: number }>;
        setWordStatus: (id: number, status: number) => Promise<void>;
      };
      component.words = [{ id: 1, status: 1 }];

      await component.setWordStatus(1, 3);

      expect(ReviewApi.updateStatus).toHaveBeenCalledWith(1, 3);
      expect(component.words[0].status).toBe(3);
    });
  });

  // ===========================================================================
  // Review Types Tests
  // ===========================================================================

  describe('review types', () => {
    it('renders all 5 review type buttons', () => {
      const container = document.createElement('div');

      renderReviewApp(container);

      expect(container.innerHTML).toContain('Sentence → Translation');
      expect(container.innerHTML).toContain('Sentence → Term');
      expect(container.innerHTML).toContain('Sentence → Both');
      expect(container.innerHTML).toContain('Term → Translation');
      expect(container.innerHTML).toContain('Translation → Term');
    });

    it('renders table mode button', () => {
      const container = document.createElement('div');

      renderReviewApp(container);

      expect(container.innerHTML).toContain('Table');
    });
  });

  // ===========================================================================
  // Keyboard Handler Tests
  // ===========================================================================

  describe('keyboard handling', () => {
    it('handleKeydown exists on reviewApp component', () => {
      document.body.innerHTML = `
        <div id="review-app"></div>
        <script type="application/json" id="review-config">
          {"reviewType": 1, "isTableMode": false}
        </script>
      `;

      initReviewApp();

      const calls = (Alpine.data as Mock).mock.calls;
      const reviewAppCall = calls.find((c: unknown[]) => c[0] === 'reviewApp');
      const component = reviewAppCall![1]();

      expect(typeof component.handleKeydown).toBe('function');
    });
  });

  // ===========================================================================
  // URL Switching Tests
  // ===========================================================================

  describe('URL switching', () => {
    it('switchReviewType modifies URL', () => {
      const originalLocation = window.location;

      document.body.innerHTML = `
        <div id="review-app"></div>
        <script type="application/json" id="review-config">
          {"reviewType": 1, "isTableMode": false}
        </script>
      `;

      initReviewApp();

      const calls = (Alpine.data as Mock).mock.calls;
      const reviewAppCall = calls.find((c: unknown[]) => c[0] === 'reviewApp');
      const component = reviewAppCall![1]() as {
        switchReviewType: (type: number) => void;
      };

      // Mock location
      delete (window as { location?: Location }).location;
      window.location = { href: 'http://test.com/review?text=1' } as Location;

      component.switchReviewType(3);

      expect(window.location.href).toContain('type=3');

      window.location = originalLocation;
    });

    it('switchToTable adds type=table to URL', () => {
      const originalLocation = window.location;

      document.body.innerHTML = `
        <div id="review-app"></div>
        <script type="application/json" id="review-config">
          {"reviewType": 1, "isTableMode": false}
        </script>
      `;

      initReviewApp();

      const calls = (Alpine.data as Mock).mock.calls;
      const reviewAppCall = calls.find((c: unknown[]) => c[0] === 'reviewApp');
      const component = reviewAppCall![1]() as {
        switchToTable: () => void;
        store: ReturnType<typeof getReviewStore>;
      };

      delete (window as { location?: Location }).location;
      window.location = { href: 'http://test.com/review?text=1' } as Location;

      component.switchToTable();

      expect(window.location.href).toContain('type=table');

      window.location = originalLocation;
    });
  });

  // ===========================================================================
  // Finished State Tests
  // ===========================================================================

  describe('finished state', () => {
    it('renders finished message section', () => {
      const container = document.createElement('div');

      renderReviewApp(container);

      expect(container.innerHTML).toContain('getFinishedTitle()');
      expect(container.innerHTML).toContain('hasTomorrowWords()');
      expect(container.innerHTML).toContain('Back to Texts');
    });
  });

  // ===========================================================================
  // Loading State Tests
  // ===========================================================================

  describe('loading state', () => {
    it('renders loading spinner', () => {
      const container = document.createElement('div');

      renderReviewApp(container);

      expect(container.innerHTML).toContain('loading-spinner');
      expect(container.innerHTML).toContain('Loading review');
    });
  });

  // ===========================================================================
  // Error State Tests
  // ===========================================================================

  describe('error state', () => {
    it('renders error notification', () => {
      const container = document.createElement('div');

      renderReviewApp(container);

      expect(container.innerHTML).toContain('is-danger');
      expect(container.innerHTML).toContain('store.error');
    });
  });

  // ===========================================================================
  // Keyboard Handler detailed tests
  // ===========================================================================

  describe('keyboard handling detail', () => {
    function getReviewAppComponent(): () => Record<string, unknown> {
      document.body.innerHTML = `
        <div id="review-app"></div>
        <script type="application/json" id="review-config">
          {"reviewType": 1, "isTableMode": false}
        </script>
      `;

      initReviewApp();

      const calls = (Alpine.data as Mock).mock.calls;
      const reviewAppCall = calls.find((c: unknown[]) => c[0] === 'reviewApp');
      return reviewAppCall ? reviewAppCall[1] : () => ({});
    }

    it('handleKeydown does nothing when modal is open', () => {
      const componentFactory = getReviewAppComponent();
      const component = componentFactory() as {
        store: ReturnType<typeof getReviewStore>;
        handleKeydown: (e: KeyboardEvent) => void;
        revealAnswer: () => void;
      };
      mockReviewStore.isModalOpen = true;

      const revealSpy = vi.spyOn(component, 'revealAnswer');
      const event = new KeyboardEvent('keydown', { key: ' ' });
      component.handleKeydown(event);

      expect(revealSpy).not.toHaveBeenCalled();
      mockReviewStore.isModalOpen = false;
    });

    it('handleKeydown does nothing when in table mode', () => {
      const componentFactory = getReviewAppComponent();
      const component = componentFactory() as {
        store: ReturnType<typeof getReviewStore>;
        handleKeydown: (e: KeyboardEvent) => void;
        revealAnswer: () => void;
      };
      mockReviewStore.isTableMode = true;

      const revealSpy = vi.spyOn(component, 'revealAnswer');
      const event = new KeyboardEvent('keydown', { key: ' ' });
      component.handleKeydown(event);

      expect(revealSpy).not.toHaveBeenCalled();
      mockReviewStore.isTableMode = false;
    });

    it('handleKeydown does nothing when finished', () => {
      const componentFactory = getReviewAppComponent();
      const component = componentFactory() as {
        store: ReturnType<typeof getReviewStore>;
        handleKeydown: (e: KeyboardEvent) => void;
        revealAnswer: () => void;
      };
      mockReviewStore.isFinished = true;

      const revealSpy = vi.spyOn(component, 'revealAnswer');
      const event = new KeyboardEvent('keydown', { key: ' ' });
      component.handleKeydown(event);

      expect(revealSpy).not.toHaveBeenCalled();
      mockReviewStore.isFinished = false;
    });

    it('handleKeydown does nothing when target is input', () => {
      const componentFactory = getReviewAppComponent();
      const component = componentFactory() as {
        store: ReturnType<typeof getReviewStore>;
        handleKeydown: (e: KeyboardEvent) => void;
        revealAnswer: () => void;
      };

      const input = document.createElement('input');
      const event = new KeyboardEvent('keydown', { key: ' ' });
      Object.defineProperty(event, 'target', { value: input });

      const revealSpy = vi.spyOn(component, 'revealAnswer');
      component.handleKeydown(event);

      expect(revealSpy).not.toHaveBeenCalled();
    });

    it('Space key reveals answer when not revealed', () => {
      const componentFactory = getReviewAppComponent();
      const component = componentFactory() as {
        store: ReturnType<typeof getReviewStore>;
        handleKeydown: (e: KeyboardEvent) => void;
        revealAnswer: () => void;
      };
      mockReviewStore.answerRevealed = false;

      const event = new KeyboardEvent('keydown', { key: ' ', cancelable: true });
      const preventSpy = vi.spyOn(event, 'preventDefault');

      component.handleKeydown(event);

      expect(preventSpy).toHaveBeenCalled();
      expect(mockReviewStore.revealAnswer).toHaveBeenCalled();
    });

    it('Escape key skips word', () => {
      const componentFactory = getReviewAppComponent();
      const component = componentFactory() as {
        store: ReturnType<typeof getReviewStore>;
        handleKeydown: (e: KeyboardEvent) => void;
        skipWord: () => Promise<void>;
      };
      mockReviewStore.currentWord = { text: 'test', status: 1, solution: '', group: '' };

      const event = new KeyboardEvent('keydown', { key: 'Escape', cancelable: true });

      component.handleKeydown(event);

      expect(mockReviewStore.skipWord).toHaveBeenCalled();
      mockReviewStore.currentWord = null;
    });

    it('shows nothing under a grade button until its interval arrives', () => {
      const componentFactory = getReviewAppComponent();
      const component = componentFactory() as { intervalHint: (g: number) => string };
      mockReviewStore.intervals = {};

      expect(component.intervalHint(3)).toBe('');
    });

    it('scales the interval hint from days to months to years', () => {
      const componentFactory = getReviewAppComponent();
      const component = componentFactory() as { intervalHint: (g: number) => string };
      mockReviewStore.intervals = { 1: 0, 2: 3, 3: 90, 4: 800 };

      // A same-day interval reads as "today" rather than "0d"
      expect(component.intervalHint(1)).toBe('today');
      expect(component.intervalHint(2)).toBe('3d');
      expect(component.intervalHint(3)).toBe('3mo');
      expect(component.intervalHint(4)).toBe('2y');
      mockReviewStore.intervals = {};
    });

    it('ArrowUp grades the answer Good when revealed', () => {
      const componentFactory = getReviewAppComponent();
      const component = componentFactory() as {
        store: ReturnType<typeof getReviewStore>;
        handleKeydown: (e: KeyboardEvent) => void;
      };
      mockReviewStore.answerRevealed = true;

      const event = new KeyboardEvent('keydown', { key: 'ArrowUp', cancelable: true });

      component.handleKeydown(event);

      expect(mockReviewStore.gradeAnswer).toHaveBeenCalledWith(3);
      mockReviewStore.answerRevealed = false;
    });

    it('ArrowDown grades the answer Again when revealed', () => {
      const componentFactory = getReviewAppComponent();
      const component = componentFactory() as {
        store: ReturnType<typeof getReviewStore>;
        handleKeydown: (e: KeyboardEvent) => void;
      };
      mockReviewStore.answerRevealed = true;

      const event = new KeyboardEvent('keydown', { key: 'ArrowDown', cancelable: true });

      component.handleKeydown(event);

      expect(mockReviewStore.gradeAnswer).toHaveBeenCalledWith(1);
      mockReviewStore.answerRevealed = false;
    });

    it('I key sets status to ignored', () => {
      const componentFactory = getReviewAppComponent();
      const component = componentFactory() as {
        store: ReturnType<typeof getReviewStore>;
        handleKeydown: (e: KeyboardEvent) => void;
        setStatus: (s: number) => Promise<void>;
      };
      mockReviewStore.currentWord = { text: 'test', status: 1, solution: '', group: '' };

      const event = new KeyboardEvent('keydown', { key: 'i', cancelable: true });

      component.handleKeydown(event);

      expect(mockReviewStore.updateStatus).toHaveBeenCalledWith(98);
      mockReviewStore.currentWord = null;
    });

    it('W key sets status to well-known', () => {
      const componentFactory = getReviewAppComponent();
      const component = componentFactory() as {
        store: ReturnType<typeof getReviewStore>;
        handleKeydown: (e: KeyboardEvent) => void;
        setStatus: (s: number) => Promise<void>;
      };
      mockReviewStore.currentWord = { text: 'test', status: 1, solution: '', group: '' };

      const event = new KeyboardEvent('keydown', { key: 'W', cancelable: true });

      component.handleKeydown(event);

      expect(mockReviewStore.updateStatus).toHaveBeenCalledWith(99);
      mockReviewStore.currentWord = null;
    });

    it('E key opens modal', () => {
      const componentFactory = getReviewAppComponent();
      const component = componentFactory() as {
        store: ReturnType<typeof getReviewStore>;
        handleKeydown: (e: KeyboardEvent) => void;
      };
      mockReviewStore.currentWord = { text: 'test', status: 1, solution: '', group: '' };

      const event = new KeyboardEvent('keydown', { key: 'e', cancelable: true });

      component.handleKeydown(event);

      expect(mockReviewStore.openModal).toHaveBeenCalled();
      mockReviewStore.currentWord = null;
    });

    it('Number keys grade the answer when revealed', () => {
      const componentFactory = getReviewAppComponent();
      const component = componentFactory() as {
        store: ReturnType<typeof getReviewStore>;
        handleKeydown: (e: KeyboardEvent) => void;
      };
      mockReviewStore.answerRevealed = true;

      const event = new KeyboardEvent('keydown', {
        key: '3',
        code: 'Digit3',
        cancelable: true
      });

      component.handleKeydown(event);

      expect(mockReviewStore.gradeAnswer).toHaveBeenCalledWith(3);
      mockReviewStore.answerRevealed = false;
    });

    it('Shift and a number key still sets the status directly', () => {
      const componentFactory = getReviewAppComponent();
      const component = componentFactory() as {
        store: ReturnType<typeof getReviewStore>;
        handleKeydown: (e: KeyboardEvent) => void;
      };
      mockReviewStore.answerRevealed = true;

      const event = new KeyboardEvent('keydown', {
        key: '%',
        code: 'Digit5',
        shiftKey: true,
        cancelable: true
      });

      component.handleKeydown(event);

      // Read off the physical key, so a layout where Shift+5 is not "%" — or
      // where the digits themselves are shifted — behaves the same
      expect(mockReviewStore.updateStatus).toHaveBeenCalledWith(5);
      expect(mockReviewStore.gradeAnswer).not.toHaveBeenCalled();
      mockReviewStore.answerRevealed = false;
    });

    it('Number key 5 does not grade, there being only four grades', () => {
      const componentFactory = getReviewAppComponent();
      const component = componentFactory() as {
        store: ReturnType<typeof getReviewStore>;
        handleKeydown: (e: KeyboardEvent) => void;
      };
      mockReviewStore.answerRevealed = true;

      const event = new KeyboardEvent('keydown', {
        key: '5',
        code: 'Digit5',
        cancelable: true
      });

      component.handleKeydown(event);

      expect(mockReviewStore.gradeAnswer).not.toHaveBeenCalled();
      expect(mockReviewStore.updateStatus).not.toHaveBeenCalled();
      mockReviewStore.answerRevealed = false;
    });
  });

  // ===========================================================================
  // reviewApp init Tests
  // ===========================================================================

  describe('reviewApp init', () => {
    it('configures store and fetches first word', async () => {
      document.body.innerHTML = `
        <div id="review-app"></div>
        <script type="application/json" id="review-config">
          {"reviewType": 2, "isTableMode": false}
        </script>
      `;

      initReviewApp();

      const calls = (Alpine.data as Mock).mock.calls;
      const reviewAppCall = calls.find((c: unknown[]) => c[0] === 'reviewApp');
      const componentFactory = reviewAppCall![1];
      const component = componentFactory() as {
        init: () => Promise<void>;
      };

      await component.init();

      expect(mockReviewStore.configure).toHaveBeenCalled();
      expect(mockReviewStore.nextWord).toHaveBeenCalled();
    });

    it('does not fetch word in table mode', async () => {
      document.body.innerHTML = `
        <div id="review-app"></div>
        <script type="application/json" id="review-config">
          {"reviewType": 1, "isTableMode": true}
        </script>
      `;

      initReviewApp();

      const calls = (Alpine.data as Mock).mock.calls;
      const reviewAppCall = calls.find((c: unknown[]) => c[0] === 'reviewApp');
      const componentFactory = reviewAppCall![1];
      const component = componentFactory() as {
        init: () => Promise<void>;
      };

      mockReviewStore.nextWord.mockClear();
      await component.init();

      expect(mockReviewStore.nextWord).not.toHaveBeenCalled();
    });
  });

  // ===========================================================================
  // speakWord Tests
  // ===========================================================================

  describe('speakWord', () => {
    it('calls speechDispatcher with word text and langId', async () => {
      const { speechDispatcher } = await import('../../../src/frontend/js/shared/utils/user_interactions');

      document.body.innerHTML = `
        <div id="review-app"></div>
        <script type="application/json" id="review-config">
          {"reviewType": 1, "isTableMode": false}
        </script>
      `;

      initReviewApp();

      const calls = (Alpine.data as Mock).mock.calls;
      const reviewAppCall = calls.find((c: unknown[]) => c[0] === 'reviewApp');
      const componentFactory = reviewAppCall![1];
      const component = componentFactory() as {
        speakWord: () => void;
        store: ReturnType<typeof getReviewStore>;
      };

      mockReviewStore.currentWord = { text: 'hello', status: 1, solution: '', group: '' };
      mockReviewStore.langSettings.langCode = 'en';
      mockReviewStore.langId = 5;

      component.speakWord();

      expect(speechDispatcher).toHaveBeenCalledWith('hello', 5);

      mockReviewStore.currentWord = null;
    });
  });

  // ===========================================================================
  // tableReview loadWords Tests
  // ===========================================================================

  describe('tableReview loadWords', () => {
    it('loads words from API', async () => {
      (ReviewApi.getTableWords as Mock).mockResolvedValue({
        data: {
          words: [{ id: 1, text: 'test', status: 1, translation: 'prueba' }],
          langSettings: { textSize: 100, rtl: false }
        }
      });

      document.body.innerHTML = `
        <div id="review-app"></div>
        <script type="application/json" id="review-config">
          {"reviewType": 1, "isTableMode": true}
        </script>
      `;

      initReviewApp();

      const calls = (Alpine.data as Mock).mock.calls;
      const tableCall = calls.find((c: unknown[]) => c[0] === 'tableReview');
      const componentFactory = tableCall![1];
      const component = componentFactory() as {
        loadWords: () => Promise<void>;
        words: Array<{ id: number }>;
        isLoading: boolean;
      };

      await component.loadWords();

      expect(ReviewApi.getTableWords).toHaveBeenCalled();
      expect(component.words).toHaveLength(1);
      expect(component.isLoading).toBe(false);
    });

    it('handles API errors gracefully', async () => {
      const consoleSpy = vi.spyOn(console, 'error').mockImplementation(() => {});
      (ReviewApi.getTableWords as Mock).mockRejectedValue(new Error('Network error'));

      document.body.innerHTML = `
        <div id="review-app"></div>
        <script type="application/json" id="review-config">
          {"reviewType": 1, "isTableMode": true}
        </script>
      `;

      initReviewApp();

      const calls = (Alpine.data as Mock).mock.calls;
      const tableCall = calls.find((c: unknown[]) => c[0] === 'tableReview');
      const componentFactory = tableCall![1];
      const component = componentFactory() as {
        loadWords: () => Promise<void>;
        isLoading: boolean;
      };

      await component.loadWords();

      expect(consoleSpy).toHaveBeenCalled();
      expect(component.isLoading).toBe(false);
    });
  });

  // ===========================================================================
  // tableReview setWordStatus error handling Tests
  // ===========================================================================

  describe('tableReview setWordStatus error handling', () => {
    it('logs error when API fails', async () => {
      const consoleSpy = vi.spyOn(console, 'error').mockImplementation(() => {});
      (ReviewApi.updateStatus as Mock).mockRejectedValue(new Error('Update failed'));

      document.body.innerHTML = `
        <div id="review-app"></div>
        <script type="application/json" id="review-config">
          {"reviewType": 1, "isTableMode": true}
        </script>
      `;

      initReviewApp();

      const calls = (Alpine.data as Mock).mock.calls;
      const tableCall = calls.find((c: unknown[]) => c[0] === 'tableReview');
      const componentFactory = tableCall![1];
      const component = componentFactory() as {
        words: Array<{ id: number; status: number }>;
        setWordStatus: (id: number, status: number) => Promise<void>;
      };
      component.words = [{ id: 1, status: 1 }];

      await component.setWordStatus(1, 3);

      expect(consoleSpy).toHaveBeenCalledWith('Error updating status:', expect.any(Error));
    });

    it('does not update word when API returns undefined status', async () => {
      (ReviewApi.updateStatus as Mock).mockResolvedValue({
        data: { status: undefined }
      });

      document.body.innerHTML = `
        <div id="review-app"></div>
        <script type="application/json" id="review-config">
          {"reviewType": 1, "isTableMode": true}
        </script>
      `;

      initReviewApp();

      const calls = (Alpine.data as Mock).mock.calls;
      const tableCall = calls.find((c: unknown[]) => c[0] === 'tableReview');
      const componentFactory = tableCall![1];
      const component = componentFactory() as {
        words: Array<{ id: number; status: number }>;
        setWordStatus: (id: number, status: number) => Promise<void>;
      };
      component.words = [{ id: 1, status: 1 }];

      await component.setWordStatus(1, 3);

      expect(component.words[0].status).toBe(1); // unchanged
    });
  });

  // ===========================================================================
  // tableReview init Tests
  // ===========================================================================

  describe('tableReview init', () => {
    it('loads column settings and words on init', async () => {
      localStorage.setItem('lwt-table-review-columns', JSON.stringify({
        columns: { edit: false },
        hideTermContent: true
      }));

      document.body.innerHTML = `
        <div id="review-app"></div>
        <script type="application/json" id="review-config">
          {"reviewType": 1, "isTableMode": true}
        </script>
      `;

      initReviewApp();

      const calls = (Alpine.data as Mock).mock.calls;
      const tableCall = calls.find((c: unknown[]) => c[0] === 'tableReview');
      const componentFactory = tableCall![1];
      const component = componentFactory() as {
        init: () => Promise<void>;
        columns: Record<string, boolean>;
        hideTermContent: boolean;
      };

      await component.init();

      expect(ReviewApi.getTableWords).toHaveBeenCalled();
    });
  });

  // ===========================================================================
  // revealAnswer with readAloud Tests
  // ===========================================================================

  describe('revealAnswer with readAloud', () => {
    it('speaks word when readAloud enabled', async () => {
      const { speechDispatcher } = await import('../../../src/frontend/js/shared/utils/user_interactions');

      document.body.innerHTML = `
        <div id="review-app"></div>
        <script type="application/json" id="review-config">
          {"reviewType": 1, "isTableMode": false}
        </script>
      `;

      initReviewApp();

      const calls = (Alpine.data as Mock).mock.calls;
      const reviewAppCall = calls.find((c: unknown[]) => c[0] === 'reviewApp');
      const componentFactory = reviewAppCall![1];
      const component = componentFactory() as {
        revealAnswer: () => void;
        store: ReturnType<typeof getReviewStore>;
      };

      mockReviewStore.readAloudEnabled = true;
      mockReviewStore.currentWord = { text: 'hello', status: 1, solution: '', group: '' };

      component.revealAnswer();

      expect(mockReviewStore.revealAnswer).toHaveBeenCalled();
      expect(speechDispatcher).toHaveBeenCalled();

      mockReviewStore.readAloudEnabled = false;
      mockReviewStore.currentWord = null;
    });
  });

  // ===========================================================================
  // setTermDisplayHtml Tests
  // ===========================================================================

  describe('setTermDisplayHtml', () => {
    it('sets innerHTML on element', () => {
      document.body.innerHTML = `
        <div id="review-app"></div>
        <script type="application/json" id="review-config">
          {"reviewType": 1, "isTableMode": false}
        </script>
      `;

      initReviewApp();

      const calls = (Alpine.data as Mock).mock.calls;
      const reviewAppCall = calls.find((c: unknown[]) => c[0] === 'reviewApp');
      const componentFactory = reviewAppCall![1];
      const component = componentFactory() as {
        setTermDisplayHtml: (el: HTMLElement) => void;
        getCurrentWordGroup: () => string;
      };

      mockReviewStore.currentWord = { text: 'test', status: 1, solution: 'prueba', group: '<b>test</b>' };

      const el = document.createElement('div');
      component.setTermDisplayHtml(el);

      expect(el.innerHTML).toBe('<b>test</b>');

      mockReviewStore.currentWord = null;
    });
  });

  // ===========================================================================
  // getCurrentWord* with current word Tests
  // ===========================================================================

  describe('getCurrentWord accessors with word', () => {
    it('returns word properties when word exists', () => {
      document.body.innerHTML = `
        <div id="review-app"></div>
        <script type="application/json" id="review-config">
          {"reviewType": 1, "isTableMode": false}
        </script>
      `;

      initReviewApp();

      const calls = (Alpine.data as Mock).mock.calls;
      const reviewAppCall = calls.find((c: unknown[]) => c[0] === 'reviewApp');
      const componentFactory = reviewAppCall![1];
      const component = componentFactory() as {
        getCurrentWordGroup: () => string;
        getCurrentWordSolution: () => string;
        getCurrentWordStatus: () => number;
      };

      mockReviewStore.currentWord = {
        text: 'test',
        status: 3,
        solution: 'prueba',
        group: '<span>group</span>'
      };

      expect(component.getCurrentWordGroup()).toBe('<span>group</span>');
      expect(component.getCurrentWordSolution()).toBe('prueba');
      expect(component.getCurrentWordStatus()).toBe(3);

      mockReviewStore.currentWord = null;
    });
  });
});
