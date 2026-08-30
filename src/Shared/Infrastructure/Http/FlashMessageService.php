<?php

/**
 * Flash Message Service
 *
 * Handles session-based flash messages that persist for one request.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Shared\Infrastructure\Http
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Shared\Infrastructure\Http;

/**
 * Flash message service for displaying one-time messages after redirects.
 *
 * Flash messages are stored in the session and cleared after being retrieved.
 * Supports multiple message types (info, success, warning, error).
 *
 * @since 3.0.0
 */
class FlashMessageService
{
    /**
     * Session key for flash messages.
     */
    private const KEY_FLASH = 'flash_messages';

    /**
     * Message type constants.
     */
    public const TYPE_INFO = 'info';
    public const TYPE_SUCCESS = 'success';
    public const TYPE_WARNING = 'warning';
    public const TYPE_ERROR = 'error';

    /**
     * Ensure session is started.
     *
     * @return bool True if session is available, false otherwise
     */
    private function ensureSession(): bool
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return true;
        }
        if (session_status() === PHP_SESSION_NONE) {
            // Check if headers have already been sent
            if (headers_sent()) {
                // Cannot start session after headers are sent
                return false;
            }
            session_start();
            return true;
        }
        return false;
    }

    /**
     * Add a flash message.
     *
     * @param string $message Message text
     * @param string $type    Message type (info, success, warning, error)
     *
     * @return void
     */
    public function add(string $message, string $type = self::TYPE_INFO): void
    {
        if (!$this->ensureSession()) {
            return;
        }
        if (!isset($_SESSION[self::KEY_FLASH]) || !is_array($_SESSION[self::KEY_FLASH])) {
            $_SESSION[self::KEY_FLASH] = [];
        }
        $_SESSION[self::KEY_FLASH][] = [
            'message' => $message,
            'type' => $type,
        ];
    }

    /**
     * Add an info message.
     *
     * @param string $message Message text
     *
     * @return void
     */
    public function info(string $message): void
    {
        $this->add($message, self::TYPE_INFO);
    }

    /**
     * Add a success message.
     *
     * @param string $message Message text
     *
     * @return void
     */
    public function success(string $message): void
    {
        $this->add($message, self::TYPE_SUCCESS);
    }

    /**
     * Add a warning message.
     *
     * @param string $message Message text
     *
     * @return void
     */
    public function warning(string $message): void
    {
        $this->add($message, self::TYPE_WARNING);
    }

    /**
     * Add an error message.
     *
     * @param string $message Message text
     *
     * @return void
     */
    public function error(string $message): void
    {
        $this->add($message, self::TYPE_ERROR);
    }

    /**
     * Add multiple messages at once.
     *
     * @param array<string> $messages Array of message strings
     * @param string        $type     Message type for all messages
     *
     * @return void
     */
    public function addMany(array $messages, string $type = self::TYPE_INFO): void
    {
        foreach ($messages as $message) {
            $this->add($message, $type);
        }
    }

    /**
     * Check if there are any flash messages.
     *
     * @param string|null $type Optional type filter
     *
     * @return bool
     *
     * @psalm-suppress MixedAssignment
     */
    public function has(?string $type = null): bool
    {
        if (!$this->ensureSession()) {
            return false;
        }
        if (!isset($_SESSION[self::KEY_FLASH]) || !is_array($_SESSION[self::KEY_FLASH])) {
            return false;
        }
        if ($type === null) {
            return count($_SESSION[self::KEY_FLASH]) > 0;
        }
        foreach ($_SESSION[self::KEY_FLASH] as $msg) {
            if (is_array($msg) && isset($msg['type']) && $msg['type'] === $type) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get all flash messages and clear them.
     *
     * @return array<array{message: string, type: string}>
     */
    public function getAndClear(): array
    {
        if (!$this->ensureSession()) {
            return [];
        }
        if (!isset($_SESSION[self::KEY_FLASH]) || !is_array($_SESSION[self::KEY_FLASH])) {
            return [];
        }
        /** @var array<array{message: string, type: string}> $messages */
        $messages = $_SESSION[self::KEY_FLASH];
        unset($_SESSION[self::KEY_FLASH]);
        return $messages;
    }

    /**
     * Get flash messages by type and clear them.
     *
     * @param string $type Message type to retrieve
     *
     * @return array<array{message: string, type: string}>
     *
     * @psalm-suppress MixedAssignment
     */
    public function getByTypeAndClear(string $type): array
    {
        if (!$this->ensureSession()) {
            return [];
        }
        if (!isset($_SESSION[self::KEY_FLASH]) || !is_array($_SESSION[self::KEY_FLASH])) {
            return [];
        }

        $messages = [];
        $remaining = [];

        foreach ($_SESSION[self::KEY_FLASH] as $msg) {
            if (is_array($msg) && isset($msg['type'])) {
                if ($msg['type'] === $type) {
                    /** @var array{message: string, type: string} $msg */
                    $messages[] = $msg;
                } else {
                    $remaining[] = $msg;
                }
            }
        }

        $_SESSION[self::KEY_FLASH] = $remaining;
        return $messages;
    }

    /**
     * Get only message strings and clear.
     *
     * @return array<string>
     */
    public function getMessagesAndClear(): array
    {
        $messages = $this->getAndClear();
        return array_map(fn($m) => $m['message'], $messages);
    }

    /**
     * Clear all flash messages without retrieving them.
     *
     * @return void
     */
    public function clear(): void
    {
        if (!$this->ensureSession()) {
            return;
        }
        unset($_SESSION[self::KEY_FLASH]);
    }

    /**
     * Get the Bulma notification CSS class for a message type.
     *
     * @param string $type Message type
     *
     * @return string Bulma notification class (e.g., 'is-success', 'is-danger')
     */
    public static function getCssClass(string $type): string
    {
        return match ($type) {
            self::TYPE_SUCCESS => 'is-success',
            self::TYPE_WARNING => 'is-warning',
            self::TYPE_ERROR => 'is-danger',
            self::TYPE_INFO => 'is-info',
            default => 'is-info',
        };
    }

    /**
     * Check if message type indicates an error.
     *
     * @param string $type Message type
     *
     * @return bool
     */
    public static function isError(string $type): bool
    {
        return $type === self::TYPE_ERROR;
    }
}
