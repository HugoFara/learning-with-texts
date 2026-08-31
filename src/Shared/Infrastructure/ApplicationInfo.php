<?php

/**
 * \file
 * \brief Application information and version utilities.
 *
 * PHP version 8.2
 *
 * @category Core
 * @package  Lwt\Shared\Infrastructure
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Shared\Infrastructure;

/**
 * Application information and version utilities.
 *
 * Provides version information and utility methods for the LWT application.
 *
 * @since 3.0.0
 */
class ApplicationInfo
{
    /**
     * Version of this current LWT application.
     */
    public const VERSION = '3.6.0-fork';

    /**
     * Date of the latest published release of LWT.
     */
    public const RELEASE_DATE = '2026-08-30';

    /**
     * Get the application version for display to humans.
     *
     * @return string Version number with formatted date (e.g., "2.10.0-fork (April 01 2024)")
     */
    public static function getVersion(): string
    {
        $timestamp = \strtotime(self::RELEASE_DATE);
        $formattedDate = $timestamp !== false ? \date("F d Y", $timestamp) : self::RELEASE_DATE;
        return self::VERSION . " ($formattedDate)";
    }

    /**
     * Get a machine-readable version number.
     *
     * @return string Machine-readable version (e.g., "v002.010.000" for version 2.10.0)
     */
    public static function getVersionNumber(): string
    {
        $r = 'v';
        $v = self::getVersion();
        // Escape any detail like "-fork"
        $v = \preg_replace('/-\w+\d*/', '', $v) ?? $v;
        $pos = \strpos($v, ' ', 0);
        if ($pos === false) {
            throw new \InvalidArgumentException(
                "Invalid version format '$v': expected 'X.Y.Z (date)'"
            );
        }
        $vn = \preg_split("/[.]/", \substr($v, 0, $pos));
        if ($vn === false || \count($vn) < 3) {
            throw new \InvalidArgumentException(
                "Invalid version format '$v':"
                . " expected at least 3 version components (X.Y.Z)"
            );
        }
        $r .= \substr('000' . $vn[0], -3);
        $r .= \substr('000' . $vn[1], -3);
        $r .= \substr('000' . $vn[2], -3);
        return $r;
    }

    /**
     * Get the raw version string without formatting.
     *
     * @return string Raw version string (e.g., "2.10.0-fork")
     */
    public static function getRawVersion(): string
    {
        return self::VERSION;
    }

    /**
     * Get the release date.
     *
     * @return string Release date in YYYY-MM-DD format
     */
    public static function getReleaseDate(): string
    {
        return self::RELEASE_DATE;
    }
}
