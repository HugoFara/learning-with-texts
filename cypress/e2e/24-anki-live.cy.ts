/// <reference types="cypress" />

/**
 * Pulling reviews from a real Anki (#264), not a stub.
 *
 * Needs Anki with AnkiConnect listening on 127.0.0.1:8765 and sharing
 * cypress/anki-exchange with this machine as /exchange. The repo builds one:
 *
 *   npm run anki:build && npm run anki:up     (podman; see scripts/anki/container)
 *
 * Without it every test here is skipped, so CI and a plain checkout are
 * unaffected. With it, this is the loop a learner runs: export from LWT, study
 * in Anki, pull the reviews back on /vocabulary/anki/sync.
 */

const apiBase = '/api/v1';
const anki = 'http://127.0.0.1:8765';

function unwrap<T>(body: unknown): T {
  const wrapped = body as { data?: T };
  return (wrapped && typeof wrapped === 'object' && 'data' in wrapped ? wrapped.data : body) as T;
}

/** Call AnkiConnect directly, failing the test on an AnkiConnect error. */
function ankiConnect<T>(action: string, params: Record<string, unknown> = {}): Cypress.Chainable<T> {
  return cy
    .request({ method: 'POST', url: anki, body: { action, version: 6, params } })
    .then((response) => {
      const body = response.body as { result: T; error: string | null };
      expect(body.error, `AnkiConnect ${action}`).to.eq(null);
      return body.result;
    });
}

/** How many of these terms are due by the end of tomorrow, as the review page counts them. */
function dueByTomorrow(termIds: number[]): Cypress.Chainable<number> {
  return cy
    .request(`${apiBase}/review/tomorrow-count?review_key=words&selection=${termIds.join(',')}`)
    .then((response) => unwrap<{ count: number }>(response.body).count);
}

