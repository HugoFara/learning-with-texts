<?php

/**
 * \file
 * \brief Form helper utilities for generating HTML form attributes.
 *
 * PHP version 8.2
 *
 * @category View
 * @package  Lwt
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Shared\UI\Helpers;

use Lwt\Shared\Infrastructure\Http\InputValidator;

/**
 * Helper class for generating HTML form attributes.
 *
 * Provides methods for generating checked/selected attributes
 * and other common form-related HTML generation.
 *
 * @since 3.0.0
 */
class FormHelper
{
    /**
     * Return a checked attribute if the value is truthy.
     *
     * @param mixed $value Some value that can be evaluated as a boolean
     *
     * @return string ' checked="checked" ' if value is true, '' otherwise
     */
    public static function getChecked(mixed $value): string
    {
        if (!isset($value)) {
            return '';
        }
        if ($value) {
            return ' checked="checked" ';
        }
        return '';
    }

    /**
     * Return a selected attribute if $value equals $selval.
     *
     * @param int|string|null $value  Current value
     * @param int|string      $selval Value to compare against
     *
     * @return string ' selected="selected" ' if values match, '' otherwise
     */
    public static function getSelected(int|string|null $value, int|string $selval): string
    {
        if (!isset($value)) {
            return '';
        }
        if ($value == $selval) {
            return ' selected="selected" ';
        }
        return '';
    }

    /**
     * Build an HTML option element.
     *
     * @param int|string      $value    Option value
     * @param string          $label    Option display text
     * @param int|string|null $selected Currently selected value
     * @param bool            $disabled Whether the option is disabled
     *
     * @return string HTML option element
     */
    public static function buildOption(
        int|string $value,
        string $label,
        int|string|null $selected = null,
        bool $disabled = false
    ): string {
        $attrs = 'value="' . htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8') . '"';
        $attrs .= self::getSelected($selected, $value);
        if ($disabled) {
            $attrs .= ' disabled="disabled"';
        }
        return '<option ' . $attrs . '>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</option>';
    }

    /**
     * Build an HTML option group separator.
     *
     * @param string $label Optional label for the separator
     *
     * @return string HTML disabled option element acting as separator
     */
    public static function buildSeparator(string $label = '--------'): string
    {
        return '<option disabled="disabled">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</option>';
    }

    /**
     * Check if a value exists in a request array parameter.
     *
     * @param mixed  $val  Value to look for
     * @param string $name Key of the request parameter
     *
     * @return string ' checked="checked" ' if found, ' ' otherwise
     */
    public static function checkInRequest(mixed $val, string $name): string
    {
        $arr = InputValidator::getArray($name);
        if (empty($arr)) {
            return ' ';
        }
        if (in_array($val, $arr)) {
            return ' checked="checked" ';
        }
        return ' ';
    }

    /**
     * Generate a hidden CSRF token field for forms.
     *
     * This method should be called within every form that uses
     * POST, PUT, DELETE, or PATCH methods for CSRF protection.
     *
     * @return string HTML hidden input element with CSRF token
     *
     * @example
     * <form method="post">
     *     <?php echo FormHelper::csrfField(); ?>
     *     <!-- other form fields -->
     * </form>
     */
    public static function csrfField(): string
    {
        // Delegate to CsrfMiddleware for token management
        return \Lwt\Shared\Infrastructure\Routing\Middleware\CsrfMiddleware::formField();
    }

    /**
     * Get the current CSRF token value.
     *
     * Useful for AJAX requests that need to send the token in a header.
     *
     * @return string The CSRF token
     */
    public static function csrfToken(): string
    {
        return \Lwt\Shared\Infrastructure\Routing\Middleware\CsrfMiddleware::getToken();
    }
}
