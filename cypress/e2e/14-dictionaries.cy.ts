/// <reference types="cypress" />

/**
 * The local-dictionary table renders from `GET /local-dictionaries`, and the
 * enable/disable and delete actions that used to be same-origin form POSTs now
 * go through the API. These specs cover what unit tests cannot: that the CSP
 * Alpine build evaluates the row bindings, and that both mutations round-trip
 * through CsrfMiddleware.
 */
describe('Local dictionaries', () => {
  const LANG_ID = 2;

  /**
   * Create a dictionary to render, returning its ID.
   *
   * @param name Dictionary name
   */
  const createDictionary = (name: string): Cypress.Chainable<number> => {
    return cy
      .apiRequest({
        method: 'POST',
        url: '/api/v1/local-dictionaries',
        body: {
          language_id: LANG_ID,
          name,
          description: 'created by cypress',
          source_format: 'csv'
        }
      })
      .then((response) => response.body.dictionary.id as number);
  };

  /**
   * Remove a dictionary so re-runs start clean.
   *
   * @param dictId Dictionary to delete
   */
  const deleteDictionary = (dictId: number) => {
    cy.apiRequest({
      method: 'DELETE',
      url: `/api/v1/local-dictionaries/${dictId}`,
      failOnStatusCode: false
    });
  };

  it('ships a scaffold and a config blob, never rendered rows', () => {
    cy.request(`/languages/${LANG_ID}/dictionaries`).then((response) => {
      const html = String(response.body);
      expect(html).to.contain('dictionary-list-config');
      expect(html).to.contain('x-data="dictionaryList"');
      expect(html).to.contain('x-for="dict in dictionaries"');
    });
  });

  it('renders the table from the API', () => {
    createDictionary('Cypress Dict').then((dictId) => {
      cy.visit(`/languages/${LANG_ID}/dictionaries`);
      cy.waitForAlpine();

      cy.contains('table tbody tr', 'Cypress Dict', { timeout: 10000 }).should('exist');
      cy.contains('table tbody tr', 'Cypress Dict').should('contain.text', 'CSV');

      deleteDictionary(dictId);
    });
  });

  it('toggles enabled state through the API', () => {
    createDictionary('Toggle Dict').then((dictId) => {
      cy.visit(`/languages/${LANG_ID}/dictionaries`);
      cy.waitForAlpine();

      // Starts enabled; one click must flip the status tag.
      cy.contains('table tbody tr', 'Toggle Dict').find('.tag.is-success').should('exist');
      cy.contains('table tbody tr', 'Toggle Dict').find('button.is-warning').click();
      cy.contains('table tbody tr', 'Toggle Dict')
        .find('.tag.is-warning', { timeout: 10000 })
        .should('exist');

      // And the change must have reached the server, not just the DOM.
      cy.apiRequest({ url: `/api/v1/local-dictionaries/${dictId}` }).then((response) => {
        expect(response.body.enabled).to.eq(false);
      });

      deleteDictionary(dictId);
    });
  });

  it('deletes a dictionary through the API', () => {
    createDictionary('Doomed Dict').then((dictId) => {
      cy.visit(`/languages/${LANG_ID}/dictionaries`);
      cy.waitForAlpine();

      // Declining leaves it in place.
      cy.contains('table tbody tr', 'Doomed Dict').find('button.is-danger').click();
      cy.get('.confirm-dialog').contains('button', 'Cancel').click();
      cy.get('.confirm-dialog').should('not.exist');
      cy.contains('table tbody tr', 'Doomed Dict').should('exist');

      cy.contains('table tbody tr', 'Doomed Dict').find('button.is-danger').click();
      cy.get('.confirm-dialog .button.is-danger').click();

      cy.contains('table tbody tr', 'Doomed Dict').should('not.exist');

      cy.apiRequest({
        url: `/api/v1/local-dictionaries?language_id=${LANG_ID}`
      }).then((response) => {
        const names = response.body.dictionaries.map((d: { name: string }) => d.name);
        expect(names).to.not.include('Doomed Dict');
      });

      deleteDictionary(dictId);
    });
  });
});
