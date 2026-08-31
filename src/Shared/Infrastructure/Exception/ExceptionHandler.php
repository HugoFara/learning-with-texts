<?php

/**
 * Global Exception Handler
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Shared\Infrastructure\Exception
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Shared\Infrastructure\Exception;

use ErrorException;
use Throwable;

/**
 * Global exception and error handler for LWT.
 *
 * Registers handlers for uncaught exceptions and PHP errors,
 * logs errors, and displays appropriate error pages.
 *
 * @since 3.0.0
 */
class ExceptionHandler
{
    /**
     * Whether we're in debug mode (show detailed errors).
     *
     * @var bool
     */
    private bool $debug;

    /**
     * Path to the log file.
     *
     * @var string|null
     */
    private ?string $logFile;

    /**
     * Whether handlers are registered.
     *
     * @var bool
     */
    private static bool $registered = false;

    /**
     * The singleton instance.
     *
     * @var self|null
     */
    private static ?self $instance = null;

    /**
     * Create a new exception handler.
     *
     * @param bool        $debug   Whether to show detailed error information
     * @param string|null $logFile Path to log file (null to disable file logging)
     */
    public function __construct(bool $debug = false, ?string $logFile = null)
    {
        $this->debug = $debug;
        $this->logFile = $logFile;
    }

    /**
     * Get or create the singleton instance.
     *
     * @param bool        $debug   Whether to show detailed errors
     * @param string|null $logFile Path to log file
     *
     * @return self
     */
    public static function getInstance(
        bool $debug = false,
        ?string $logFile = null
    ): self {
        if (self::$instance === null) {
            self::$instance = new self($debug, $logFile);
        }
        return self::$instance;
    }

    /**
     * Register the exception and error handlers.
     *
     * @return void
     */
    public function register(): void
    {
        if (self::$registered) {
            return;
        }

        // Don't register handlers during PHPUnit tests
        if ($this->isRunningInPhpUnit()) {
            return;
        }

        set_exception_handler([$this, 'handleException']);
        set_error_handler([$this, 'handleError']);
        register_shutdown_function([$this, 'handleShutdown']);

        self::$registered = true;
    }

    /**
     * Check if running in PHPUnit.
     *
     * @return bool
     */
    private function isRunningInPhpUnit(): bool
    {
        return class_exists('PHPUnit\Framework\TestCase', false)
            || defined('PHPUNIT_COMPOSER_INSTALL')
            || defined('__PHPUNIT_PHAR__');
    }

    /**
     * Handle an uncaught exception.
     *
     * @param Throwable $exception The exception to handle
     *
     * @return void
     */
    public function handleException(Throwable $exception): void
    {
        // Log the exception
        $this->logException($exception);

        // Determine HTTP status code
        $statusCode = 500;
        if ($exception instanceof LwtException) {
            $statusCode = $exception->getHttpStatusCode();
        }

        // Send HTTP status header if not already sent
        if (!headers_sent()) {
            http_response_code($statusCode);
        }

        // Check if this is an API request
        if ($this->isApiRequest()) {
            $this->renderJsonError($exception, $statusCode);
        } else {
            $this->renderHtmlError($exception, $statusCode);
        }
    }

    /**
     * Handle a PHP error by converting to ErrorException.
     *
     * @param int    $severity Error severity level
     * @param string $message  Error message
     * @param string $file     File where error occurred
     * @param int    $line     Line number
     *
     * @return bool False to use PHP's default error handler
     *
     * @throws ErrorException When error is in error_reporting mask
     *
     * @psalm-suppress PossiblyUnusedReturnValue Return value used by PHP error handling
     */
    public function handleError(
        int $severity,
        string $message,
        string $file,
        int $line
    ): bool {
        // Respect error_reporting settings
        if (!(error_reporting() & $severity)) {
            return false;
        }

        throw new ErrorException($message, 0, $severity, $file, $line);
    }

    /**
     * Handle fatal errors on shutdown.
     *
     * @return void
     */
    public function handleShutdown(): void
    {
        $error = error_get_last();

        if ($error === null) {
            return;
        }

        // Only handle fatal errors
        $fatalErrors = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
        if (!in_array($error['type'], $fatalErrors, true)) {
            return;
        }

        $exception = new ErrorException(
            $error['message'],
            0,
            $error['type'],
            $error['file'],
            $error['line']
        );

        $this->handleException($exception);
    }

