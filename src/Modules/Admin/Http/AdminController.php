<?php

/**
 * Admin Controller
 *
 * HTTP controller for administrative functions.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Admin\Http
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Admin\Http;

use Lwt\Shared\Http\BaseController;
use Lwt\Shared\Infrastructure\Http\InputValidator;
use Lwt\Modules\Admin\Application\AdminFacade;
use Lwt\Shared\Infrastructure\Language\LanguagePresets;
use Lwt\Modules\Admin\Application\Services\TtsService;

/**
 * Controller for administrative functions.
 *
 * Handles:
 * - Backup and restore
 * - Database wizard
 * - Settings
 * - Install demo
 * - Server data
 *
 * @category Lwt
 * @package  Lwt\Modules\Admin\Http
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */
class AdminController extends BaseController
{
    private AdminFacade $adminFacade;
    private TtsService $ttsService;

    /**
     * View base path.
     */
    private string $viewPath;

    /**
     * Constructor.
     *
     * @param AdminFacade|null $adminFacade Admin facade (optional for BC)
     * @param TtsService|null  $ttsService  TTS service (optional for BC)
     */
    public function __construct(
        ?AdminFacade $adminFacade = null,
        ?TtsService $ttsService = null
    ) {
        parent::__construct();
        $this->adminFacade = $adminFacade ?? $this->createDefaultFacade();
        $this->ttsService = $ttsService ?? new TtsService();
        $this->viewPath = __DIR__ . '/../Views/';
    }

    /**
     * Create the default AdminFacade when not provided via DI.
     *
     * @return AdminFacade
     */
    private function createDefaultFacade(): AdminFacade
    {
        // Import required classes for fallback instantiation
        require_once __DIR__ . '/../Domain/SettingsRepositoryInterface.php';
        require_once __DIR__ . '/../Domain/BackupRepositoryInterface.php';
        require_once __DIR__ . '/../Infrastructure/MySqlSettingsRepository.php';
        require_once __DIR__ . '/../Infrastructure/MySqlBackupRepository.php';
        require_once __DIR__ . '/../Application/AdminFacade.php';

        $settingsRepo = new \Lwt\Modules\Admin\Infrastructure\MySqlSettingsRepository();
        $backupRepo = new \Lwt\Modules\Admin\Infrastructure\MySqlBackupRepository();

        return new AdminFacade($settingsRepo, $backupRepo);
    }

    /**
     * Admin dashboard page.
     *
     * Shows links to all admin subpages.
     *
     * @param array<string, string> $params Route parameters
     *
     * @return void
     *
     * @psalm-suppress UnresolvableInclude View path is constructed at runtime
     */
    public function dashboard(array $params): void
    {
        $this->render('Administration', true);
        include $this->viewPath . 'dashboard.php';
        $this->endRender();
    }

    /**
     * Backup and restore page.
     *
     * Handles:
     * - restore=xxx: Restore from uploaded file
     * - backup=xxx: Download backup
     * - orig_backup=xxx: Download official format backup
     * - empty=xxx: Empty the database
     *
     * @param array<string, string> $params Route parameters
     *
     * @return void
     *
     * @psalm-suppress UnusedVariable Variables are used in included view files
     * @psalm-suppress UnresolvableInclude View path is constructed at runtime
     */
    public function backup(array $params): void
    {
        $message = '';

        // Handle operations
        if ($this->hasParam('restore')) {
            $result = $this->adminFacade->restoreFromUpload(
                InputValidator::getUploadedFile('thefile')
            );
            // Show the restore's own report rather than a flat "Database
            // restored": its query/record counts are the only signal an admin
            // has that the dump actually replayed. A restore that parsed zero
            // statements used to look identical to a real one here (#249).
            $message = $result['success']
                ? ($result['message'] ?? 'Database restored')
                : ($result['error'] ?? 'Restore failed');
        } elseif ($this->hasParam('backup')) {
            $this->adminFacade->downloadBackup();
            // downloadBackup exits, so we never reach here
        } elseif ($this->hasParam('orig_backup')) {
            $this->adminFacade->downloadOfficialBackup();
            // downloadOfficialBackup exits, so we never reach here
        } elseif ($this->hasParam('empty')) {
            $result = $this->adminFacade->emptyDatabase();
            $message = $result['success'] ? 'Database emptied' : 'Empty database failed';
        }

        // Render page
        $this->render('Database Operations', true);
        $this->message($message, true);

        include $this->viewPath . 'backup.php';

        $this->endRender();
    }

