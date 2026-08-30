<?php

/**
 * LWT Route Configuration
 *
 * This file defines all routes for the application.
 * Routes map URL paths to controller methods.
 *
 * PHP version 8.1
 *
 * @category Lwt
 * @package  Lwt\Shared\Infrastructure\Routing
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0 All routes now use controller methods
 */

declare(strict_types=1);

namespace Lwt\Shared\Infrastructure\Routing;

use Lwt\Shared\Infrastructure\Routing\Middleware\AuthMiddleware;
use Lwt\Shared\Infrastructure\Routing\Middleware\AuthRateLimitMiddleware;
use Lwt\Shared\Infrastructure\Routing\Middleware\AdminMiddleware;
use Lwt\Shared\Infrastructure\Routing\Middleware\CsrfMiddleware;
use Lwt\Shared\Infrastructure\Routing\Middleware\RateLimitMiddleware;

/**
 * Auth middleware for protected routes.
 * Includes CSRF protection for state-changing requests (POST, PUT, DELETE).
 *
 * @var array<string>
 */
const AUTH_MIDDLEWARE = [AuthMiddleware::class, CsrfMiddleware::class];

/**
 * Admin middleware for admin-only routes.
 * Requires authentication AND admin role, plus CSRF protection.
 *
 * @var array<string>
 */
const ADMIN_MIDDLEWARE = [AdminMiddleware::class, CsrfMiddleware::class];

/**
 * Register all application routes.
 *
 * Routes are organized into:
 * - Public routes: No authentication required (login, register, etc.)
 * - Protected routes: Require user authentication
 *
 * @param Router $router The router instance to register routes with
 *
 * @return void
 */