    /**
     * Log an exception.
     *
     * @param Throwable $exception The exception to log
     *
     * @return void
     */
    public function logException(Throwable $exception): void
    {
        // Check if this exception should be logged
        if ($exception instanceof LwtException && !$exception->shouldLog()) {
            return;
        }

        $logMessage = $this->formatLogMessage($exception);

        // Log to file if configured
        if ($this->logFile !== null) {
            $this->writeToLogFile($logMessage);
        }

        // Always log to PHP error log
        error_log($logMessage);
    }

    /**
     * Format an exception for logging.
     *
     * @param Throwable $exception The exception to format
     *
     * @return string
     */
    private function formatLogMessage(Throwable $exception): string
    {
        $timestamp = date('Y-m-d H:i:s');
        $type = get_class($exception);
        $message = $exception->getMessage();
        $file = $exception->getFile();
        $line = $exception->getLine();

        $logMessage = sprintf(
            "[%s] %s: %s in %s:%d",
            $timestamp,
            $type,
            $message,
            $file,
            $line
        );

        // Add context for LwtException
        if ($exception instanceof LwtException) {
            $context = $exception->getContext();
            if (!empty($context)) {
                $encoded = json_encode($context, JSON_UNESCAPED_SLASHES);
                $logMessage .= "\nContext: " . ($encoded !== false ? $encoded : '{}');
            }
        }

        // Add stack trace
        $logMessage .= "\nStack trace:\n" . $exception->getTraceAsString();

        // Add previous exception if exists
        if ($exception->getPrevious() !== null) {
            $logMessage .= "\n\nCaused by: " . $this->formatLogMessage($exception->getPrevious());
        }

        return $logMessage;
    }

    /**
     * Write a message to the log file.
     *
     * @param string $message The message to write
     *
     * @return void
     */
    private function writeToLogFile(string $message): void
    {
        if ($this->logFile === null) {
            return;
        }

        // Ensure log directory exists
        $logDir = dirname($this->logFile);
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }

