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
});
