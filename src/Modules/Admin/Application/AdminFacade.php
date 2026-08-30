<?php

/**
 * Admin Facade
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Admin\Application
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Admin\Application;

use Lwt\Shared\Infrastructure\Globals;
use Lwt\Modules\Admin\Application\DTO\DatabaseConnectionDTO;
use Lwt\Modules\Admin\Application\Services\SessionCleaner;
use Lwt\Modules\Admin\Application\UseCases\Backup\DownloadBackup;
use Lwt\Modules\Admin\Application\UseCases\Backup\DownloadOfficialBackup;
use Lwt\Modules\Admin\Application\UseCases\Backup\EmptyDatabase;
use Lwt\Modules\Admin\Application\UseCases\Backup\RestoreFromUpload;
use Lwt\Modules\Admin\Application\UseCases\Demo\GetLanguageCount;
use Lwt\Modules\Admin\Application\UseCases\Demo\InstallDemo;
use Lwt\Modules\Admin\Application\UseCases\ServerData\GetServerData;
use Lwt\Modules\Admin\Application\UseCases\Settings\GetAllSettings;
use Lwt\Modules\Admin\Application\UseCases\Settings\GetSetting;
use Lwt\Modules\Admin\Application\UseCases\Settings\ResetAllSettings;
use Lwt\Modules\Admin\Application\UseCases\Settings\SaveAllSettings;
use Lwt\Modules\Admin\Application\UseCases\Settings\SaveSetting;
use Lwt\Modules\Admin\Application\UseCases\Theme\GetAvailableThemes;
use Lwt\Modules\Admin\Application\UseCases\Wizard\AutocompleteConnection;
use Lwt\Modules\Admin\Application\UseCases\Wizard\LoadConnection;
use Lwt\Modules\Admin\Application\UseCases\Wizard\SaveConnection;
use Lwt\Modules\Admin\Application\UseCases\Wizard\TestConnection;
use Lwt\Modules\Admin\Domain\BackupRepositoryInterface;
use Lwt\Modules\Admin\Domain\SettingsRepositoryInterface;

/**
 * Facade providing unified interface to Admin module.
 *
 * This facade wraps the use cases to provide a similar interface
 * to the original services for gradual migration.
 *
 * @since 3.0.0
 */
class AdminFacade
{
    // Settings use cases
    private GetSetting $getSetting;
    private GetAllSettings $getAllSettings;
    private SaveAllSettings $saveAllSettings;
    private ResetAllSettings $resetAllSettings;
    private SaveSetting $saveSetting;

    // Backup use cases
    private RestoreFromUpload $restoreFromUpload;
    private DownloadBackup $downloadBackup;
    private DownloadOfficialBackup $downloadOfficialBackup;
    private EmptyDatabase $emptyDatabase;

    // Demo use cases
    private InstallDemo $installDemo;
    private GetLanguageCount $getLanguageCount;

    // ServerData use case
    private GetServerData $getServerData;

    // Theme use case
    private GetAvailableThemes $getAvailableThemes;

    // Wizard use cases
    private LoadConnection $loadConnection;
    private SaveConnection $saveConnection;
    private TestConnection $testConnection;
    private AutocompleteConnection $autocompleteConnection;