        // Append to log file
        @file_put_contents(
            $this->logFile,
            $message . "\n" . str_repeat('-', 80) . "\n",
            FILE_APPEND | LOCK_EX
        );
    }

    /**
     * Check if the current request is an API request.
     *
     * @return bool
     */
    private function isApiRequest(): bool
    {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';

        // Check URL path
        if (str_contains($requestUri, '/api/') || str_contains($requestUri, '/api.php')) {
            return true;
        }

        // Check Accept header
        if (str_contains($accept, 'application/json')) {
            return true;
        }

        // Check X-Requested-With header (AJAX)
        $xRequestedWith = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
        if (strtolower($xRequestedWith) === 'xmlhttprequest') {
            return true;
        }

        return false;
    }

    /**
     * Render a JSON error response.
     *
     * @param Throwable $exception  The exception
     * @param int       $statusCode HTTP status code
     *
     * @return void
     */
    private function renderJsonError(Throwable $exception, int $statusCode): void
    {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }

        $response = [
            'error' => true,
            'message' => $this->getUserMessage($exception),
            'status' => $statusCode,
        ];

        // Add validation errors if applicable
        if ($exception instanceof ValidationException) {
            $response['errors'] = $exception->getErrors();
        }

        // Add debug info if enabled
        if ($this->debug) {
            $response['debug'] = [
                'type' => get_class($exception),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => explode("\n", $exception->getTraceAsString()),
            ];

            if ($exception instanceof LwtException) {
                $response['debug']['context'] = $exception->getContext();
            }
        }

        echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Render an HTML error page.
     *
     * @param Throwable $exception  The exception
     * @param int       $statusCode HTTP status code
     *
     * @return void
     */
    private function renderHtmlError(Throwable $exception, int $statusCode): void
    {
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
        }

        $title = $this->getStatusTitle($statusCode);
        $userMessage = $this->getUserMessage($exception);

        echo '<!DOCTYPE html>';
        echo '<html lang="en"><head>';
        echo '<meta charset="UTF-8">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
        echo '<title>Error - ' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</title>';
        echo '<style>';
        echo 'body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; ';
        echo 'margin: 0; padding: 0; background: #f5f5f5; color: #333; }';
        echo '.container { max-width: 800px; margin: 50px auto; padding: 30px; ';
        echo 'background: white; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }';
        echo 'h1 { color: #d32f2f; margin-top: 0; border-bottom: 2px solid #d32f2f; padding-bottom: 15px; }';
        echo '.message { font-size: 1.1em; margin: 20px 0; padding: 15px; background: #fff3cd; ';
        echo 'border-left: 4px solid #ffc107; border-radius: 4px; }';
        echo '.debug { margin-top: 30px; padding: 20px; background: #f8f9fa; border-radius: 4px; }';
        echo '.debug h3 { margin-top: 0; color: #666; }';
        echo '.debug pre { background: #2d2d2d; color: #f8f8f2; padding: 15px; ';
        echo 'border-radius: 4px; overflow-x: auto; font-size: 13px; line-height: 1.5; }';
        echo '.context { margin: 15px 0; padding: 10px; background: #e3f2fd; border-radius: 4px; }';
        echo '.actions { margin-top: 30px; }';
        echo '.btn { display: inline-block; padding: 10px 20px; margin-right: 10px; ';
        echo 'background: #1976d2; color: white; text-decoration: none; border-radius: 4px; }';
        echo '.btn:hover { background: #1565c0; }';
        echo '.support { margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee; ';
        echo 'color: #666; font-size: 0.9em; }';
        echo '.support a { color: #1976d2; }';
        echo '</style>';
        echo '</head><body>';

        echo '<div class="container">';
        echo '<h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>';
        echo '<div class="message">' . htmlspecialchars($userMessage, ENT_QUOTES, 'UTF-8') . '</div>';

        // Debug information (only in debug mode)
        if ($this->debug) {
            echo '<div class="debug">';
            echo '<h3>Debug Information</h3>';
            $exceptionClass = htmlspecialchars(get_class($exception), ENT_QUOTES, 'UTF-8');
            $exceptionMsg = htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8');
            echo '<p><strong>Exception:</strong> ' . $exceptionClass . '</p>';
            echo '<p><strong>Message:</strong> ' . $exceptionMsg . '</p>';
            echo '<p><strong>File:</strong> ' . htmlspecialchars($exception->getFile(), ENT_QUOTES, 'UTF-8');
            echo ':' . $exception->getLine() . '</p>';

            // Show context for LwtException
            if ($exception instanceof LwtException) {
                $context = $exception->getContext();
                if (!empty($context)) {
                    echo '<div class="context">';
                    echo '<strong>Context:</strong><br>';
                    $encoded = json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                    echo '<pre>' . htmlspecialchars(
                        $encoded !== false ? $encoded : '',
                        ENT_QUOTES,
                        'UTF-8'
                    ) . '</pre>';
                    echo '</div>';
                }
            }

            echo '<h4>Stack Trace</h4>';
            echo '<pre>' . htmlspecialchars($exception->getTraceAsString(), ENT_QUOTES, 'UTF-8') . '</pre>';
            echo '</div>';
        }

        echo '<div class="actions">';
        echo '<a href="/" class="btn">&larr; Home</a>';
        echo '</div>';

        echo '<div class="support">';
        echo '<p>If this error persists, please report it on ';
        echo '<a href="https://github.com/HugoFara/lwt/issues/new/choose" target="_blank">GitHub</a> ';
        echo '</div>';

        echo '</div></body></html>';
    }

    /**
     * Get a user-friendly message for the exception.
     *
     * @param Throwable $exception The exception
     *
     * @return string
     */
    private function getUserMessage(Throwable $exception): string
    {
        if ($exception instanceof LwtException) {
            return $exception->getUserMessage();
        }

        // Generic message for non-LWT exceptions
        return 'An unexpected error occurred. Please try again later.';
    }

    /**
     * Get a title for the HTTP status code.
     *
     * @param int $statusCode HTTP status code
     *
     * @return string
     */
    private function getStatusTitle(int $statusCode): string
    {
        $titles = [
            400 => 'Bad Request',
            401 => 'Authentication Required',
            403 => 'Access Denied',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            409 => 'Conflict',
            422 => 'Validation Error',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
        ];

        return $titles[$statusCode] ?? 'Error';
    }

    /**
     * Set debug mode.
     *
     * @param bool $debug Whether to show debug information
     *
     * @return self
     */
    public function setDebug(bool $debug): self
    {
        $this->debug = $debug;
        return $this;
    }

    /**
     * Set the log file path.
     *
     * @param string|null $logFile Path to log file
     *
     * @return self
     */
    public function setLogFile(?string $logFile): self
    {
        $this->logFile = $logFile;
        return $this;
    }

    /**
     * Check if handlers are registered.
     *
     * @return bool
     */
    public static function isRegistered(): bool
    {
        return self::$registered;
    }

    /**
     * Reset the handler state (for testing).
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$registered = false;
        self::$instance = null;
        restore_exception_handler();
        restore_error_handler();
    }
}
