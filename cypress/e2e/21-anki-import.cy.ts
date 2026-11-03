/// <reference types="cypress" />

/**
 * The two Anki import pages.
 *
 * These exist because /vocabulary/apkg/import returned a 500 from the 3.6.0
 * release until it was found by hand: the controller is auto-wired, one
 * interface in its dependency graph was never bound, and nothing noticed.
 * The unit tests could not catch it — they construct the controller directly
 * with their own services, which is exactly the step that was broken. Only a
 * request through the real router can.
 */
describe('Anki import pages', () => {
  describe('Round-trip .apkg import', () => {
    beforeEach(() => {
      cy.visit('/vocabulary/apkg/import');
    });

    it('should load, rather than 500 on an unresolved dependency', () => {
      cy.get('h1').should('be.visible');
      cy.contains('Internal Server Error').should('not.exist');
    });

    it('should offer the upload form', () => {
      cy.get('form[enctype="multipart/form-data"]').should('exist');
      cy.get('input[type="file"][name="apkg"]').should('exist');
      cy.get('button[type="submit"]').should('exist');
    });

    // The hand-rolled markup emitted this field as "csrf_token", which the
    // middleware never reads -- it only looks for "_csrf_token". Moving to
    // FormHelper::csrfField() is what fixed the name.
    it('should carry a CSRF token under the name the middleware reads', () => {
      cy.get('input[name="_csrf_token"]').should('exist');
    });

    it('should point a foreign deck at the page that can create terms', () => {
      cy.get('a[href="/vocabulary/anki-deck/import"]').should('exist');
    });

    it('should render every label through the translator', () => {
      // An untranslated key renders as its own name. This is what the page
      // did for its whole life before the markup moved into a view.
      cy.get('body').should('not.contain', 'vocabulary.anki.');
    });
  });

  describe('Foreign deck import', () => {
    beforeEach(() => {
      cy.visit('/vocabulary/anki-deck/import');
    });

    it('should load', () => {
      cy.get('h1').should('be.visible');
      cy.contains('Internal Server Error').should('not.exist');
    });

    it('should offer the upload form', () => {
      cy.get('form[enctype="multipart/form-data"]').should('exist');
      cy.get('input[type="file"][name="apkg"]').should('exist');
    });

    it('should carry a CSRF token under the name the middleware reads', () => {
      cy.get('input[name="_csrf_token"]').should('exist');
    });

    it('should render every label through the translator', () => {
      cy.get('body').should('not.contain', 'vocabulary.anki.');
    });
  });

  // #264: the page that pulls reviews from Anki over AnkiConnect. Anki is not
  // running under test, so this checks the page and its failure path; the
  // merge itself is covered by MergeReviewHistoryTest and AnkiReviewPullTest.
  describe('Pull reviews from Anki', () => {
    beforeEach(() => {
      cy.visit('/vocabulary/anki/sync');
      cy.waitForAlpine();
    });

    it('should load, with every label translated', () => {
      cy.get('h1').should('be.visible');
      cy.contains('Internal Server Error').should('not.exist');
      cy.get('body').should('not.contain', 'vocabulary.anki.');
    });

    it('should be linked from the .apkg import page', () => {
      cy.visit('/vocabulary/apkg/import');
      cy.get('a[href="/vocabulary/anki/sync"]').should('exist');
    });

    it('should say so when Anki cannot be reached', () => {
      cy.intercept('POST', 'http://127.0.0.1:8765/', { forceNetworkError: true });
      cy.get('[x-data="ankiSync"] button.is-primary').click();
      cy.get('[x-data="ankiSync"] .notification.is-danger')
        .should('be.visible')
        .and('contain', '127.0.0.1:8765');
    });

    it('should post what Anki returns and show the summary', () => {
      cy.intercept('POST', 'http://127.0.0.1:8765/', (req) => {
        // AnkiConnect requests go out as a plain-text JSON body.
        const body = (typeof req.body === 'string' ? JSON.parse(req.body) : req.body) as { action: string };
        const { action } = body;
        const results: Record<string, unknown> = {
          requestPermission: { permission: 'granted' },
          deckNames: ['Default', 'LWT::Nowhere'],
          findNotes: [100],
          notesInfo: [{
            noteId: 100,
            cards: [1000],
            tags: [],
            fields: { Term: { value: 'x', order: 0 }, LwtId: { value: '999999', order: 4 } },
          }],
          cardsInfo: [{ cardId: 1000, deckName: 'LWT::Nowhere', queue: 0 }],
          getReviewsOfCards: { 1000: [{ id: 1770000000000, ease: 3 }] },
        };
        req.reply({ result: results[action] ?? null, error: null });
      });
      cy.intercept('POST', '**/api/v1/anki/reviews').as('pull');

      cy.get('[x-data="ankiSync"] button.is-primary').click();

      cy.wait('@pull').then(({ request }) => {
        const sent = (typeof request.body === 'string' ? JSON.parse(request.body) : request.body) as {
          reviews: unknown[];
        };
        expect(sent.reviews).to.deep.equal([{ term_id: 999999, reviewed_at: 1770000000000, ease: 3 }]);
      });
      // No term 999999 here, so the one review is reported as skipped.
      cy.get('[x-data="ankiSync"] .notification.is-success').should('be.visible');
    });
  });
});