    /**
     * Constructor.
     *
     * @param SettingsRepositoryInterface $settingsRepository Settings repository
     * @param BackupRepositoryInterface   $backupRepository   Backup repository
     */
    public function __construct(
        SettingsRepositoryInterface $settingsRepository,
        BackupRepositoryInterface $backupRepository
    ) {
        // Initialize Settings use cases
        $this->getSetting = new GetSetting($settingsRepository);
        $this->getAllSettings = new GetAllSettings();
        $this->saveAllSettings = new SaveAllSettings();
        $this->resetAllSettings = new ResetAllSettings($settingsRepository);
        $this->saveSetting = new SaveSetting(new SessionCleaner());

        // Initialize Backup use cases
        $this->restoreFromUpload = new RestoreFromUpload($backupRepository);
        $this->downloadBackup = new DownloadBackup($backupRepository);
        $this->downloadOfficialBackup = new DownloadOfficialBackup($backupRepository);
        $this->emptyDatabase = new EmptyDatabase($backupRepository);

        // Initialize Demo use cases
        $this->installDemo = new InstallDemo();
        $this->getLanguageCount = new GetLanguageCount();

        // Initialize ServerData use case
        $this->getServerData = new GetServerData($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown');

        // Initialize Theme use case
        $this->getAvailableThemes = new GetAvailableThemes();

        // Initialize Wizard use cases
        $this->loadConnection = new LoadConnection();
        $this->saveConnection = new SaveConnection();
        $this->testConnection = new TestConnection();
        $this->autocompleteConnection = new AutocompleteConnection();
    }

    // =========================================================================
    // Settings Operations
    // =========================================================================

    /**
     * Get a setting value.
     *
     * @param string $key     Setting key
     * @param string $default Default value
     *
     * @return string Setting value
     */
    public function getSetting(string $key, string $default = ''): string
    {
        return $this->getSetting->execute($key, $default);
    }

    /**
     * Get all settings.
     *
     * @return array<string, string> All settings
     */
    public function getAllSettings(): array
    {
        return $this->getAllSettings->execute();
    }

    /**
     * Save all settings from form.
     *
     * @return array{success: bool}
     */
    public function saveAllSettings(): array
    {
        return $this->saveAllSettings->execute();
    }

    /**
     * Reset all settings to defaults.
     *
     * @return array{success: bool}
     */
    public function resetAllSettings(): array
    {
        return $this->resetAllSettings->execute();
    }

    /**
     * Save a single setting with optional session clearing.
     *
     * @param string $key   Setting key
     * @param string $value Setting value
     *
     * @return void
     */
    public function saveAndClearSession(string $key, string $value): void
    {
        $this->saveSetting->execute($key, $value);
    }

    // =========================================================================
    // Backup Operations
    // =========================================================================

    /**
     * Restore database from uploaded file.
     *
     * @param array{name: string, type: string, tmp_name: string, error: int, size: int}|null $fileData
     *        Validated file data from InputValidator::getUploadedFile()
     *
     * @return array{success: bool, error: ?string, message?: string}
     */
    public function restoreFromUpload(?array $fileData): array
    {
        return $this->restoreFromUpload->execute($fileData);
    }

    /**
     * Download LWT backup.
     *
     * @return void Outputs file and exits
     */
    public function downloadBackup(): void
    {
        $this->downloadBackup->execute();
    }

    /**
     * Download official format backup.
     *
     * @return void Outputs file and exits
     */
    public function downloadOfficialBackup(): void
    {
        $this->downloadOfficialBackup->execute();
    }

    /**
     * Empty the database.
     *
     * @return array{success: bool}
     */
    public function emptyDatabase(): array
    {
        return $this->emptyDatabase->execute();
    }

    /**
     * Get database name.
     *
     * @return string Database name
     */
    public function getDatabaseName(): string
    {
        return Globals::getDatabaseName();
    }

    // =========================================================================
    // Demo Operations
    // =========================================================================

    /**
     * Install demo database.
     *
     * @return string Status message
     */
    public function installDemo(): string
    {
        return $this->installDemo->execute();
    }

    /**
     * Get language count.
     *
     * @return int Number of languages
     */
    public function getLanguageCount(): int
    {
        return $this->getLanguageCount->execute();
    }

    // =========================================================================
    // ServerData Operations
    // =========================================================================

    /**
     * Get server data.
     *
     * @return array Server information
     */
    public function getServerData(): array
    {
        return $this->getServerData->execute();
    }

    // =========================================================================
    // Theme Operations
    // =========================================================================

    /**
     * Get available themes.
     *
     * @return array Theme list
     */
    public function getAvailableThemes(): array
    {
        return $this->getAvailableThemes->execute();
    }

    // =========================================================================
    // Wizard Operations
    // =========================================================================

    /**
     * Load database connection from .env.
     *
     * @return DatabaseConnectionDTO Connection data
     */
    public function loadConnection(): DatabaseConnectionDTO
    {
        return $this->loadConnection->execute();
    }

    /**
     * Check if .env file exists.
     *
     * @return bool True if exists
     */
    public function envFileExists(): bool
    {
        return $this->loadConnection->envExists();
    }

    /**
     * Get path to .env file.
     *
     * @return string Path
     */
    public function getEnvPath(): string
    {
        return $this->loadConnection->getEnvPath();
    }

    /**
     * Save database connection to .env.
     *
     * @param DatabaseConnectionDTO $connection Connection data
     *
     * @return bool True on success
     */
    public function saveConnectionToEnv(DatabaseConnectionDTO $connection): bool
    {
        return $this->saveConnection->execute($connection);
    }

    /**
     * Test database connection.
     *
     * @param DatabaseConnectionDTO $connection Connection data
     *
     * @return array{success: bool, error: ?string}
     */
    public function testConnection(DatabaseConnectionDTO $connection): array
    {
        return $this->testConnection->execute($connection);
    }

    /**
     * Get autocomplete suggestions for connection.
     *
     * @return DatabaseConnectionDTO Pre-filled connection
     */
    public function autocompleteConnection(): DatabaseConnectionDTO
    {
        return $this->autocompleteConnection->execute();
    }

    /**
     * Create connection DTO from form data.
     *
     * @param array<string, mixed> $formData Form input
     *
     * @return DatabaseConnectionDTO Connection DTO
     */
    public function createConnectionFromForm(array $formData): DatabaseConnectionDTO
    {
        return DatabaseConnectionDTO::fromFormData($formData);
    }

    /**
     * Create empty connection DTO.
     *
     * @return DatabaseConnectionDTO Empty connection
     */
    public function createEmptyConnection(): DatabaseConnectionDTO
    {
        return new DatabaseConnectionDTO();
    }
}
