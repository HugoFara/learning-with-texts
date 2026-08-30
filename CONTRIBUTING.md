# Contributing to LWT

This guide is mainly aimed at developers, but it can give useful insights on how LWT is structured, which could help with debugging. The first step you need to take is to clone LWT from the official GitHub repository ([HugoFara/lwt](https://github.com/HugoFara/lwt)).

## Prerequisites

### Composer (PHP)

Getting [Composer](https://getcomposer.org/download/) is required for PHP development (testing, static analysis). Go to the lwt folder and run:

```bash
composer install --dev
```

### Node.js and npm (Frontend)

[Node.js](https://nodejs.org/) is required for frontend development (JavaScript/TypeScript, CSS). Install dependencies with:

```bash
npm install
```

This is required for building frontend assets, running the dev server, and type checking.

## Create and Edit Themes

Themes are stored at `src/frontend/css/themes/`. Each theme is a folder containing CSS overrides and optional assets.

### Creating a New Theme

1. Create a new folder in `src/frontend/css/themes/` with your theme name (use underscores for spaces, e.g., `My_Theme`)

2. Create a `theme.json` file with metadata:

   ```json
   {
       "name": "My Theme",
       "description": "A brief description of what this theme changes.",
       "mode": "light",
       "highlighting": "Description of word highlighting style",
       "wordBreaking": "Standard"
   }
   ```

   | Field | Description |
   |-------|-------------|
   | `name` | Display name shown in settings |
   | `description` | Explains what the theme changes |
   | `mode` | `"light"` or `"dark"` |
   | `highlighting` | How words are highlighted (e.g., "Background color", "Underline") |
   | `wordBreaking` | Word wrapping behavior (e.g., "Standard", "Modified") |

3. Create a `styles.css` file with your CSS overrides. Key classes to customize:

   ```css
   /* Status colors for word learning stages */
   .status0 { /* Unknown words */ }
   .status1 { /* Learning stage 1 */ }
   .status2 { /* Learning stage 2 */ }
   .status3 { /* Learning stage 3 */ }
   .status4 { /* Learning stage 4 */ }
   .status5 { /* Learned */ }
   .status98 { /* Ignored */ }
   .status99 { /* Well-known */ }

   /* General styling */
   body { background-color: #fff; color: #000; }
   ```

4. Build your theme:

   ```bash
   npm run build:themes
   ```

### Add Images to your Themes

You can include images in your theme:

* Use images from `assets/images/` with path `../../../assets/images/theimage.png`
* Add your own files to your theme folder and reference with `./myimage.png`

### Theme Fallback System

When LWT looks for a file in `dist/themes/{{Theme}}/`, it checks if the file exists. If not, it falls back to `dist/css/`. This means your themes **only need to override files you want to change** - you don't need to copy all files from `src/frontend/css/base/`.

### Existing Themes

| Theme | Mode | Description |
|-------|------|-------------|
| Default | Light | Standard theme with background color highlighting |
| Dark | Dark | Dark theme, easy on the eyes for nighttime reading |
| Dark Muted | Dark | Dark theme with white highlighted text |
| Flex Wrap | Light | Modified word breaking rules |
| Underline | Light | Subtle underline highlighting |
| Underline Dark | Dark | Dark version of Underline |

## Frontend Development (JavaScript/TypeScript)

LWT uses TypeScript for frontend code, built with Vite.

### Source Files

TypeScript source files are in `src/frontend/js/`:

* `main.ts` - Vite entry point
* `pgm.ts` - Core utilities
* `jq_pgm.ts` - jQuery-dependent functions
* `text_events.ts` - Text reading interface
* `audio_controller.ts` - Audio playback
* And more...

### Development Workflow

1. Start the dev server with Hot Module Replacement:

   ```bash
   npm run dev
   ```

2. Check TypeScript types:

   ```bash
   npm run typecheck
   ```

3. Build for production:

   ```bash
   npm run build
   ```

### Build Commands

| Command | Description |
|---------|-------------|
| `npm run dev` | Start Vite dev server with HMR |
| `npm run build` | Build Vite JS/CSS bundles |
| `npm run build:themes` | Build theme CSS files |
| `npm run build:all` | Build everything (Vite + themes) |
| `npm run typecheck` | Run TypeScript type checking |
| `composer build` | Alias for `npm run build:all` |

## Edit PHP code

The PHP codebase structure:

* `index.php` - Front controller entry point
* `src/backend/Controllers/` - MVC Controllers
* `src/backend/Services/` - Business logic services
* `src/backend/Views/` - View templates
* `src/backend/Core/` - Core modules (database, utilities)
* `src/backend/Api/` - REST API handlers

### Testing your Code

It is highly advised to test your code. Tests should be wrote under ``tests/``. We use PHP Unit for testing.

To run all tests with coverage (requires Xdebug):

```bash
composer test
```

To run tests without coverage (faster):

```bash
composer test:no-coverage
```

Coverage reports are generated in `coverage-report/index.html`.

### Security Check

We use Psalm to find code flaws and inconsistencies. Use ``./vendor/bin/psalm``.

You can configure the reporting level in ``psalm.xml``.

### Advice: Follow the Code Style Standards

Nobody likes to debug unreadable code. A good way to avoid thinking about it is to include phpcs directly in your IDE. You can also download it and run it regularly on your code.

You can run it through composer. Use ``php ./vendor/bin/squizlabs/phpcs.phar [filename]`` to see style violations on a file. You can fix them using

```bash
php ./vendor/bin/squizlabs/phpcbf.phar [filename]
```

## Interact with and modify the REST API

Starting from 2.9.0-fork, LWT provides a RESTful API. The main handler for the API is `api.php`.
You can find a more exhaustive API documentation at [api.md](./api.md).

If you plan to develop the API, please follow the RESTful standards.

To test the API:

```bash
npm test
```

## End-to-End Testing (Cypress)

LWT uses [Cypress](https://www.cypress.io/) for end-to-end testing. E2E tests verify that the application works correctly from a user's perspective.

### Running E2E Tests

Make sure the development server is running on `http://localhost:8000`, then:

```bash
npm run e2e          # Run all E2E tests headlessly
npx cypress open     # Open Cypress interactive UI
```

### Test Structure

E2E tests are located in `cypress/e2e/` and cover:

* `01-setup.cy.ts` - Database setup and demo installation
* `02-home.cy.ts` - Home page and navigation
* `03-legacy-redirects.cy.ts` - Legacy URL redirects
* `04-languages.cy.ts` - Language management
* `05-texts.cy.ts` - Text management
* `06-words.cy.ts` - Word/term management
* `07-admin.cy.ts` - Admin pages (settings, statistics, backup)
* `08-api.cy.ts` - REST API endpoints

Test fixtures are in `cypress/fixtures/test-data.json`.

### Writing E2E Tests

When adding new features or fixing bugs that affect user-facing functionality, consider adding or updating E2E tests:

1. Create or modify test files in `cypress/e2e/`
2. Use fixtures for test data
3. Run tests locally before submitting PRs

## Curated Feed Sources

LWT ships a registry of curated RSS/Atom news feeds for language learners in `data/curated_feeds.json`. Each entry includes pre-configured extraction selectors and options matching the app's feed system.

### Checking Feed Health

A health-check script detects link rot, XML format changes, and stale feeds:

```bash
php bin/check-feeds.php                  # Check all feeds
php bin/check-feeds.php --language=fr    # Check only French feeds
php bin/check-feeds.php --json           # Machine-readable output (for CI)
php bin/check-feeds.php --sample-article # Also test article selectors on a live page
php bin/check-feeds.php --fix            # Update _lastVerified date on success
```

Exit codes: `0` = all healthy, `1` = issues found, `2` = registry file error.

Run this script before submitting changes to `curated_feeds.json`, and periodically to catch feed URL changes early.

### Adding or Updating Feeds

1. Edit `data/curated_feeds.json` — each source needs `name` (max 40 chars), `url`, `articleSectionTags` (tag name for content extraction), `filterTags` (XPath selectors to remove), `options`, `category`, and `level`.
2. Run `php bin/check-feeds.php` and verify the new feed is healthy.
3. Prefer well-known, publicly funded broadcasters (BBC, DW, NHK, etc.) as they tend to maintain stable RSS endpoints.

## Improving Documentation

To regenerate all documentation (VitePress, JSDoc, phpDoc), use ``composer doc``.

### General Documentation

The documentation source files are in `docs-src/` and use [VitePress](https://vitepress.dev/) for static site generation. Markdown files are organized by topic:

* `docs-src/guide/` - User guides and tutorials
* `docs-src/reference/` - Reference documentation
* `docs-src/developer/` - Developer documentation
* `docs-src/legal/` - License information

The built documentation is output to `docs/`.

### PHP Code Documentation

Code documentation (everything under `docs/html/` and `docs/php/`) is automatically generated.
If you see an error, the PHP code is most likely at fault.
However, don't hesitate to signal the issue.

PHP documentation is generated using [phpDocumentor](https://phpdoc.org/).
You can use it through `php tools/phpDocumentor` if installed with [Phive](https://phar.io/).

### JS Code Documentation

Code documentation for JavaScript is available at `docs/js/` is is generated thourgh [JSDoc](https://jsdoc.app/).
The JSDoc configuration file is `jsdoc.json`.

## Translations

LWT ships nine locales. English is the reference: every other locale is measured
against it, and the badges below are refreshed automatically whenever `main` moves.

| Language | Code | Completion |
| --- | --- | --- |
| German | `de` | ![locale de](https://img.shields.io/endpoint?url=https%3A%2F%2Fraw.githubusercontent.com%2FHugoFara%2Flwt%2Fbadges%2Flocale-de.json) |
| English | `en` | ![locale en](https://img.shields.io/endpoint?url=https%3A%2F%2Fraw.githubusercontent.com%2FHugoFara%2Flwt%2Fbadges%2Flocale-en.json) |
| Spanish | `es` | ![locale es](https://img.shields.io/endpoint?url=https%3A%2F%2Fraw.githubusercontent.com%2FHugoFara%2Flwt%2Fbadges%2Flocale-es.json) |
| French | `fr` | ![locale fr](https://img.shields.io/endpoint?url=https%3A%2F%2Fraw.githubusercontent.com%2FHugoFara%2Flwt%2Fbadges%2Flocale-fr.json) |
| Italian | `it` | ![locale it](https://img.shields.io/endpoint?url=https%3A%2F%2Fraw.githubusercontent.com%2FHugoFara%2Flwt%2Fbadges%2Flocale-it.json) |
| Japanese | `ja` | ![locale ja](https://img.shields.io/endpoint?url=https%3A%2F%2Fraw.githubusercontent.com%2FHugoFara%2Flwt%2Fbadges%2Flocale-ja.json) |
| Portuguese | `pt` | ![locale pt](https://img.shields.io/endpoint?url=https%3A%2F%2Fraw.githubusercontent.com%2FHugoFara%2Flwt%2Fbadges%2Flocale-pt.json) |
| Russian | `ru` | ![locale ru](https://img.shields.io/endpoint?url=https%3A%2F%2Fraw.githubusercontent.com%2FHugoFara%2Flwt%2Fbadges%2Flocale-ru.json) |
| Chinese | `zh` | ![locale zh](https://img.shields.io/endpoint?url=https%3A%2F%2Fraw.githubusercontent.com%2FHugoFara%2Flwt%2Fbadges%2Flocale-zh.json) |

### Checking Completion

```bash
php bin/check-locales.php                # Human-readable table
php bin/check-locales.php --json         # Machine-readable output
php bin/check-locales.php --fail-under=90  # Exit 1 if a locale drops below 90%
```

The same script runs in CI on every push and pull request, so a missing key shows
up in the `Locale completion` workflow before it reaches a reader.

### Improving a Translation

Translations live in `locale/<code>/`, split into one JSON file per area
(`common.json`, `vocabulary.json`, `review.json`, and so on). Each file maps a
key to the translated string, and the key set in `locale/en/` is authoritative.

To fill in a gap, find the key in the English file, copy it into the matching
file for your language, and translate the value. Keys absent from a locale fall
back to English at runtime, so a partial translation is safe to submit — there is
no need to complete a file before opening a pull request.

### Adding a New Language

Create `locale/<code>/` and copy the namespace files you intend to translate from
`locale/en/`. The new locale is picked up automatically by the checker; add a row
to the table above and the badge will appear once your change reaches `main`.

## New Version

LWT-fork follows a strict procedure for new versions.
This section is mainly intended for the maintainers, but feel free to take a peek at it.

### Version Locations

The version number must be updated in multiple places:

| File | Field/Constant |
| --- | --- |
| `src/backend/Core/ApplicationInfo.php` | `VERSION` and `RELEASE_DATE` constants |
| `package.json` | `version` field |
| `CHANGELOG.md` | New version section header |

The authoritative version is in `ApplicationInfo.php`. The `package.json` version should match (without the `-fork` suffix).

### Release Steps

1. Update the version in all locations listed above.
2. In the [CHANGELOG](./CHANGELOG.md), move items from `[UNRELEASED]` to the new version section with the release date.
3. Build frontend assets with `npm run build:all`.
4. Regenerate documentation with `composer doc`.
5. Commit your changes: `git commit -m "Release [version]"`
6. Add a version tag with annotation: `git tag -a v[version] -m "Release [version]"` and push with `git push --tags`.
7. If all GitHub Actions pass, create a new release on GitHub linking to the tag.
8. The new version is live!

## Other Ways of Contribution

### Drop a star on GitHub

This is an open-source project. It means that anyone can contribute, but nobody gets paid for improving it. Dropping a star, leaving a comment, or posting an issue is *essential* because the only reward developers get from time spent on LWT is the opportunity to discuss with users.

### Spread the Word

LWT is a non-profitable piece of software, so we won't have much time or money to advertise it. If you enjoy LWT and want to see it grow, share it!

### Discuss

Either go to the forum of the [official LWT version](https://sourceforge.net/p/learning-with-texts/discussion/), or come and [discuss on the community version](https://github.com/HugoFara/lwt/discussions).

Thanks for your interest in contributing!