function registerRoutes(Router $router): void
{
    // ==================== HOME PAGE (PROTECTED) ====================

    $router->registerWithMiddleware('/', 'Lwt\\Modules\\Home\\Http\\HomeController@index', AUTH_MIDDLEWARE);
    $router->registerWithMiddleware('/index.php', 'Lwt\\Modules\\Home\\Http\\HomeController@index', AUTH_MIDDLEWARE);

    // ==================== TEXT ROUTES (PROTECTED) ====================

    // Read text (Alpine.js - client-side rendering)
    // New RESTful route: /text/123/read
    $router->get('/text/{text:int}/read', 'Lwt\\Modules\\Text\\Http\\TextController@read', AUTH_MIDDLEWARE);
    // Legacy route: /text/read?text=123
    $router->get('/text/read', 'Lwt\\Modules\\Text\\Http\\TextController@read', AUTH_MIDDLEWARE);

    // New text form (RESTful route)
    $router->get('/texts/new', 'Lwt\\Modules\\Text\\Http\\TextController@new', AUTH_MIDDLEWARE);
    $router->post('/texts/new', 'Lwt\\Modules\\Text\\Http\\TextController@new', AUTH_MIDDLEWARE);

    // Edit text form (RESTful route): /texts/123/edit
    $router->get('/texts/{id:int}/edit', 'Lwt\\Modules\\Text\\Http\\TextController@editSingle', AUTH_MIDDLEWARE);
    $router->post('/texts/{id:int}/edit', 'Lwt\\Modules\\Text\\Http\\TextController@editSingle', AUTH_MIDDLEWARE);

    // Delete text (RESTful route): DELETE /texts/123
    $router->delete('/texts/{id:int}', 'Lwt\\Modules\\Text\\Http\\TextController@delete', AUTH_MIDDLEWARE);

    // Archive text (RESTful route): POST /texts/123/archive
    $router->post('/texts/{id:int}/archive', 'Lwt\\Modules\\Text\\Http\\TextController@archive', AUTH_MIDDLEWARE);

    // Unarchive text (RESTful route): POST /texts/123/unarchive
    $router->post('/texts/{id:int}/unarchive', 'Lwt\\Modules\\Text\\Http\\TextController@unarchive', AUTH_MIDDLEWARE);

    // Texts list and legacy edit routes
    $router->registerWithMiddleware('/text/edit', 'Lwt\\Modules\\Text\\Http\\TextController@edit', AUTH_MIDDLEWARE);
    $router->registerWithMiddleware('/texts', 'Lwt\\Modules\\Text\\Http\\TextController@edit', AUTH_MIDDLEWARE);

    // Display improved text
    // New RESTful route: /text/123/display
    $router->get('/text/{text:int}/display', 'Lwt\\Modules\\Text\\Http\\TextController@display', AUTH_MIDDLEWARE);
    // Legacy route: /text/display?text=123
    $router->get('/text/display', 'Lwt\\Modules\\Text\\Http\\TextController@display', AUTH_MIDDLEWARE);

    // Print text (TextPrintController from Text module)
    // RESTful route: /text/123/print
    $router->get(
        '/text/{text:int}/print',
        'Lwt\\Modules\\Text\\Http\\TextPrintController@printAnnotated',
        AUTH_MIDDLEWARE
    );
    // RESTful route: /text/123/print/edit
    $router->get(
        '/text/{text:int}/print/edit',
        'Lwt\\Modules\\Text\\Http\\TextPrintController@editAnnotation',
        AUTH_MIDDLEWARE
    );
    // RESTful route: DELETE /text/123/annotation
    $router->delete(
        '/text/{text:int}/annotation',
        'Lwt\\Modules\\Text\\Http\\TextPrintController@deleteAnnotation',
        AUTH_MIDDLEWARE
    );
    // RESTful route: /text/123/print-plain
    $router->get(
        '/text/{text:int}/print-plain',
        'Lwt\\Modules\\Text\\Http\\TextPrintController@printPlain',
        AUTH_MIDDLEWARE
    );
    // Legacy route: /text/print-plain?text=123
    $router->registerWithMiddleware(
        '/text/print-plain',
        'Lwt\\Modules\\Text\\Http\\TextPrintController@printPlain',
        AUTH_MIDDLEWARE
    );

    // Check text
    $router->registerWithMiddleware('/text/check', 'Lwt\\Modules\\Text\\Http\\TextController@check', AUTH_MIDDLEWARE);

    // Archived texts
    $router->registerWithMiddleware(
        '/text/archived',
        'Lwt\\Modules\\Text\\Http\\TextController@archived',
        AUTH_MIDDLEWARE
    );

    // Edit archived text (RESTful route): /text/archived/123/edit
    $router->get(
        '/text/archived/{id:int}/edit',
        'Lwt\\Modules\\Text\\Http\\TextController@archivedEdit',
        AUTH_MIDDLEWARE
    );

    // Delete archived text (RESTful route): DELETE /text/archived/123
    $router->delete(
        '/text/archived/{id:int}',
        'Lwt\\Modules\\Text\\Http\\TextController@deleteArchived',
        AUTH_MIDDLEWARE
    );

    // ==================== WORD/TERM ROUTES (PROTECTED) ====================
    // Split into focused controllers: TermEditController, TermDisplayController,
    // TermStatusController, TermApiController, TermImportController, MultiWordController

    // Edit word (TermEditController). The editor is client-rendered against
    // /api/v1/terms/*, so these only ever serve a page — never a submission.
    $router->get(
        '/word/edit',
        'Lwt\\Modules\\Vocabulary\\Http\\TermEditController@editWord',
        AUTH_MIDDLEWARE
    );

    // Edit term while testing (TermEditController)
    $router->get(
        '/word/edit-term',
        'Lwt\\Modules\\Vocabulary\\Http\\TermEditController@editTerm',
        AUTH_MIDDLEWARE
    );

    // Edit single word form (RESTful route): /words/123/edit
    $router->get(
        '/words/{id:int}/edit',
        'Lwt\\Modules\\Vocabulary\\Http\\TermEditController@editWordById',
        AUTH_MIDDLEWARE
    );
    // Delete word (RESTful route): DELETE /words/123
    $router->delete(
        '/words/{id:int}',
        'Lwt\\Modules\\Vocabulary\\Http\\TermEditController@deleteWord',
        AUTH_MIDDLEWARE
    );

    // Words list - Alpine.js SPA version (TermDisplayController)
    $router->registerWithMiddleware(
        '/words/edit',
        'Lwt\\Modules\\Vocabulary\\Http\\TermDisplayController@listEditAlpine',
        AUTH_MIDDLEWARE
    );

    // All words (list view) - Alpine.js SPA version (TermDisplayController)
    $router->registerWithMiddleware(
        '/words',
        'Lwt\\Modules\\Vocabulary\\Http\\TermDisplayController@listEditAlpine',
        AUTH_MIDDLEWARE
    );

    // New word (TermEditController)
    // RESTful route: /words/new
    $router->get('/words/new', 'Lwt\\Modules\\Vocabulary\\Http\\TermEditController@createWord', AUTH_MIDDLEWARE);
    // Legacy route: /word/new. GET only — creation goes through
    // POST /api/v1/terms/for-language, which validates the language.
    $router->get(
        '/word/new',
        'Lwt\\Modules\\Vocabulary\\Http\\TermEditController@createWord',
        AUTH_MIDDLEWARE
    );

    // Show word - new RESTful route with typed parameter (TermDisplayController)
    $router->get('/word/{wid:int}', 'Lwt\\Modules\\Vocabulary\\Http\\TermDisplayController@showWord', AUTH_MIDDLEWARE);
    // Legacy route for backward compatibility
    $router->get('/word/show', 'Lwt\\Modules\\Vocabulary\\Http\\TermDisplayController@showWord', AUTH_MIDDLEWARE);

    // Inline edit (TermEditController)
    $router->registerWithMiddleware(
        '/word/inline-edit',
        'Lwt\\Modules\\Vocabulary\\Http\\TermEditController@inlineEdit',
        AUTH_MIDDLEWARE
    );

    // Bulk translate (TermImportController)
    $router->registerWithMiddleware(
        '/word/bulk-translate',
        'Lwt\\Modules\\Vocabulary\\Http\\TermImportController@bulkTranslate',
        AUTH_MIDDLEWARE
    );

    // Similar terms lookup (TermDisplayController)
    $router->registerWithMiddleware(
        '/vocabulary/similar-terms',
        'Lwt\\Modules\\Vocabulary\\Http\\TermDisplayController@similarTerms',
        AUTH_MIDDLEWARE
    );

    // Vocabulary JSON API routes (TermApiController)
    $router->registerWithMiddleware(
        '/vocabulary/term',
        'Lwt\\Modules\\Vocabulary\\Http\\TermApiController@getTermJson',
        AUTH_MIDDLEWARE,
        'GET'
    );
    $router->registerWithMiddleware(
        '/vocabulary/term',
        'Lwt\\Modules\\Vocabulary\\Http\\TermApiController@createJson',
        AUTH_MIDDLEWARE,
        'POST'
    );
    $router->registerWithMiddleware(
        '/vocabulary/term',
        'Lwt\\Modules\\Vocabulary\\Http\\TermApiController@updateJson',
        AUTH_MIDDLEWARE,
        'PUT'
    );
    // Update term status (TermStatusController)
    // New RESTful route: PUT /vocabulary/term/123/status
    $router->put(
        '/vocabulary/term/{wid:int}/status',
        'Lwt\\Modules\\Vocabulary\\Http\\TermStatusController@updateStatus',
        AUTH_MIDDLEWARE
    );
    // Legacy route: PUT /vocabulary/term/status?wid=123
    $router->put(
        '/vocabulary/term/status',
        'Lwt\\Modules\\Vocabulary\\Http\\TermStatusController@updateStatus',
        AUTH_MIDDLEWARE
    );
    // Delete term (TermApiController)
    $router->registerWithMiddleware(
        '/vocabulary/term/delete',
        'Lwt\\Modules\\Vocabulary\\Http\\TermApiController@delete',
        AUTH_MIDDLEWARE,
        'POST'
    );

    // Upload words (TermImportController)
    $router->registerWithMiddleware(
        '/word/upload',
        'Lwt\\Modules\\Vocabulary\\Http\\TermImportController@upload',
        AUTH_MIDDLEWARE
    );

    // Anki .apkg export / import (ApkgController)
    // Both GET (whole language) and POST (selection-aware via marked[]).
    $router->get(
        '/vocabulary/apkg/export',
        'Lwt\\Modules\\Vocabulary\\Http\\ApkgController@export',
        AUTH_MIDDLEWARE
    );
    $router->post(
        '/vocabulary/apkg/export',
        'Lwt\\Modules\\Vocabulary\\Http\\ApkgController@export',
        AUTH_MIDDLEWARE
    );
    $router->get(
        '/vocabulary/apkg/import',
        'Lwt\\Modules\\Vocabulary\\Http\\ApkgController@importForm',
        AUTH_MIDDLEWARE
    );
    $router->post(
        '/vocabulary/apkg/import',
        'Lwt\\Modules\\Vocabulary\\Http\\ApkgController@importForm',
        AUTH_MIDDLEWARE
    );

    // Import a deck built in Anki as new terms (AnkiDeckImportController).
    // Distinct from the apkg routes above: those round-trip LWT's own export,
    // this one seeds terms from a foreign deck.
    $router->get(
        '/vocabulary/anki-deck/import',
        'Lwt\\Modules\\Vocabulary\\Http\\AnkiDeckImportController@index',
        AUTH_MIDDLEWARE
    );
    $router->post(
        '/vocabulary/anki-deck/import',
        'Lwt\\Modules\\Vocabulary\\Http\\AnkiDeckImportController@index',
        AUTH_MIDDLEWARE
    );

    // Review status change during review (iframe/ajax view).
    // NB: the dead text-reader frame routes (set-status, delete-term,
    // delete-multi, insert-wellknown, insert-ignore) are unregistered here —
    // the modern reader changes status via /api/v1/terms/* and never linked to
    // them. Their now-unreachable controller methods + result views are retained
    // pending the frontend dead-popup cleanup (needs in-browser E2E to verify).
    $router->registerWithMiddleware(
        '/word/set-review-status',
        'Lwt\\Modules\\Vocabulary\\Http\\TermStatusController@setReviewStatusView',
        AUTH_MIDDLEWARE
    );

    // ==================== REVIEW ROUTES (PROTECTED) ====================

    // Review interface (Review module)
    $router->registerWithMiddleware('/review', 'Lwt\\Modules\\Review\\Http\\ReviewController@index', AUTH_MIDDLEWARE);

    // ==================== LANGUAGE ROUTES (PROTECTED) ====================

    // New language form (RESTful route): GET/POST /languages/new
    $router->get('/languages/new', 'Lwt\\Modules\\Language\\Http\\LanguageController@new', AUTH_MIDDLEWARE);

    // Edit language form (RESTful route): /languages/123/edit
    $router->get('/languages/{id:int}/edit', 'Lwt\\Modules\\Language\\Http\\LanguageController@edit', AUTH_MIDDLEWARE);

    // Delete language (RESTful route): DELETE /languages/123
    $router->delete(
        '/languages/{id:int}',
        'Lwt\\Modules\\Language\\Http\\LanguageController@delete',
        AUTH_MIDDLEWARE
    );

    // Refresh (reparse) language texts (RESTful route): POST /languages/123/refresh
    $router->post(
        '/languages/{id:int}/refresh',
        'Lwt\\Modules\\Language\\Http\\LanguageController@refresh',
        AUTH_MIDDLEWARE
    );

    // Starter vocabulary (shown after language creation)
    $router->get(
        '/languages/{id:int}/starter-vocab',
        'Lwt\\Modules\\Vocabulary\\Http\\StarterVocabController@show',
        AUTH_MIDDLEWARE
    );
    $router->post(
        '/languages/{id:int}/starter-vocab/import',
        'Lwt\\Modules\\Vocabulary\\Http\\StarterVocabController@import',
        AUTH_MIDDLEWARE
    );
    $router->post(
        '/languages/{id:int}/starter-vocab/enrich',
        'Lwt\\Modules\\Vocabulary\\Http\\StarterVocabController@enrich',
        AUTH_MIDDLEWARE
    );
    $router->get(
        '/languages/{id:int}/starter-vocab/skip',
        'Lwt\\Modules\\Vocabulary\\Http\\StarterVocabController@skip',
        AUTH_MIDDLEWARE
    );

    // Languages list (Language module)
    $router->registerWithMiddleware(
        '/languages',
        'Lwt\\Modules\\Language\\Http\\LanguageController@index',
        AUTH_MIDDLEWARE
    );

    // ==================== TAG ROUTES (PROTECTED) ====================

    // Term tags (Tags module)
    // The controller's new() handles both GET (show form) and POST (save).
    $router->get('/tags/new', 'Lwt\\Modules\\Tags\\Http\\TermTagController@new', AUTH_MIDDLEWARE);
    $router->post('/tags/new', 'Lwt\\Modules\\Tags\\Http\\TermTagController@new', AUTH_MIDDLEWARE);

    // Edit term tag (RESTful route): GET/POST /tags/123/edit
    $router->get('/tags/{id:int}/edit', 'Lwt\\Modules\\Tags\\Http\\TermTagController@edit', AUTH_MIDDLEWARE);
    $router->post('/tags/{id:int}/edit', 'Lwt\\Modules\\Tags\\Http\\TermTagController@edit', AUTH_MIDDLEWARE);

    // Delete term tag (RESTful route): DELETE /tags/123
    $router->delete('/tags/{id:int}', 'Lwt\\Modules\\Tags\\Http\\TermTagController@delete', AUTH_MIDDLEWARE);

    $router->registerWithMiddleware('/tags', 'Lwt\\Modules\\Tags\\Http\\TermTagController@index', AUTH_MIDDLEWARE);

    // Text tags (Tags module)
    // Same pattern as term tags: controller's new() handles GET and POST.
    $router->get('/tags/text/new', 'Lwt\\Modules\\Tags\\Http\\TextTagController@new', AUTH_MIDDLEWARE);
    $router->post('/tags/text/new', 'Lwt\\Modules\\Tags\\Http\\TextTagController@new', AUTH_MIDDLEWARE);

    // Edit text tag (RESTful route): GET/POST /tags/text/123/edit
    $router->get('/tags/text/{id:int}/edit', 'Lwt\\Modules\\Tags\\Http\\TextTagController@edit', AUTH_MIDDLEWARE);
    $router->post('/tags/text/{id:int}/edit', 'Lwt\\Modules\\Tags\\Http\\TextTagController@edit', AUTH_MIDDLEWARE);

    // Delete text tag (RESTful route): DELETE /tags/text/123
    $router->delete('/tags/text/{id:int}', 'Lwt\\Modules\\Tags\\Http\\TextTagController@delete', AUTH_MIDDLEWARE);

    $router->registerWithMiddleware('/tags/text', 'Lwt\\Modules\\Tags\\Http\\TextTagController@index', AUTH_MIDDLEWARE);

    // ==================== FEED ROUTES (PROTECTED) ====================

    // Feeds SPA (new Alpine.js single page application)
    $router->registerWithMiddleware('/feeds/manage', 'Lwt\\Modules\\Feed\\Http\\FeedController@spa', AUTH_MIDDLEWARE);

    // New feed form (RESTful route). GET only: the form saves through
    // POST /api/v1/feeds (#262).
    $router->get('/feeds/new', 'Lwt\\Modules\\Feed\\Http\\FeedController@newFeed', AUTH_MIDDLEWARE);

    // Edit feed form (RESTful route): /feeds/123/edit. GET only, as above —
    // the form saves through PUT /api/v1/feeds/{id}.
    $router->get('/feeds/{id:int}/edit', 'Lwt\\Modules\\Feed\\Http\\FeedController@editFeed', AUTH_MIDDLEWARE);

    // Delete feed (RESTful route): DELETE /feeds/123
    $router->delete('/feeds/{id:int}', 'Lwt\\Modules\\Feed\\Http\\FeedController@deleteFeed', AUTH_MIDDLEWARE);

    // Load/refresh feed (RESTful route): POST /feeds/123/load
    $router->get('/feeds/{id:int}/load', 'Lwt\\Modules\\Feed\\Http\\FeedController@loadFeedRoute', AUTH_MIDDLEWARE);

    // Multi-load feeds interface (RESTful route)
    $router->get('/feeds/multi-load', 'Lwt\\Modules\\Feed\\Http\\FeedController@multiLoad', AUTH_MIDDLEWARE);

    // Refresh feeds due for auto-update
    $router->get(
        '/feeds/autoupdate',
        'Lwt\\Modules\\Feed\\Http\\FeedController@autoupdate',
        AUTH_MIDDLEWARE
    );

    // Legacy article browser - redirects to the manager SPA
    $router->registerWithMiddleware('/feeds', 'Lwt\\Modules\\Feed\\Http\\FeedController@index', AUTH_MIDDLEWARE);

    // Edit feeds (legacy route - handles query params)
    $router->registerWithMiddleware('/feeds/edit', 'Lwt\\Modules\\Feed\\Http\\FeedController@edit', AUTH_MIDDLEWARE);

    // Feed wizard. GET only: the wizard is one page that reads and saves
    // through /api/v1 rather than four pages posting back here (#262).
    $router->get(
        '/feeds/wizard',
        'Lwt\\Modules\\Feed\\Http\\FeedController@wizard',
        AUTH_MIDDLEWARE
    );

    // ==================== BOOK ROUTES (PROTECTED) ====================
    // Book module routes for EPUB import and book management

    // Books list
    $router->registerWithMiddleware('/books', 'Lwt\\Modules\\Book\\Http\\BookController@index', AUTH_MIDDLEWARE);

    // Book detail (chapters list)
    $router->get('/book/{id:int}', 'Lwt\\Modules\\Book\\Http\\BookController@show', AUTH_MIDDLEWARE);

    // Import EPUB form and processing
    $router->registerWithMiddleware('/book/import', 'Lwt\\Modules\\Book\\Http\\BookController@import', AUTH_MIDDLEWARE);

    // Delete book
    $router->post('/book/{id:int}/delete', 'Lwt\\Modules\\Book\\Http\\BookController@delete', AUTH_MIDDLEWARE);

    // ==================== LOCAL DICTIONARY ROUTES (PROTECTED) ====================
    // All dictionary routes use DictionaryController from the Dictionary module

    // RESTful routes: /languages/{id}/dictionaries
    $router->get(
        '/languages/{id:int}/dictionaries',
        'Lwt\\Modules\\Dictionary\\Http\\DictionaryController@index',
        AUTH_MIDDLEWARE
    );
    $router->get(
        '/languages/{id:int}/dictionaries/import',
        'Lwt\\Modules\\Dictionary\\Http\\DictionaryController@import',
        AUTH_MIDDLEWARE
    );
    $router->post(
        '/languages/{id:int}/dictionaries/import',
        'Lwt\\Modules\\Dictionary\\Http\\DictionaryController@processImport',
        AUTH_MIDDLEWARE
    );

    // Legacy routes (with ?lang= query parameter)
    $router->registerWithMiddleware(
        '/dictionaries',
        'Lwt\\Modules\\Dictionary\\Http\\DictionaryController@index',
        AUTH_MIDDLEWARE
    );

    // Import wizard
    $router->registerWithMiddleware(
        '/dictionaries/import',
        'Lwt\\Modules\\Dictionary\\Http\\DictionaryController@import',
        AUTH_MIDDLEWARE,
        'GET'
    );
    $router->registerWithMiddleware(
        '/dictionaries/import',
        'Lwt\\Modules\\Dictionary\\Http\\DictionaryController@processImport',
        AUTH_MIDDLEWARE,
        'POST'
    );

    // Delete dictionary
    $router->registerWithMiddleware(
        '/dictionaries/delete',
        'Lwt\\Modules\\Dictionary\\Http\\DictionaryController@delete',
        AUTH_MIDDLEWARE,
        'POST'
    );

    // Preview (AJAX)
    $router->registerWithMiddleware(
        '/dictionaries/preview',
        'Lwt\\Modules\\Dictionary\\Http\\DictionaryController@preview',
        AUTH_MIDDLEWARE,
        'POST'
    );

    // ==================== ADMIN ROUTES (ADMIN ONLY) ====================
    // These routes require admin role, not just authentication

    // Admin dashboard
    $router->registerWithMiddleware(
        '/admin',
        'Lwt\\Modules\\Admin\\Http\\AdminController@dashboard',
        ADMIN_MIDDLEWARE
    );

    // Backup & Restore (Admin module)
    $router->registerWithMiddleware(
        '/admin/backup',
        'Lwt\\Modules\\Admin\\Http\\AdminController@backup',
        ADMIN_MIDDLEWARE
    );

    // Database Wizard (Admin module)
    $router->registerWithMiddleware(
        '/admin/wizard',
        'Lwt\\Modules\\Admin\\Http\\AdminController@wizard',
        ADMIN_MIDDLEWARE
    );

    // Statistics (User module) - legacy /admin/statistics redirects to /profile/statistics
    $router->registerWithMiddleware(
        '/admin/statistics',
        'Lwt\\Modules\\User\\Http\\StatisticsController@redirectFromAdmin',
        AUTH_MIDDLEWARE
    );

    // Install Demo (Admin module)
    $router->registerWithMiddleware(
        '/admin/install-demo',
        'Lwt\\Modules\\Admin\\Http\\AdminController@installDemo',
        ADMIN_MIDDLEWARE
    );

    // Settings (Admin module)
    $router->registerWithMiddleware(
        '/admin/settings',
        'Lwt\\Modules\\Admin\\Http\\AdminController@settings',
        ADMIN_MIDDLEWARE
    );

    // Server data (Admin module)
    $router->registerWithMiddleware(
        '/admin/server-data',
        'Lwt\\Modules\\Admin\\Http\\AdminController@serverData',
        ADMIN_MIDDLEWARE
    );

    // User Management (Admin module). Only the three page routes remain:
    // every mutation now goes through /api/v1/admin/users, which enforces the
    // admin role itself rather than relying on ADMIN_MIDDLEWARE.
    $router->get('/admin/users', 'Lwt\\Modules\\Admin\\Http\\UserManagementController@index', ADMIN_MIDDLEWARE);
    $router->get('/admin/users/new', 'Lwt\\Modules\\Admin\\Http\\UserManagementController@create', ADMIN_MIDDLEWARE);
    $router->get(
        '/admin/users/{id:int}/edit',
        'Lwt\\Modules\\Admin\\Http\\UserManagementController@edit',
        ADMIN_MIDDLEWARE
    );

    // ==================== USER PROFILE (AUTH REQUIRED) ====================
    // Profile and preferences save through PUT /api/v1/profile*; these routes
    // only render the scaffolds those components bind to.
    $router->get('/profile', 'Lwt\\Modules\\User\\Http\\UserController@profileForm', AUTH_MIDDLEWARE);
    $router->get('/profile/preferences', 'Lwt\\Modules\\User\\Http\\UserController@preferencesForm', AUTH_MIDDLEWARE);
    $router->get(
        '/profile/statistics',
        'Lwt\\Modules\\User\\Http\\StatisticsController@show',
        AUTH_MIDDLEWARE
    );

    // ==================== AUTHENTICATION ROUTES (PUBLIC) ====================
    // All auth routes use UserController from the User module

    // Login - no auth required, rate limited and CSRF-protected on POST.
    // CSRF on a pre-login form blocks login-CSRF: an attacker cannot force a
    // victim to log in as the attacker's account (and then have the victim's
    // reading/vocabulary land there) without first stealing the pre-login
    // session token, which is HttpOnly+SameSite.
    $router->register('/login', 'Lwt\\Modules\\User\\Http\\UserController@loginForm', 'GET');
    $router->post(
        '/login',
        'Lwt\\Modules\\User\\Http\\UserController@login',
        [AuthRateLimitMiddleware::class, CsrfMiddleware::class]
    );

    // Packaged-client entry: client-rendered "choose server + log in" flow
    // (token auth). The login POST goes to /api/v1/auth/login, not here.
    $router->register('/connect', 'Lwt\\Modules\\User\\Http\\UserController@clientAuthForm', 'GET');

    // Registration - no auth required, rate limited and CSRF-protected on POST.
    $router->register('/register', 'Lwt\\Modules\\User\\Http\\UserController@registerForm', 'GET');
    $router->post(
        '/register',
        'Lwt\\Modules\\User\\Http\\UserController@register',
        [AuthRateLimitMiddleware::class, CsrfMiddleware::class]
    );
    // One-time recovery code shown after an email-less sign-up or a code reset.
    $router->register(
        '/register/recovery-code',
        'Lwt\\Modules\\User\\Http\\UserController@recoveryCodeShown',
        'GET'
    );

    // Logout - POST-only with CSRF so cross-site `<img src=/logout>` cannot
    // log the victim out. The controller handles a missing session gracefully.
    $router->post('/logout', 'Lwt\\Modules\\User\\Http\\UserController@logout', [CsrfMiddleware::class]);

    // Email Verification - no auth required for token link.
    $router->register('/verify-email', 'Lwt\\Modules\\User\\Http\\UserController@verifyEmail', 'GET');
    // Resend verification is an authenticated state-changing POST (controller
    // gates auth inline). Add CSRF + rate-limit so cross-site forms cannot
    // spam the victim's inbox with verification emails.
    $router->post(
        '/email/resend-verification',
        'Lwt\\Modules\\User\\Http\\UserController@resendVerification',
        [AuthRateLimitMiddleware::class, CsrfMiddleware::class]
    );

    // Password Reset - no auth required, rate limited and CSRF-protected on POST.
    $router->register('/password/forgot', 'Lwt\\Modules\\User\\Http\\UserController@forgotPasswordForm', 'GET');
    $router->post(
        '/password/forgot',
        'Lwt\\Modules\\User\\Http\\UserController@forgotPassword',
        [AuthRateLimitMiddleware::class, CsrfMiddleware::class]
    );
    $router->register('/password/reset', 'Lwt\\Modules\\User\\Http\\UserController@resetPasswordForm', 'GET');
    $router->post(
        '/password/reset',
        'Lwt\\Modules\\User\\Http\\UserController@resetPassword',
        [AuthRateLimitMiddleware::class, CsrfMiddleware::class]
    );
    // Recovery-code reset (for accounts created without an email).
    $router->register(
        '/password/recover',
        'Lwt\\Modules\\User\\Http\\UserController@recoverWithCodeForm',
        'GET'
    );
    $router->post(
        '/password/recover',
        'Lwt\\Modules\\User\\Http\\UserController@recoverWithCode',
        [AuthRateLimitMiddleware::class, CsrfMiddleware::class]
    );

    // ==================== WORDPRESS INTEGRATION (PUBLIC) ====================

    // WordPress routes are public - they handle their own auth via WP tokens
    $router->register('/wordpress/start', 'Lwt\\Modules\\User\\Http\\WordPressController@start');
    $router->register('/wordpress/stop', 'Lwt\\Modules\\User\\Http\\WordPressController@stop');

    // ==================== GOOGLE OAUTH INTEGRATION (PUBLIC) ====================

    // Google OAuth routes are public - they handle their own auth via OAuth tokens.
    // The link-confirm POST writes to the session and changes account linkage,
    // so it must validate CSRF to block cross-site form submissions even though
    // it does not require AuthMiddleware (controller checks the pending-link session state).
    $router->register('/google/start', 'Lwt\\Modules\\User\\Http\\GoogleController@start');
    $router->register('/google/callback', 'Lwt\\Modules\\User\\Http\\GoogleController@callback');
    $router->register('/google/link-confirm', 'Lwt\\Modules\\User\\Http\\GoogleController@linkConfirm', 'GET');
    $router->post(
        '/google/link-confirm',
        'Lwt\\Modules\\User\\Http\\GoogleController@processLinkConfirm',
        [CsrfMiddleware::class]
    );

    // ==================== MICROSOFT OAUTH INTEGRATION (PUBLIC) ====================

    // Microsoft OAuth routes are public - they handle their own auth via OAuth tokens.
    // See the Google block above for why link-confirm POST needs CsrfMiddleware.
    $router->register('/microsoft/start', 'Lwt\\Modules\\User\\Http\\MicrosoftController@start');
    $router->register('/microsoft/callback', 'Lwt\\Modules\\User\\Http\\MicrosoftController@callback');
    $router->register('/microsoft/link-confirm', 'Lwt\\Modules\\User\\Http\\MicrosoftController@linkConfirm', 'GET');
    $router->post(
        '/microsoft/link-confirm',
        'Lwt\\Modules\\User\\Http\\MicrosoftController@processLinkConfirm',
        [CsrfMiddleware::class]
    );

    // ==================== API ROUTES ====================

    // Main API - use prefix to catch all sub-paths
    // Note: API handles its own authentication internally via ApiV1::validateAuth()
    // This allows /api/v1/auth/* endpoints to be public while protecting others.
    // CsrfMiddleware enforces token validation on POST/PUT/DELETE/PATCH for
    // cookie-authenticated callers; requests carrying a Bearer token are
    // exempted inside the middleware (the token itself defeats CSRF).
    // Support both /api/v1 (new) and /api.php/v1 (legacy) paths.
    // RateLimitMiddleware sits in front of CSRF so a flood of unsigned
    // requests gets dropped before we spend cycles hashing the token —
    // the middleware itself uses the per-endpoint window for /whisper
    // (5/15min) and the general default for everything else.
    $router->registerPrefixWithMiddleware(
        '/api/v1',
        'ApiController@v1',
        [RateLimitMiddleware::class, CsrfMiddleware::class]
    );
    $router->registerPrefixWithMiddleware(
        '/api.php/v1',
        'ApiController@v1',
        [RateLimitMiddleware::class, CsrfMiddleware::class]
    );

    // Translation APIs (PROTECTED) - used by authenticated users
    $router->registerWithMiddleware(
        '/api/translate',
        'Lwt\\Modules\\Dictionary\\Http\\TranslationController@translate',
        AUTH_MIDDLEWARE
    );
    $router->registerWithMiddleware(
        '/api/google',
        'Lwt\\Modules\\Dictionary\\Http\\TranslationController@google',
        AUTH_MIDDLEWARE
    );
    $router->registerWithMiddleware(
        '/api/glosbe',
        'Lwt\\Modules\\Dictionary\\Http\\TranslationController@glosbe',
        AUTH_MIDDLEWARE
    );

    // ==================== DEPRECATED ROUTES ====================
    // These legacy routes still work but emit Deprecation headers.
    // They will be removed in the next major version.

    $router->deprecate('/text/read', '/text/{text}/read');
    $router->deprecate('/text/display', '/text/{text}/display');
    $router->deprecate('/text/print-plain', '/text/{text}/print-plain');
    $router->deprecate('/text/edit', '/texts');
    $router->deprecate('/word/new', '/words/new');
    $router->deprecate('/word/show', '/word/{wid}');
    $router->deprecate('/vocabulary/term/status', '/vocabulary/term/{wid}/status');
    $router->deprecate('/feeds/edit', '/feeds/{id}/edit');
    $router->deprecate('/dictionaries', '/languages/{id}/dictionaries');
    $router->deprecate('/dictionaries/import', '/languages/{id}/dictionaries/import');
    $router->deprecatePrefix('/api.php/v1', '/api/v1');
}
