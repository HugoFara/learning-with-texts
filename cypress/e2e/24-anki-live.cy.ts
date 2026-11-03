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
  /** Further languages and decks the note test creates, for cleanup. */
  const languageIds: number[] = [];
  const extraDecks: string[] = [];

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
    // Tolerant: a test may already have deleted some of these.
    const quietly = (action: string, params: Record<string, unknown>) =>
      cy.request({ method: 'POST', url: anki, body: { action, version: 6, params }, failOnStatusCode: false });
    if (noteIds.length > 0) {
      quietly('deleteNotes', { notes: noteIds });
    }
    [deckName, ...extraDecks].filter((d) => d !== '').forEach((deck) => {
      quietly('deleteDecks', { decks: [deck], cardsToo: true });
    });
    termIds.forEach((id) => {
      cy.apiRequest({ method: 'DELETE', url: `${apiBase}/terms/${id}`, failOnStatusCode: false });
    });
    // A sync may have created terms the test never saw (a run that failed
    // part-way), and a language with terms cannot be deleted: empty each first.
    [languageId, ...languageIds].filter((id) => id > 0).forEach((id) => {
      cy.request(`${apiBase}/terms/list?lang=${id}&per_page=500`).then((r) => {
        unwrap<{ words: { id: number }[] }>(r.body).words.forEach((word) => {
          cy.apiRequest({ method: 'DELETE', url: `${apiBase}/terms/${word.id}`, failOnStatusCode: false });
        });
      });
      cy.apiRequest({ method: 'DELETE', url: `${apiBase}/languages/${id}`, failOnStatusCode: false });
    });
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

  // Step 3: the notes themselves. A second throwaway language, so its deck
  // and terms are this test's alone.
  it('syncs fields, tags, suspension, additions and deletions both ways', () => {
    const name = `E2E Notes ${Date.now()}`;
    const deck = `LWT::${name}`;
    const file = 'lwt-e2e-notes.apkg';
    let langId = 0;
    const ids: Record<string, number> = {};
    const noteOf: Record<string, number> = {};
    const cardOf: Record<string, number> = {};

    interface NoteInfo { noteId: number; cards: number[]; tags: string[]; fields: Record<string, { value: string }> }
    const ourNotes = (): Cypress.Chainable<NoteInfo[]> =>
      ankiConnect<number[]>('findNotes', { query: `"deck:${deck}"` })
        .then((found) => ankiConnect<NoteInfo[]>('notesInfo', { notes: found }));
    const notesPlan = (): Cypress.Chainable<{ summary: Record<string, number>; conflicts: unknown[] }> =>
      ourNotes().then((notes) =>
        ankiConnect<{ cardId: number; deckName: string; queue: number }[]>('cardsInfo', { cards: notes.map((n) => n.cards[0]) })
          .then((cards) => cy.apiRequest({
            method: 'POST',
            url: `${apiBase}/anki/notes-plan`,
            body: {
              decks: [deck],
              notes: notes.map((n, i) => ({
                note_id: n.noteId,
                card_id: cards[i].cardId,
                deck: cards[i].deckName,
                fields: Object.fromEntries(Object.entries(n.fields).map(([k, v]) => [k, v.value])),
                tags: n.tags,
                suspended: cards[i].queue === -1,
                last_review: null,
              })),
            },
          }).then((r) => unwrap<{ summary: Record<string, number>; conflicts: unknown[] }>(r.body)))
      );
    const sync = (): void => {
      cy.get('[x-data="ankiSync"] button.is-primary').click();
      cy.get('[x-data="ankiSync"] button.is-primary').should('not.be.disabled');
      cy.get('[x-data="ankiSync"] .notification.is-success').should('be.visible');
    };

    // Three terms in LWT, exported and imported into Anki.
    cy.apiRequest({ method: 'POST', url: `${apiBase}/languages`, body: { name } }).then((response) => {
      langId = unwrap<{ id: number }>(response.body).id;
      languageIds.push(langId);
      ['uno', 'dos', 'tres'].forEach((text) => {
        cy.apiRequest({
          method: 'POST',
          url: `${apiBase}/terms/for-language`,
          body: { language_id: langId, text, translation: `${text} meaning`, status: 2 },
        }).then((r) => {
          ids[text] = unwrap<{ id: number }>(r.body).id;
          termIds.push(ids[text]);
        });
      });
    });
    cy.then(() =>
      cy.request({ url: `/vocabulary/apkg/export?lang_id=${langId}`, encoding: 'binary' })
        .then((response) => cy.writeFile(`cypress/anki-exchange/${file}`, response.body, 'binary'))
    );
    cy.then(() => ankiConnect('importPackage', { path: `/exchange/${file}` }));
    cy.then(() => ourNotes().then((notes) => {
      for (const n of notes) {
        const text = n.fields.Term.value;
        noteOf[text] = n.noteId;
        cardOf[text] = n.cards[0];
        noteIds.push(n.noteId);
      }
      extraDecks.push(deck);
    }));

    // First sync after the export: both sides agree; nothing moves.
    cy.visit('/vocabulary/anki/sync');
    cy.waitForAlpine();
    sync();
    cy.then(() => notesPlan().then((plan) => {
      expect(plan.summary.to_anki + plan.summary.to_lwt, 'in step after the first sync').to.eq(0);
      expect(plan.conflicts).to.have.length(0);
    }));

    // Edits on both sides, and an addition on each.
    cy.then(() => {
      ankiConnect('updateNoteFields', { note: { id: noteOf.uno, fields: { Notes: 'edited in Anki' } } });
      ankiConnect('addTags', { notes: [noteOf.dos], tags: 'from-anki' });
      ankiConnect('suspend', { cards: [cardOf.tres] });
      ankiConnect<number>('addNote', {
        note: { deckName: deck, modelName: 'LWT Term', fields: { Term: 'cuatro', Translation: 'four' } },
      }).then((id) => { noteIds.push(id); });
      cy.apiRequest({ method: 'PUT', url: `${apiBase}/terms/${ids.dos}/translation`, body: { translation: 'two (LWT)' } });
      cy.apiRequest({
        method: 'POST',
        url: `${apiBase}/terms/for-language`,
        body: { language_id: langId, text: 'cinco', translation: 'five', status: 1 },
      }).then((r) => { termIds.push(unwrap<{ id: number }>(r.body).id); });
    });

    sync();

    // Anki got LWT's changes; the new LWT term is a note with its id.
    cy.then(() => cy.request(`${apiBase}/terms/${ids.dos}`).then((r) => ourNotes().then((notes) => {
      const byText = Object.fromEntries(notes.map((n) => [n.fields.Term.value, n]));
      // The translation endpoint adds to the existing translation; Anki must
      // hold whatever LWT now has.
      const lwtTranslation = unwrap<{ translation: string }>(r.body).translation;
      expect(lwtTranslation).to.contain('two (LWT)');
      expect(byText.dos.fields.Translation.value).to.eq(lwtTranslation);
      expect(byText.dos.tags).to.include('from-anki');
      expect(byText.cinco, 'LWT term added to Anki').to.not.eq(undefined);
      expect(Number(byText.cinco.fields.LwtId.value)).to.be.greaterThan(0);
      expect(Number(byText.cuatro.fields.LwtId.value), 'Anki note now names its new term').to.be.greaterThan(0);
      noteIds.push(byText.cinco.noteId);
      termIds.push(Number(byText.cuatro.fields.LwtId.value));
    })));
    // LWT got Anki's: the suspended card ignores its term ...
    cy.then(() => cy.request(`${apiBase}/terms/${ids.tres}`).then((r) => {
      expect(unwrap<{ status: number }>(r.body).status, 'suspended in Anki').to.eq(98);
    }));
    // ... and everything else matches: a plan from Anki's state moves nothing.
    cy.then(() => notesPlan().then((plan) => {
      expect(plan.summary.to_anki, 'nothing left for Anki').to.eq(0);
      expect(plan.summary.to_lwt, 'nothing left for LWT').to.eq(0);
      expect(plan.summary.added_to_anki + plan.summary.added_to_lwt).to.eq(0);
      expect(plan.conflicts).to.have.length(0);
    }));

    // A note deleted in Anki: the term is only deleted once confirmed.
    cy.then(() => ankiConnect('deleteNotes', { notes: [noteOf.uno] }));
    sync();
    cy.get('[x-data="ankiSync"] .notification.is-danger').should('contain', 'uno');
    cy.then(() => cy.request(`${apiBase}/terms/${ids.uno}`).then((r) => {
      expect(unwrap<{ id?: number }>(r.body).id, 'still there before confirming').to.eq(ids.uno);
    }));
    cy.get('[x-data="ankiSync"] .notification.is-danger button').click();
    cy.get('[x-data="ankiSync"] button.is-primary').should('not.be.disabled');
    cy.then(() => cy.request({ url: `${apiBase}/terms/${ids.uno}`, failOnStatusCode: false }).then((r) => {
      expect(r.status, 'deleted once confirmed').to.be.oneOf([400, 404]);
      expect(JSON.stringify(r.body)).to.contain('not found');
    }));
  });
});
