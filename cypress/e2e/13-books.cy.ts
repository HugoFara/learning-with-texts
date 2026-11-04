/// <reference types="cypress" />

/**
 * The books list and detail pages render entirely from `/api/v1/books`; the
 * PHP views carry no book data. These specs cover what unit tests cannot: that
 * the CSP Alpine build can evaluate the components' bindings, and that
 * `DELETE /api/v1/books/{id}` is reachable through the endpoint registry —
 * that route sat behind a 405 while its handler was fully implemented.
 */
describe('Books', () => {
  const FIXTURE = 'cypress/fixtures/sample-book.epub';

  /**
   * Seed a book through the import form, which is the flow already proven to
   * survive CsrfMiddleware, and hand back its ID.
   *
   * @returns Chainable resolving to the new book's ID
   */
  const importFixtureBook = (): Cypress.Chainable<string> => {
    cy.visit('/book/import');
    cy.waitForAlpine();

    cy.get('select[name="LgID"] option')
      .not('[value=""]')
      .first()
      .then(($option) => {
        cy.get('select[name="LgID"]').select($option.val() as string);
      });

    cy.get('input[type="file"][name="thefile"]').selectFile(FIXTURE, { force: true });
    cy.get('button[type="submit"]').click();
    cy.get('.notification.is-success', { timeout: 15000 }).should('be.visible');

    return cy
      .get('a.button.is-primary[href^="/book/"]')
      .invoke('attr', 'href')
      .then((href) => String(href).replace('/book/', ''));
  };

  /**
   * Delete a book through the API route under test.
   *
   * @param bookId Book to remove
   */
  const deleteBook = (bookId: string) => {
    cy.apiRequest({
      method: 'DELETE',
      url: `/api/v1/books/${bookId}`,
      failOnStatusCode: false
    });
  };

  describe('The API the pages depend on', () => {
    it('accepts DELETE on a book rather than rejecting the method', () => {
      // A 405 here means Endpoints::ROUTES has drifted from BookApiHandler
      // again; any other status proves the route reaches its handler.
      cy.apiRequest({
        method: 'DELETE',
        url: '/api/v1/books/999999',
        failOnStatusCode: false
      }).then((response) => {
        expect(response.status).to.not.eq(405);
      });
    });

    it('accepts PUT on reading progress rather than rejecting the method', () => {
      cy.apiRequest({
        method: 'PUT',
        url: '/api/v1/books/999999/progress',
        body: { chapter: 1 },
        failOnStatusCode: false
      }).then((response) => {
        expect(response.status).to.not.eq(405);
      });
    });
  });

  describe('Books list', () => {
    it('ships a scaffold and a config blob, never rendered rows', () => {
      cy.request('/books').then((response) => {
        const html = String(response.body);
        expect(html).to.contain('book-list-config');
        expect(html).to.contain('x-data="bookList"');
        // Every row cell lives inside the x-for template, so the served HTML
        // holds bindings rather than values.
        expect(html).to.contain('x-for="book in books"');
        expect(html).to.contain('x-text="book.title"');
      });
    });

    it('renders the list client-side and deletes a row through the API', () => {
      importFixtureBook().then((bookId) => {
        cy.visit('/books');
        cy.waitForAlpine();

        // Rows exist only if the component fetched and rendered them.
        cy.get('table tbody tr', { timeout: 10000 }).should('have.length.at.least', 1);
        cy.contains('table tbody tr', 'EPUB').should('exist');

        cy.contains('table tbody tr', 'EPUB').find('button.is-danger').click();
        cy.get('.confirm-dialog .button.is-danger').click();

        cy.get('.notification.is-info', { timeout: 10000 }).should('be.visible');

        deleteBook(bookId);
      });
    });
  });

  describe('Book detail', () => {
    it('ships a scaffold and a config blob, never rendered chapters', () => {
      importFixtureBook().then((bookId) => {
        cy.request(`/book/${bookId}`).then((response) => {
          const html = String(response.body);
          expect(html).to.contain('book-detail-config');
          expect(html).to.contain('x-data="bookDetail"');
          expect(html).to.contain('x-for="chapter in chapters"');
        });

        deleteBook(bookId);
      });
    });

    it('renders the book and its chapters from the API', () => {
      importFixtureBook().then((bookId) => {
        cy.visit(`/book/${bookId}`);
        cy.waitForAlpine();

        // Title and chapter rows both arrive over the API.
        cy.get('h2.title', { timeout: 10000 }).should('not.have.text', '');
        cy.get('table tbody tr').should('have.length.at.least', 1);
        cy.get('a.button.is-primary').should('have.attr', 'href').and('include', '/read');

        deleteBook(bookId);
      });
    });
  });
});