describe('Sync reviews with a real Anki', () => {
  let ankiUp = false;
  let languageId = 0;
  const termIds: number[] = [];
  let noteIds: number[] = [];
  let deckName = '';
  let cardIds: number[] = [];

  before(() => {
    cy.request({ method: 'POST', url: anki, body: { action: 'version', version: 6 }, failOnStatusCode: false, timeout: 3000 })
      .then((response) => {
        ankiUp = response.status === 200 && (response.body as { result?: number }).result === 6;
      }, () => {
        ankiUp = false;
      });
  });

  beforeEach(function () {
    if (!ankiUp) {
      cy.log('AnkiConnect not reachable on 127.0.0.1:8765; run npm run anki:up');
      this.skip();
    }
  });

  after(() => {
    if (!ankiUp) return;
    if (noteIds.length > 0) {
      ankiConnect('deleteNotes', { notes: noteIds });
    }
    if (deckName !== '') {
      ankiConnect('deleteDecks', { decks: [deckName], cardsToo: true });
    }
    termIds.forEach((id) => {
      cy.apiRequest({ method: 'DELETE', url: `${apiBase}/terms/${id}`, failOnStatusCode: false });
    });
    if (languageId > 0) {
      cy.apiRequest({ method: 'DELETE', url: `${apiBase}/languages/${languageId}`, failOnStatusCode: false });
    }
  });

  it('brings Anki answers into LWT and sends LWT answers to Anki, once each way', () => {
    const name = `E2E Anki ${Date.now()}`;
    const file = 'lwt-e2e.apkg';

    // 1. Terms in LWT, exported as an Anki package into the shared folder.
    cy.apiRequest({ method: 'POST', url: `${apiBase}/languages`, body: { name } }).then((response) => {
      languageId = unwrap<{ id: number }>(response.body).id;
      ['uno', 'dos', 'tres'].forEach((text) => {
        cy.apiRequest({
          method: 'POST',
          url: `${apiBase}/terms/for-language`,
          body: { language_id: languageId, text, translation: `${text} meaning`, status: 1 },
        }).then((termResponse) => {
          termIds.push(unwrap<{ id: number }>(termResponse.body).id);
        });
      });
    });
    cy.then(() =>
      cy.request({ url: `/vocabulary/apkg/export?lang_id=${languageId}`, encoding: 'binary' })
        .then((response) => cy.writeFile(`cypress/anki-exchange/${file}`, response.body, 'binary'))
    );

    // 2. Imported into Anki, which now holds one LWT note per term.
    cy.then(() => ankiConnect('importPackage', { path: `/exchange/${file}` }));
    cy.then(() =>
      ankiConnect<number[]>('findNotes', { query: '"note:LWT Term"' }).then((all) =>
        ankiConnect<{ noteId: number; cards: number[]; fields: Record<string, { value: string }> }[]>(
          'notesInfo',
          { notes: all }
        ).then((notes) => {
          const ours = notes.filter((n) => termIds.includes(Number(n.fields.LwtId?.value)));
          expect(ours, 'one Anki note per exported term').to.have.length(3);
          noteIds = ours.map((n) => n.noteId);
          // In term order, so the grades below land on the terms named later.
          ours.sort((a, b) => termIds.indexOf(Number(a.fields.LwtId.value)) - termIds.indexOf(Number(b.fields.LwtId.value)));
          const cards = ours.map((n) => n.cards[0]);
          cardIds = cards;
          ankiConnect<{ deckName: string }[]>('cardsInfo', { cards }).then((info) => {
            deckName = info[0].deckName;
          });

          // 3. Studied in Anki: Good, Again, Easy.
          ankiConnect<boolean[]>('answerCards', {
            answers: cards.map((cardId, i) => ({ cardId, ease: [3, 1, 4][i] })),
          }).then((answered) => expect(answered).to.deep.equal([true, true, true]));
        })
      )
    );

    // Before the pull, all three new terms fall due by tomorrow.
    cy.then(() => dueByTomorrow(termIds).then((count) => expect(count, 'due before the pull').to.eq(3)));

    // 4. Synced through the page.
    cy.intercept('POST', '**/api/v1/anki/reviews').as('pull');
    cy.intercept('POST', '**/api/v1/anki/push-plan').as('plan');
    cy.visit('/vocabulary/anki/sync');
    cy.waitForAlpine();
    cy.get('[x-data="ankiSync"] button.is-primary').click();
    cy.wait('@pull').then(({ response }) => {
      const summary = unwrap<{ applied: number; terms: number; duplicates: number }>(response?.body);
      expect(summary.applied, 'reviews merged').to.eq(3);
      expect(summary.terms, 'terms rescheduled').to.eq(3);
    });
    // LWT had nothing Anki lacks: everything it holds came from Anki.
    cy.wait('@plan').then(({ response }) => {
      const plan = unwrap<{ reviews: unknown[]; cards: unknown[] }>(response?.body);
      expect(plan.reviews, 'nothing to send back yet').to.have.length(0);
    });
    cy.get('[x-data="ankiSync"] .notification.is-success').should('be.visible');

    // The answers now decide the schedule: the term answered Again in Anki is
    // still due by tomorrow, the one answered Easy is pushed out.
    cy.then(() => {
      const [, againTerm, easyTerm] = termIds;
      dueByTomorrow([againTerm]).then((count) => expect(count, 'Again term due').to.eq(1));
      dueByTomorrow([easyTerm]).then((count) => expect(count, 'Easy term due').to.eq(0));
    });

    // 5. Studied in LWT: the first term, answered Easy here.
    cy.then(() =>
      cy.apiRequest({ method: 'PUT', url: `${apiBase}/review/status`, body: { term_id: termIds[0], grade: 4 } })
    );

    // 6. The next sync sends that review and the schedule it led to.
    let planned: { card_id: number; due_days: number; ivl: number } | undefined;
    cy.get('[x-data="ankiSync"] button.is-primary').click();
    cy.wait('@pull').then(({ response }) => {
      const summary = unwrap<{ applied: number; duplicates: number }>(response?.body);
      expect(summary.applied, 'nothing new from Anki').to.eq(0);
      expect(summary.duplicates).to.eq(3);
    });
    cy.wait('@plan').then(({ response }) => {
      const plan = unwrap<{ reviews: number[][]; cards: { card_id: number; due_days: number; ivl: number }[] }>(
        response?.body
      );
      expect(plan.reviews, 'the LWT review').to.have.length(1);
      expect(plan.reviews[0][1]).to.eq(cardIds[0]);
      expect(plan.reviews[0][3], 'its grade').to.eq(4);
      expect(plan.cards).to.have.length(1);
      planned = plan.cards[0];
    });
    // The page writes to Anki after the plan arrives; wait for it to finish.
    cy.get('[x-data="ankiSync"] button.is-primary').should('not.be.disabled');
    cy.get('[x-data="ankiSync"] .notification.is-success').should('be.visible');

    // Anki now has it in the card's history, and LWT's schedule on the card.
    cy.then(() => {
      ankiConnect<Record<string, { ease: number }[]>>('getReviewsOfCards', { cards: [String(cardIds[0])] })
        .then((byCard) => {
          // setDueDate adds Anki's own grade-less "rescheduled" row (ease 0),
          // which the pull ignores; the answers are the Anki one and LWT's.
          const answers = byCard[String(cardIds[0])].filter((row) => row.ease > 0);
          // Order is not checked: LWT keeps seconds, so its answer's id can sort
          // before an Anki answer given earlier within the same second.
          expect(answers.map((row) => row.ease), 'Anki answer and LWT answer').to.have.members([3, 4]);
        });
      ankiConnect<{ interval: number; reps: number; type: number }[]>('cardsInfo', { cards: [cardIds[0]] })
        .then(([card]) => {
          expect(card.type, 'a review card').to.eq(2);
          expect(card.interval, 'LWT interval').to.eq(planned?.ivl);
          expect(card.reps, 'both answers counted').to.eq(2);
        });
    });

    // 7. And a sync right after changes nothing in either direction.
    cy.get('[x-data="ankiSync"] button.is-primary').click();
    cy.wait('@pull').then(({ response }) => {
      expect(unwrap<{ applied: number }>(response?.body).applied).to.eq(0);
    });
    cy.wait('@plan').then(({ response }) => {
      expect(unwrap<{ reviews: unknown[] }>(response?.body).reviews).to.have.length(0);
    });
  });
});
