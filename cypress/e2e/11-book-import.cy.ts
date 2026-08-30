/// <reference types="cypress" />

/**
 * EPUB import posts to the API and renders its outcome client-side, replacing
 * the server-rendered result page. These specs cover what unit tests cannot:
 * that the CSP Alpine build can evaluate the component's bindings, and that a
 * multipart upload survives CsrfMiddleware.
 */
describe('EPUB Import', () => {
  const FIXTURE = 'cypress/fixtures/sample-book.epub';

  /**
   * Remove a book so re-runs do not trip the duplicate-import guard.
   *
   * @param bookId Book to delete
   */
  const deleteBook = (bookId: string) => {
    cy.apiRequest({
      method: 'POST',
      url: `/book/${bookId}/delete`,
      form: true,
      body: {},
      failOnStatusCode: false
    });
  };

  describe('Import form', () => {
    beforeEach(() => {
      cy.visit('/book/import');
      cy.waitForAlpine();
    });

    it('no longer posts to the removed server-rendered result page', () => {
      // The old flow submitted here and landed on import_result.php.
      cy.get('form[enctype="multipart/form-data"]')
        .should('not.have.attr', 'action', '/book/import');
    });

    it('reflects the chosen filename through the component', () => {
      cy.get('input[type="file"][name="thefile"]').selectFile(FIXTURE, { force: true });
      cy.get('#filename').should('contain.text', 'sample-book.epub');
    });

    it('reports a missing language in place, without navigating', () => {
      cy.get('select[name="LgID"]').then(($select) => {
        $select.append('<option value="" selected>none</option>');
      });
      cy.get('input[type="file"][name="thefile"]').selectFile(FIXTURE, { force: true });
      cy.get('button[type="submit"]').click();

      cy.get('.notification.is-danger').should('be.visible');
      cy.url().should('include', '/book/import');
    });

    it('imports the book and renders the result without a page load', () => {
      // Pick the first real language rather than assuming a fixed ID.
      cy.get('select[name="LgID"] option')
        .not('[value=""]')
        .first()
        .then(($option) => {
          cy.get('select[name="LgID"]').select($option.val() as string);
        });

      cy.get('input[type="file"][name="thefile"]').selectFile(FIXTURE, { force: true });
      cy.get('button[type="submit"]').click();

      // The form is replaced by the client-rendered outcome.
      cy.get('.notification.is-success', { timeout: 15000 }).should('be.visible');
      cy.get('form[enctype="multipart/form-data"]').should('not.be.visible');
      cy.url().should('include', '/book/import');

      // Success links straight to the new book; use it to clean up.
      cy.get('a.button.is-primary[href^="/book/"]')
        .invoke('attr', 'href')
        .then((href) => {
          const bookId = String(href).replace('/book/', '');
          expect(bookId).to.match(/^\d+$/);
          deleteBook(bookId);
        });
    });
  });

  describe('API', () => {
    it('rejects an upload with no CSRF token', () => {
      // Deliberately a bare cy.request: cy.apiRequest would add the header.
      cy.request({
        method: 'POST',
        url: '/api/v1/books',
        failOnStatusCode: false,
        body: {}
      }).its('status').should('eq', 403);
    });

    it('reports a missing language as a handled failure', () => {
      cy.apiRequest({
        method: 'POST',
        url: '/api/v1/books',
        form: true,
        body: {},
        failOnStatusCode: false
      }).then((response) => {
        // A rejected write reports a 4xx, not a 200 carrying an error body.
        expect(response.status).to.eq(400);
        expect(response.body).to.have.property('success', false);
        expect(response.body).to.have.property('error').that.is.a('string');
      });
    });
  });
});
