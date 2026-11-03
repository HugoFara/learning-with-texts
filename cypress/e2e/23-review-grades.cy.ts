/// <reference types="cypress" />

/**
 * A real review session through the four FSRS grades (#238, phase 2b).
 *
 * The unit tests pin the scheduler against py-fsrs reference vectors, and the
 * API tests pin the endpoint. Neither proves the review screen sends what the
 * user pressed, or that the answer reaches the term's schedule: the 2a shadow
 * write once never fired from the review SPA at all, because the client took
 * a different code path than the tests did. This drives the screen itself.
 *
 * It works in its own throwaway language so the queue holds exactly the four
 * terms it created, whatever the demo data contains.
 */

const apiBase = '/api/v1';

/** The four grades, in the order the buttons show them (title "… (n)"). */
const GRADES = [1, 2, 3, 4] as const;

interface Intervals {
  [grade: string]: number;
}

function unwrap<T>(body: unknown): T {
  // Endpoints answer either { data: … } or the payload itself.
  const wrapped = body as { data?: T };
  return (wrapped && typeof wrapped === 'object' && 'data' in wrapped ? wrapped.data : body) as T;
}

function intervalsOf(termId: number): Cypress.Chainable<Intervals> {
  return cy
    .request(`${apiBase}/review/intervals?term_id=${termId}`)
    .then((response) => unwrap<{ intervals: Intervals }>(response.body).intervals);
}

describe('Review session with the four grades', () => {
  let languageId = 0;
  const termIds: number[] = [];
  const initial: Record<number, Intervals> = {};

  before(() => {
    cy.apiRequest({
      method: 'POST',
      url: `${apiBase}/languages`,
      body: { name: `E2E Review ${Date.now()}` },
    }).then((response) => {
      const created = unwrap<{ success: boolean; id: number }>(response.body);
      expect(created.success, 'language created').to.eq(true);
      languageId = created.id;

      ['alpha', 'bravo', 'charlie', 'delta'].forEach((text) => {
        cy.apiRequest({
          method: 'POST',
          url: `${apiBase}/terms/for-language`,
          body: { language_id: languageId, text, translation: `${text} meaning`, status: 1 },
        }).then((termResponse) => {
          const term = unwrap<{ success: boolean; id: number }>(termResponse.body);
          expect(term.success, `term ${text} created`).to.eq(true);
          const id = Number(term.id);
          expect(id, `term ${text} id`).to.be.greaterThan(0);
          termIds.push(id);
          intervalsOf(id).then((intervals) => {
            initial[id] = intervals;
          });
        });
      });
    });

    // A new status-1 term falls due tomorrow, and no grade schedules sooner
    // than a day. Learned two days ago, the four are due now.
    cy.then(() => {
      cy.exec(`php cypress/support/backdate-terms.php 2 ${termIds.join(' ')}`)
        .its('stdout')
        .should('contain', '4');
    });
  });

  after(() => {
    // A language with terms cannot be deleted, so the terms go first.
    termIds.forEach((id) => {
      cy.apiRequest({ method: 'DELETE', url: `${apiBase}/terms/${id}`, failOnStatusCode: false });
    });
    if (languageId > 0) {
      cy.apiRequest({
        method: 'DELETE',
        url: `${apiBase}/languages/${languageId}`,
        failOnStatusCode: false,
      }).then((response) => {
        expect(unwrap<{ success: boolean }>(response.body).success, 'test language removed').to.eq(true);
      });
    }
  });

  it('sends each grade the user presses and schedules the term by it', () => {
    const graded: Record<number, number> = {};

    cy.intercept('PUT', '**/api/v1/review/status').as('grade');
    cy.visit(`/review?lang=${languageId}`);
    cy.waitForAlpine();

    GRADES.forEach((grade) => {
      cy.get('.review-word-area').should('be.visible');
      cy.get('.review-word-area button.is-primary.is-large').filter(':visible').click();
      cy.get(`.review-word-area button[title$="(${grade})"]`).filter(':visible').click();

      cy.wait('@grade').then(({ request, response }) => {
        const body = (typeof request.body === 'string'
          ? JSON.parse(request.body)
          : request.body) as { term_id: number; grade: number };
        expect(body.grade, 'grade sent').to.eq(grade);
        expect(termIds, 'a term of this session').to.include(body.term_id);
        expect(response?.statusCode).to.eq(200);
        expect(unwrap<{ error?: string }>(response?.body).error, 'no error').to.eq(undefined);
        // Again puts a term back in today's queue; keep its first grade.
        graded[body.term_id] ??= grade;
      });
    });

    cy.then(() => {
      const easy = Number(Object.keys(graded).find((id) => graded[Number(id)] === 4));
      const again = Number(Object.keys(graded).find((id) => graded[Number(id)] === 1));
      expect(easy, 'a term graded Easy').to.be.greaterThan(0);
      expect(again, 'a term graded Again').to.be.greaterThan(0);

      // What Good would schedule next follows the memory state the grade left:
      // Easy raises stability, Again does not.
      intervalsOf(easy).then((after) => {
        expect(after['3'], 'Good interval after Easy').to.be.greaterThan(initial[easy]['3']);
      });
      intervalsOf(again).then((after) => {
        expect(after['3'], 'Good interval after Again').to.be.at.most(initial[again]['3']);
      });
    });
  });
});
