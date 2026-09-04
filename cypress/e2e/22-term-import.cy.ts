/// <reference types="cypress" />

/**
 * The term-import page, /word/upload.
 *
 * Its controller reported every outcome by echoing a notification built by
 * hand -- ten copies of the same markup, none of them translated, so the
 * failure messages were English on all nine locales. Unit tests cannot see
 * that: they assert the class's shape, not what a request renders. These
 * specs walk the paths that produce each message.
 */
describe('Term import', () => {
  describe('The form', () => {
    beforeEach(() => {
      cy.visit('/word/upload');
    });

    it('should load', () => {
      cy.get('h1').should('be.visible');
      cy.contains('Internal Server Error').should('not.exist');
    });

    it('should offer the three import tabs', () => {
      cy.get('.tabs li').should('have.length.at.least', 3);
    });

    it('should render every label through the translator', () => {
      // An untranslated key renders as its own name.
      cy.get('body').should('not.contain', 'vocabulary.upload.');
      cy.get('body').should('not.contain', 'vocabulary.common.');
    });
  });

  describe('Reporting a failed import', () => {
    it('should say which language is missing, in the page locale', () => {
      // No LgID: the first thing the import path checks.
      cy.visit('/word/upload?op=Import');
      cy.get('.notification.is-danger').should('be.visible');
      cy.get('body').should('not.contain', 'vocabulary.upload.errors.');
    });

    it('should reject an import with a language but no data', () => {
      cy.visit('/word/upload?op=Import&LgID=1&Col1=w');
      cy.get('.notification.is-danger').should('be.visible');
      cy.get('body').should('not.contain', 'vocabulary.upload.errors.');
    });

    it('should say when no column holds the term', () => {
      cy.visit('/word/upload?op=Import&LgID=1&Col1=t&Upload=hello');
      cy.get('.notification.is-danger').should('be.visible');
      cy.get('body').should('not.contain', 'vocabulary.upload.errors.');
    });

    it('should say when a dictionary import has no file', () => {
      cy.visit('/word/upload?op=ImportDictionary&LgID=1');
      cy.get('.notification.is-danger').should('be.visible');
      cy.get('body').should('not.contain', 'vocabulary.upload.errors.');
    });
  });
});