    /**
     * Database wizard page.
     *
     * The wizard is a standalone page that can run without database connection.
     * It uses its own self-contained HTML output.
     *
     * Handles:
     * - op=Autocomplete: Auto-detect connection settings
     * - op=Check: Test connection with provided settings
     * - op=Change: Save new connection settings
     *
     * @param array<string, string> $params Route parameters
     *
     * @return void
     *
     * @psalm-suppress UnusedVariable Variables are used in included view files
     * @psalm-suppress UnresolvableInclude View path is constructed at runtime
     */
    public function wizard(array $params): void
    {
        $conn = null;
        $errorMessage = null;

        // Handle operations
        $op = $this->param('op');
        if ($op !== '') {
            if ($op === "Autocomplete") {
                $conn = $this->adminFacade->autocompleteConnection();
            } elseif ($op === "Check") {
                $formData = InputValidator::getMany([
                    'hostname' => 'string',
                    'login' => 'string',
                    'password' => 'string',
                    'dbname' => 'string',
                    'tbpref' => 'string',
                ]);
                $conn = $this->adminFacade->createConnectionFromForm($formData);
                $result = $this->adminFacade->testConnection($conn);
                $errorMessage = $result['success'] ? null : $result['error'];
            } elseif ($op === "Change") {
                $formData = InputValidator::getMany([
                    'hostname' => 'string',
                    'login' => 'string',
                    'password' => 'string',
                    'dbname' => 'string',
                    'tbpref' => 'string',
                ]);
                $conn = $this->adminFacade->createConnectionFromForm($formData);
                $this->adminFacade->saveConnectionToEnv($conn);
                // Redirect to home after saving
                $this->redirect('/');
            }
        } elseif ($this->adminFacade->envFileExists()) {
            $conn = $this->adminFacade->loadConnection();
        } else {
            $conn = $this->adminFacade->createEmptyConnection();
        }

        // The wizard view is standalone (includes its own HTML structure)
        include $this->viewPath . 'wizard.php';
    }

    /**
     * Settings page.
     *
     * Handles:
     * - op=Save: Save all settings
     * - op=Reset: Reset to defaults
     *
     * @param array<string, string> $params Route parameters
     *
     * @return void
     *
     * @psalm-suppress UnusedVariable Variables are used in included view files
     * @psalm-suppress UnresolvableInclude View path is constructed at runtime
     */
    public function settings(array $params): void
    {
        $message = '';

        // Handle form submission
        $op = $this->param('op');
        if ($op !== '') {
            if ($op === 'Save') {
                $result = $this->adminFacade->saveAllSettings();
                $message = $result['success'] ? 'Settings saved' : 'Failed to save settings';
            } else {
                $result = $this->adminFacade->resetAllSettings();
                $message = $result['success'] ? 'Settings reset to defaults' : 'Failed to reset settings';
            }
        }

        // Load current admin settings for the form (used by included view)
        $settings = $this->adminFacade->getAllSettings();

        // Render page
        $this->render('Admin Settings', true);
        $this->message($message, true);

        include $this->viewPath . 'settings_form.php';

        $this->endRender();
    }

    /**
     * Install demo page.
     *
     * Handles:
     * - install=xxx: Install the demo database
     *
     * @param array<string, string> $params Route parameters
     *
     * @return void
     *
     * @psalm-suppress UnusedVariable Variables are used in included view files
     * @psalm-suppress UnresolvableInclude View path is constructed at runtime
     */
    public function installDemo(array $params): void
    {
        $message = '';

        // Handle install request
        if ($this->hasParam('install')) {
            try {
                $message = $this->adminFacade->installDemo();
            } catch (\RuntimeException $e) {
                $message = 'Error: ' . $e->getMessage();
            }
        }

        // Get view data (used by included view)
        $langcnt = $this->adminFacade->getLanguageCount();

        // Render page
        $this->render('Install LWT Demo Database', true);
        $this->message($message, true);

        include $this->viewPath . 'install_demo.php';

        $this->endRender();
    }

    /**
     * Server data page.
     *
     * @param array<string, string> $params Route parameters
     *
     * @return void
     *
     * @psalm-suppress UnusedVariable Variables are used in included view files
     * @psalm-suppress UnresolvableInclude View path is constructed at runtime
     */
    public function serverData(array $params): void
    {
        $data = $this->adminFacade->getServerData();

        // Render page
        $this->render("Server Data", true);

        include $this->viewPath . 'server_data.php';

        $this->endRender();
    }
}
