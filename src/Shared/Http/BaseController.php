<?php

/**
 * \file
 * \brief Base Controller for MVC architecture
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Shared\Http
 * @author   HugoFara <git@hugofara.net>
 * @license Unlicense <http://unlicense.org/>
 * @link    https://hugofara.github.io/lwt/developer/api
 * @since   3.0.0
 */

declare(strict_types=1);

namespace Lwt\Shared\Http;

use Lwt\Shared\Infrastructure\Http\InputValidator;
use Lwt\Shared\Infrastructure\Http\RedirectResponse;
use Lwt\Shared\Infrastructure\Http\JsonResponse;
use Lwt\Shared\Infrastructure\Database\Connection;
use Lwt\Shared\Infrastructure\Database\DB;
use Lwt\Shared\UI\Helpers\PageLayoutHelper;

/**
 * Abstract base controller providing common functionality for all controllers.
 *
 * Controllers should extend this class to access database connections,
 * utility functions, and rendering helpers.
 *
 * @category Lwt
 * @package  Lwt\Shared\Http
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */
abstract class BaseController
{
    /**
     * Initialize controller.
     */
    public function __construct()
    {
        // Base initialization - subclasses can override
    }

    /**
     * Start page rendering with standard LWT header.
     *
     * @param string $title    Page title
     * @param bool   $showMenu Whether to show navigation menu (default: true)
     *
     * @return void
     */
    protected function render(string $title, bool $showMenu = true): void
    {
        PageLayoutHelper::renderPageStart($title, $showMenu);
    }

    /**
     * End page rendering with standard LWT footer.
     *
     * @return void
     */
    protected function endRender(): void
    {
        PageLayoutHelper::renderPageEnd();
    }

    /**
     * Display a message (success/error) to the user using Bulma notifications.
     *
     * @param string $message  The message to display
     * @param bool   $autoHide Whether to auto-hide the message (default: true)
     *
     * @return void
     */
    protected function message(string $message, bool $autoHide = true): void
    {
        if (trim($message) == '') {
            return;
        }
        $escapedMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        $isError = str_starts_with($message, "Error");
        $notificationType = $isError ? 'is-danger' : 'is-success';
        $autoHideAttr = $autoHide && !$isError ? ' x-init="setTimeout(() => visible = false, 3000)"' : '';

        echo '<div class="notification ' . $notificationType . ' is-light mb-4" '
            . 'x-data="{ visible: true }"' . $autoHideAttr . ' x-show="visible" x-transition>';
        echo '<button class="delete" @click="visible = false"></button>';
        echo $escapedMessage;
        if ($isError && !$autoHide) {
            echo '<br><button class="button is-small mt-2" data-action="history-back">'
                . '&larr; Go back and correct</button>';
        }
        echo '</div>';
    }

    /**
     * Get a string request parameter (GET, POST, or REQUEST).
     *
     * @param string $key     Parameter name
     * @param string $default Default value if not set
     *
     * @return string Parameter value or default
     */
    protected function param(string $key, string $default = ''): string
    {
        return InputValidator::getString($key, $default);
    }

    /**
     * Check if a parameter exists in the request.
     *
     * @param string $key Parameter name
     *
     * @return bool True if the parameter exists
     */
    protected function hasParam(string $key): bool
    {
        return InputValidator::has($key);
    }

    /**
     * Get a string GET parameter.
     *
     * @param string $key     Parameter name
     * @param string $default Default value if not set
     *
     * @return string Parameter value or default
     */
    protected function get(string $key, string $default = ''): string
    {
        return InputValidator::getStringFromGet($key, $default);
    }

    /**
     * Get a string POST parameter.
     *
     * @param string $key     Parameter name
     * @param string $default Default value if not set
     *
     * @return string Parameter value or default
     */
    protected function post(string $key, string $default = ''): string
    {
        return InputValidator::getStringFromPost($key, $default);
    }

    /**
     * Get an integer request parameter.
     *
     * @param string   $key     Parameter name
     * @param int|null $default Default value if not set
     * @param int|null $min     Minimum allowed value
     * @param int|null $max     Maximum allowed value
     *
     * @return int|null Parameter value or default
     */
    protected function paramInt(string $key, ?int $default = null, ?int $min = null, ?int $max = null): ?int
    {
        return InputValidator::getInt($key, $default, $min, $max);
    }

    /**
     * Get a required integer request parameter.
     *
     * @param string   $key Parameter name
     * @param int|null $min Minimum allowed value
     * @param int|null $max Maximum allowed value
     *
     * @return int Parameter value
     *
     * @throws \InvalidArgumentException If parameter is missing or invalid
     */
    protected function requireInt(string $key, ?int $min = null, ?int $max = null): int
    {
        return InputValidator::requireInt($key, $min, $max);
    }

    /**
     * Get an array request parameter.
     *
     * @param string $key     Parameter name
     * @param array  $default Default value if not set
     *
     * @return array Parameter value or default
     */
    protected function paramArray(string $key, array $default = []): array
    {
        return InputValidator::getArray($key, $default);
    }

    /**
     * Check if the request is a POST request.
     *
     * @return bool
     */
    protected function isPost(): bool
    {
        return InputValidator::isPost();
    }

    /**
     * Check if the request is a GET request.
     *
     * @return bool
     */
    protected function isGet(): bool
    {
        return InputValidator::isGet();
    }

    /**
     * Redirect to another URL.
     *
     * Return this from a controller method; the router will send it.
     *
     * @param string $url        URL to redirect to
     * @param int    $statusCode HTTP status code (default: 302)
     *
     * @return RedirectResponse
     */
    protected function redirect(string $url, int $statusCode = 302): RedirectResponse
    {
        return new RedirectResponse($url, $statusCode);
    }

    /**
     * Execute a database query using the LWT query wrapper.
     *
     * @param string $sql SQL query
     *
     * @return \mysqli_result|bool Query result
     */
    protected function query(string $sql): \mysqli_result|bool
    {
        return Connection::query($sql);
    }

    /**
     * Execute an INSERT/UPDATE/DELETE query.
     *
     * @param string $sql SQL query
     *
     * @return int Number of affected rows
     *
     * @psalm-suppress PossiblyUnusedReturnValue
     */
    protected function execute(string $sql): int
    {
        return DB::execute($sql);
    }

    /**
     * Get a single value from the database.
     *
     * @param string $sql SQL query (should return single value as 'value')
     *
     * @return mixed The value or null
     */
    protected function getValue(string $sql): mixed
    {
        return Connection::fetchValue($sql);
    }

    /**
     * Return JSON response.
     *
     * Return this from a controller method; the router will send it.
     *
     * @param mixed $data   Data to encode as JSON
     * @param int   $status HTTP status code (default: 200)
     *
     * @return JsonResponse
     */
    protected function json(mixed $data, int $status = 200): JsonResponse
    {
        return new JsonResponse($data, $status);
    }

    /**
     * Get IDs from marked checkboxes.
     *
     * @param string|array $marked The 'marked' request parameter value
     *
     * @return int[] Array of integer IDs
     *
     * @psalm-return array<int>
     */
    protected function getMarkedIds(string|array $marked): array
    {
        if (!is_array($marked)) {
            return [];
        }
        return array_map('intval', $marked);
    }
}
