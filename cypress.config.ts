import { defineConfig } from 'cypress';

export default defineConfig({
  e2e: {
    baseUrl: 'http://localhost:8000',
    setupNodeEvents(on) {
      on('task', {
        // In Node, because cy.request() fails the test on a refused connection.
        ankiConnectVersion(url: string): Promise<number | null> {
          return fetch(url, {
            method: 'POST',
            body: JSON.stringify({ action: 'version', version: 6 }),
            signal: AbortSignal.timeout(3000)
          })
            .then((response) => response.json())
            .then((body: { result?: number }) => body.result ?? null)
            .catch(() => null);
        }
      });
    },
    supportFile: 'cypress/support/e2e.ts',
    specPattern: 'cypress/e2e/**/*.cy.ts',
    viewportWidth: 1280,
    viewportHeight: 720,
    defaultCommandTimeout: 10000,
    video: false,
    screenshotOnRunFailure: true,
  },
});
