/// <reference types="cypress" />

/**
 * One row per language in the "All Languages" table.
 *
 * The list has been through two redesigns (action card -> header button, cards
 * -> table). The old `.language-card` selector outlived the markup and, because
 * most assertions here are wrapped in `if (find('.language-card').length > 0)`,
 * the tests kept passing while asserting nothing. Keep the row selector defined
 * once so the next redesign is a one-line fix rather than silent rot.
 */
const LANG_ROW = 'table.is-hoverable tbody tr';

describe('Languages Management', () => {
  beforeEach(() => {
    cy.visit('/languages');
    // Wait for Alpine.js to initialize
    cy.get('[x-data="languageList"]').should('exist');
  });

  describe('Languages List', () => {
    it('should load languages page', () => {
      cy.url().should('include', '/languages');
      cy.get('body').should('be.visible');
    });

    it('should display loading state initially', () => {
      // The loading state may be very brief, so we just check it exists
      cy.get('[x-data="languageList"]').should('exist');
    });

    it('should display the language table or the empty state after loading', () => {
      cy.get('[x-data="languageList"]').should('exist');
      cy.get(`${LANG_ROW}, a[href*="/languages/new"]`).should('exist');
    });

    it('should display demo languages when installed', () => {
      cy.get('body').then(($body) => {
        if ($body.find(LANG_ROW).length > 0) {
          cy.fixture('test-data').then((data) => {
            cy.get(LANG_ROW).should('contain', data.demoLanguages[0]);
          });
        } else {
          cy.log('No languages installed - skipping demo language check');
        }
      });
    });

    it('should have action buttons on each language row when languages exist', () => {
      cy.get('body').then(($body) => {
        if ($body.find(LANG_ROW).length > 0) {
          // Actions are icon-only buttons/links in the trailing cell.
          cy.get(LANG_ROW).first().find('.buttons a, .buttons button').should('exist');
        } else {
          cy.log('No languages installed - skipping action buttons check');
        }
      });
    });

    it('should have edit links for languages when they exist', () => {
      cy.get('body').then(($body) => {
        if ($body.find(LANG_ROW).length > 0) {
          cy.get(`${LANG_ROW} a[href*="/edit"]`).should('exist');
        } else {
          cy.log('No languages installed - skipping edit links check');
        }
      });
    });

    it('should display per-language counts when languages exist', () => {
      cy.get('body').then(($body) => {
        if ($body.find(LANG_ROW).length > 0) {
          // Texts / archived / terms / feeds counts each link out to their list.
          cy.get(LANG_ROW).first().find('td.has-text-centered a').should('have.length.at.least', 3);
        } else {
          cy.log('No languages installed - skipping counts check');
        }
      });
    });

    it('should have a "New Language" button', () => {
      // The list used to carry an .action-card; the button now lives in the
      // page header (and in the empty state when there are no languages).
      cy.get('a[href*="/languages/new"]').should('exist');
    });
  });

  describe('Language Row Actions', () => {
    it('should show a "Set as Current" button for non-current languages', () => {
      cy.get('body').then(($body) => {
        if ($body.find(LANG_ROW).length === 0) {
          cy.log('No languages installed - skipping set-current button check');
          return;
        }
        // The button is icon-only and rendered only for rows that are not the
        // current language, so identify it by its title attribute.
        cy.get(`${LANG_ROW} button[title="Set as Current"]`).should('exist');
      });
    });

    it('should navigate to edit page when Edit is clicked', () => {
      cy.get('body').then(($body) => {
        if ($body.find(`${LANG_ROW} a[href*="/edit"]`).length > 0) {
          cy.get(`${LANG_ROW} a[href*="/edit"]`).first().click();
          cy.url().should('match', /\/languages\/\d+\/edit/);
          cy.get('form').should('exist');
        } else {
          cy.log('No languages installed - skipping edit navigation check');
        }
      });
    });
  });

  describe('Delete Confirmation Modal', () => {
    // Delete is only offered for languages with no texts, words or feeds.
    const DELETE_BTN = `${LANG_ROW} button[title="Delete"]`;

    it('should show delete confirmation when delete is clicked', () => {
      cy.get('body').then(($body) => {
        if ($body.find(DELETE_BTN).length > 0) {
          cy.get(DELETE_BTN).first().click();
          cy.get('.modal.is-active').should('exist');
          cy.get('.modal-card-title').should('contain', 'Confirm Delete');
        } else {
          cy.log('No deletable languages - skipping delete modal check');
        }
      });
    });

    it('should close modal when Cancel is clicked', () => {
      cy.get('body').then(($body) => {
        if ($body.find(DELETE_BTN).length > 0) {
          cy.get(DELETE_BTN).first().click();
          cy.get('.modal.is-active').should('exist');
          cy.get('.modal-card-foot button').contains('Cancel').click();
          cy.get('.modal.is-active').should('not.exist');
        } else {
          cy.log('No deletable languages - skipping cancel modal check');
        }
      });
    });
  });

  describe('Embedded Wizard', () => {
    it('should apply settings when selecting language from embedded wizard', () => {
      cy.visit('/languages/new');

      // Wait for page to fully load
      cy.wait(500);

      // The embedded wizard uses SearchableSelectHelper which renders as an Alpine.js component
      // Structure: searchable-select > hidden input#l2 + searchable-select__trigger button + dropdown
      // We need to:
      // 1. Click the trigger button to open the dropdown
      // 2. Type in the search input to filter
      // 3. Click the option

      // Find the searchable select for L2 (contains input#l2)
      cy.get('input#l2').closest('.searchable-select').within(() => {
        // Click the trigger button to open the dropdown
        cy.get('.searchable-select__trigger').click();

        // Type to filter
        cy.get('.searchable-select__dropdown input[type="text"]').type('Latvian');
      });

      // Click on the Latvian option (options are <li> elements inside .searchable-select__options)
      cy.get('.searchable-select__options li').contains('Latvian').click();

      // Wait for settings to be applied
      cy.wait(300);

      // Verify the language name is set
      cy.get('input[name="LgName"]').should('have.value', 'Latvian');

      // Expand Advanced Settings to check parsing settings
      cy.contains('Advanced Settings').click();

      // Latvian should NOT be right-to-left
      cy.get('input[name="LgRightToLeft"]').should('not.be.checked');

      // Word characters regex should be set
      cy.get('input[name="LgRegexpWordCharacters"]').invoke('val').should('not.be.empty');

      // Sentence split regex should be set
      cy.get('input[name="LgRegexpSplitSentences"]').invoke('val').should('not.be.empty');
    });

    it('should persist wizard-derived settings after saving', () => {
      // The language keeps the name the wizard gave it, so clear what an
      // interrupted run left behind.
      const deleteDanish = () =>
        cy.request('/api/v1/languages').then((response) => {
          const languages = response.body.languages as Array<{ id: number; name: string }>;
          languages.filter((l) => l.name === 'Danish').forEach((l) => {
            cy.apiRequest({ method: 'DELETE', url: `/api/v1/languages/${l.id}` });
          });
        });
      deleteDanish();

      cy.visit('/languages/new');
      cy.wait(500);

      cy.get('input#l1').closest('.searchable-select').within(() => {
        cy.get('.searchable-select__trigger').click();
        cy.get('.searchable-select__dropdown input[type="text"]').type('English');
        cy.get('.searchable-select__options li').contains('English').click();
      });

      cy.get('input#l2').closest('.searchable-select').within(() => {
        cy.get('.searchable-select__trigger').click();
        cy.get('.searchable-select__dropdown input[type="text"]').type('Danish');
        cy.get('.searchable-select__options li').contains('Danish').click();
      });
      cy.wait(300);

      // Saved exactly as the wizard filled it in: retyping any field here
      // would hide a wizard write that never reached the form's state.
      cy.get('form[name="lg_form"] button[type="submit"]').click();

      // Creating a language now hands off to the starter-vocabulary step.
      cy.url().should('match', /\/languages\/\d+\/starter-vocab/);

      // The list is client-rendered from /api/v1/languages, so wait for the
      // Alpine root and then for the card itself rather than a fixed delay.
      cy.visit('/languages');
      cy.get('[x-data="languageList"]').should('exist');
      cy.contains(LANG_ROW, 'Danish', { timeout: 10000 })
        .within(() => {
          cy.get('a[href*="/edit"]').click();
        });

      cy.get('input[name="LgName"]').should('have.value', 'Danish');
      cy.contains('Advanced Settings').click();
      cy.get('input[name="LgDict1URI"]').should('have.value', 'https://glosbe.com/da/en/lwt_term');
      cy.get('input[name="LgGoogleTranslateURI"]').invoke('val').should('contain', 'translate.google.com');
      cy.get('input[name="LgSourceLang"]').should('have.value', 'da');
      cy.get('input[name="LgTargetLang"]').should('have.value', 'en');
      cy.get('input[name="LgRightToLeft"]').should('not.be.checked');
      cy.get('input[name="LgRegexpWordCharacters"]').invoke('val').should('not.be.empty');

      deleteDanish();
    });
  });

  describe('Create Language', () => {
    it('should show new language form', () => {
      cy.visit('/languages/new');
      cy.get('form[name="lg_form"]').should('exist');
    });

    it('should have required form fields', () => {
      cy.visit('/languages/new');
      // Language name field
      cy.get('input[name="LgName"]').should('exist');
      // Dictionary field
      cy.get('input[name="LgDict1URI"]').should('exist');
      // Word characters regex field
      cy.get('input[name="LgRegexpWordCharacters"]').should('exist');
      // Sentence split regex field
      cy.get('input[name="LgRegexpSplitSentences"]').should('exist');
    });

    it('should have submit button', () => {
      cy.visit('/languages/new');
      cy.get('button[type="submit"]').should('exist');
    });

    it('should create a new language', () => {
      cy.visit('/languages/new');

      const uniqueName = `Test Language ${Date.now()}`;

      // Fill in required fields
      cy.get('input[name="LgName"]').type(uniqueName);

      // Expand Advanced Settings section to access dictionary and regex fields
      cy.contains('Advanced Settings').click();

      cy.get('input[name="LgDict1URI"]').type('https://example.com/###');

      // Find and fill word characters field if empty
      cy.get('input[name="LgRegexpWordCharacters"]').then(($input) => {
        if (!$input.val()) {
          cy.wrap($input).type('a-zA-Z');
        }
      });

      // Find and fill sentence split field if empty
      cy.get('input[name="LgRegexpSplitSentences"]').then(($input) => {
        if (!$input.val()) {
          cy.wrap($input).type('.!?');
        }
      });

      // Submit the form
      cy.get('button[type="submit"]').click();

      // Creating a language hands off to the starter-vocabulary step, which
      // offers to seed the new language before you write your first text.
      cy.url().should('match', /\/languages\/\d+\/starter-vocab/);
    });
  });

  describe('Edit Language', () => {
    // These tests require at least one language to exist
    // Skip if no demo data is installed

    beforeEach(() => {
      cy.visit('/languages');
      // Wait for page to fully load
      cy.get('body').should('be.visible');
    });

    it('should load edit form for existing language', () => {
      cy.get('body').then(($body) => {
        if ($body.find(LANG_ROW).length > 0) {
          cy.get(LANG_ROW).first().within(() => {
            cy.get('a[href*="/edit"]').click();
          });
          cy.get('form[name="lg_form"]').should('exist');
          cy.get('input[name="LgName"]').should('not.have.value', '');
        } else {
          cy.log('No languages installed - skipping edit form test');
        }
      });
    });

    it('should have populated fields', () => {
      cy.get('body').then(($body) => {
        if ($body.find(LANG_ROW).length > 0) {
          cy.get(LANG_ROW).first().find('a[href*="/edit"]').click();
          cy.get('input[name="LgName"]').invoke('val').should('not.be.empty');
        } else {
          cy.log('No languages installed - skipping populated fields test');
        }
      });
    });

    it('should have cancel link that returns to list', () => {
      cy.get('body').then(($body) => {
        if ($body.find(LANG_ROW).length > 0) {
          cy.get(LANG_ROW).first().find('a[href*="/edit"]').click();
          // Cancel is a link, not a button
          cy.contains('a', 'Cancel').click();
          cy.url().should('eq', Cypress.config().baseUrl + '/languages');
        } else {
          cy.log('No languages installed - skipping cancel link test');
        }
      });
    });
  });

  describe('Text Size Preview', () => {
    it('should have text size input in form', () => {
      cy.visit('/languages/new');

      // The text size input should exist (may be in collapsed section)
      cy.get('input[name="LgTextSize"]').should('exist');

      // The preview element should exist
      cy.get('#LgTextSizeExample').should('exist');
    });

    it('should update text size when input changes', () => {
      cy.visit('/languages/new');

      // Force interaction since the element may be in a collapsed section
      cy.get('input[name="LgTextSize"]').clear({ force: true }).type('150', { force: true });
      cy.get('input[name="LgTextSize"]').should('have.value', '150');
    });
  });
});
