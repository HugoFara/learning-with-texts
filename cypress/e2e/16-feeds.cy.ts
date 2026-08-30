/// <reference types="cypress" />

/**
 * The server-rendered feed pages are retired.
 *
 * `/feeds` (browse.php), `/feeds/edit` and `/feeds/multi-load` all redirect to
 * the manager SPA, which renders everything from `/api/v1`. The edit-before-
 * import flow that used to keep browse.php alive now runs on two endpoints:
 * `POST /feeds/articles/extract` reads, `POST /feeds/articles/create-texts`
 * writes.
 */
describe('Feeds', () => {
  describe('retired pages', () => {
    const retired = ['/feeds', '/feeds/edit', '/feeds/multi-load'];

    retired.forEach((path) => {
      it(`${path} lands on the manager`, () => {
        cy.visit(path);
        cy.location('pathname').should('eq', '/feeds/manage');
      });
    });

    it('no longer ships a server-rendered article table', () => {
      cy.request('/feeds/manage').then((response) => {
        const html = String(response.body);
        // The manager is a scaffold: rows arrive from the API, so the only
        // <tr> in the markup are inside x-for templates and headers.
        expect(html).to.contain('x-for="article in articles"');
        expect(html).to.contain('feed-manager-app');
      });
    });

    it('keeps the auto-update loader reachable on its own route', () => {
      // The language page links here; it used to be /feeds?check_autoupdate=1.
      cy.request('/feeds/autoupdate').then((response) => {
        expect(response.status).to.eq(200);
        expect(String(response.body)).to.contain('feed-loader-config');
      });
    });
  });

  describe('article endpoints', () => {
    it('rejects extract with no articles selected', () => {
      cy.apiRequest({
        method: 'POST',
        url: '/api/v1/feeds/articles/extract',
        body: { article_ids: [] },
        failOnStatusCode: false
      }).then((response) => {
        expect(response.body.success).to.eq(false);
        expect(response.body.errors).to.include('No articles selected');
      });
    });

    it('refuses to create texts without a feed', () => {
      cy.apiRequest({
        method: 'POST',
        url: '/api/v1/feeds/articles/create-texts',
        body: { texts: [{ title: 'T', text: 'Body' }] },
        failOnStatusCode: false
      }).then((response) => {
        expect(response.body.success).to.eq(false);
        expect(response.body.created).to.eq(0);
      });
    });

    it('refuses to create texts for a feed the caller does not own', () => {
      // The ownership gate is what stops feed_links — which has no owner
      // column — from being written through by ID guessing.
      cy.apiRequest({
        method: 'POST',
        url: '/api/v1/feeds/articles/create-texts',
        body: {
          feed_id: 999999,
          texts: [{ title: 'T', text: 'Body' }]
        },
        failOnStatusCode: false
      }).then((response) => {
        expect(response.body.success).to.eq(false);
        expect(response.body.error).to.eq('Feed not found');
        expect(response.body.created).to.eq(0);
      });
    });

    it('is registered for POST, not only reachable in theory', () => {
      // A 405 here would mean Endpoints::ROUTES rejected the method before
      // the handler ever ran — the failure mode the books endpoints had.
      cy.apiRequest({
        method: 'POST',
        url: '/api/v1/feeds/articles/create-texts',
        body: {},
        failOnStatusCode: false
      }).then((response) => {
        expect(response.status).to.not.eq(405);
      });
    });
  });

  /**
   * The manual "add a feed" form and the edit form save through /api/v1 rather
   * than posting themselves (#262).
   */
  describe('feed forms', () => {
    it('creates a feed from the manual tab', () => {
      const name = `Manual Feed ${Date.now()}`;
      cy.intercept('POST', '**/api/v1/feeds').as('createFeed');

      cy.visit('/feeds/new');
      cy.contains('a, button, .is-clickable', /manual/i).click();

      cy.get('input[name="NfName"]').should('be.visible').type(name);
      cy.get('input[name="NfSourceURI"]').type('https://example.com/manual.xml');
      cy.get('input[name="NfArticleSectionTags"]').type('//div');
      cy.get('form').filter(':visible').contains('button[type="submit"]', /save/i).click();

      cy.wait('@createFeed').its('response.statusCode').should('eq', 200);
      cy.location('pathname').should('match', /\/feeds\/\d+\/edit/);
      cy.get('input[name="NfName"]').should('have.value', name);
    });

    it('saves an edit through the API', () => {
      const name = `Edit Feed ${Date.now()}`;
      const renamed = `${name} (renamed)`;

      cy.apiRequest({
        method: 'POST',
        url: '/api/v1/feeds',
        body: {
          langId: 1,
          name,
          sourceUri: 'https://example.com/edit.xml',
          articleSectionTags: '//div',
          filterTags: '',
          options: 'edit_text=1'
        }
      }).then((response) => {
        const feedId = response.body.feed.id;
        cy.intercept('PUT', `**/api/v1/feeds/${feedId}`).as('updateFeed');

        cy.visit(`/feeds/${feedId}/edit`);
        cy.get('input[name="NfName"]').should('have.value', name).clear();
        cy.get('input[name="NfName"]').type(renamed);
        cy.contains('button[type="submit"]', /update|save/i).click();

        // A form POST would never produce this request — and /feeds/{id}/edit
        // no longer accepts one.
        cy.wait('@updateFeed').its('response.statusCode').should('eq', 200);
        cy.location('pathname').should('eq', '/feeds/manage');

        cy.visit(`/feeds/${feedId}/edit`);
        cy.get('input[name="NfName"]').should('have.value', renamed);
      });
    });

    it('no longer accepts a form POST on the page routes', () => {
      cy.request({ method: 'POST', url: '/feeds/new', failOnStatusCode: false })
        .its('status')
        .should('eq', 404);
    });

  });

  /**
   * The wizard was four pages posting back to /feeds/wizard, with the parsed
   * feed and the fetched article held in $_SESSION between them. It is one
   * page now: the steps are panels, and the two server-side reads are API
   * calls. Walking it needs a live RSS URL, so what is asserted here is the
   * shape of the page and the endpoints behind it.
   */
  describe('wizard', () => {
    it('serves all four steps as one page', () => {
      cy.request('/feeds/wizard').then((response) => {
        const html = String(response.body);
        expect(html).to.contain('x-data="feedWizard"');
        expect(html).to.contain('feed-wizard-config');
        expect(html).to.contain('x-data="feedWizardStep1"');
        expect(html).to.contain('x-data="feedWizardStep2"');
        expect(html).to.contain('x-data="feedWizardStep3"');
        expect(html).to.contain('x-data="feedWizardStep4"');
      });
    });

    it('posts nothing back to the page routes', () => {
      cy.request('/feeds/wizard').then((response) => {
        const html = String(response.body);
        expect(html).to.not.contain('action="/feeds/wizard"');
        expect(html).to.not.contain('action="/feeds/edit"');
        expect(html).to.not.contain('name="save_feed"');
      });

      cy.request({ method: 'POST', url: '/feeds/wizard', failOnStatusCode: false })
        .its('status')
        .should('eq', 404);
    });

    it('carries the feed being reopened into the page config', () => {
      cy.request('/feeds/wizard?edit_feed=123').then((response) => {
        expect(String(response.body)).to.contain('"editFeedId":123');
      });
    });

    it('rejects a feed preview with no URL', () => {
      cy.apiRequest({
        method: 'POST',
        url: '/api/v1/feeds/wizard/preview',
        body: {},
        failOnStatusCode: false
      }).then((response) => {
        expect(response.body.success).to.eq(false);
        expect(response.body.error).to.eq('Feed URL is required');
      });
    });

    it('rejects an article preview with no URL', () => {
      cy.apiRequest({
        method: 'POST',
        url: '/api/v1/feeds/wizard/article',
        body: { index: 0 },
        failOnStatusCode: false
      }).then((response) => {
        expect(response.body.success).to.eq(false);
        expect(response.body.error).to.eq('Feed URL is required');
      });
    });

    /**
     * Walking the four steps needs a feed to walk, and reaching a real one
     * would make the test depend on someone else's uptime. The two reads are
     * stubbed instead; what is being tested here is the part no unit test
     * covers — that each step mounts, picks and hands over in one page, under
     * the CSP build of Alpine.
     */
    it('walks all four steps without leaving the page', () => {
      cy.intercept('POST', '**/api/v1/feeds/wizard/preview', {
        body: {
          success: true,
          title: 'Example News',
          articleSource: '',
          articleSources: ['description'],
          items: [
            { index: 0, title: 'First', link: 'https://news.example/1', host: 'news.example' },
            { index: 1, title: 'Second', link: 'https://news.example/2', host: 'news.example' }
          ]
        }
      }).as('preview');

      cy.intercept('POST', '**/api/v1/feeds/wizard/article', {
        body: {
          success: true,
          html: '<div id="wrap"><p id="body-text">Bonjour Manon.</p><aside id="ad">Ad</aside></div>'
        }
      }).as('article');

      cy.visit('/feeds/wizard');

      // Step 1: the URL tab reads the feed instead of posting.
      cy.contains('.tabs a', 'Enter Feed URL').click();
      cy.get('input[name="rss_url"]').type('https://news.example/rss');
      cy.get('form.validate').filter(':visible').find('button[type="submit"]').click();
      cy.wait('@preview');

      // Step 2: the article is on the page, and clicking it offers selectors.
      cy.wait('@article');
      cy.get('#lwt_article #body-text').should('be.visible');
      // A step that mounts after the page's one icon pass still gets icons.
      cy.get('.wizard-controls svg').should('exist');
      cy.get('.wizard-controls i[data-lucide]').should('not.exist');
      cy.get('select[name="selected_feed"] option').should('have.length', 2);
      cy.get('#lwt_article #body-text').click();
      cy.get('select[name="mark_action"] option').should('have.length.greaterThan', 1);
      cy.get('select[name="mark_action"]').select(1);
      // Scoped: the advanced panel carries a hidden "Get" of its own.
      cy.get('.wizard-controls').contains('button', 'Get').click();
      cy.get('#lwt_sel li').should('have.length', 1);
      cy.get('.wizard-controls').contains('button', 'Next').click();

      // Step 3: the same article, now for picking what to drop. The panel
      // scrolls inside #lwt_container, so existence is the assertion.
      cy.contains('Elements to filter out').should('exist');
      cy.get('#lwt_sel li').should('have.length', 0);
      cy.get('.wizard-controls').contains('button', 'Next').click();

      // Step 4: the picked selectors arrive in the save form, and the
      // language is named rather than asked for.
      cy.get('select[name="NfLgID"]').should('not.exist');
      cy.get('input[name="NfSourceURI"]').should('have.value', 'https://news.example/rss');
      cy.get('input[name="NfName"]').should('have.value', 'Example News');
      cy.get('input[name="NfArticleSectionTags"]').should('not.have.value', '');

      // One page throughout: the URL never moved.
      cy.location('pathname').should('eq', '/feeds/wizard');
    });
  });
});
