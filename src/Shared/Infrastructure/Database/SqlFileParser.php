<?php

/**
 * \file
 * \brief SQL file parsing utilities.
 *
 * Provides functionality for parsing SQL files into individual queries.
 *
 * PHP version 8.2
 *
 * @category Database
 * @package  Lwt
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0 Split from kernel_utility.php
 */

declare(strict_types=1);

namespace Lwt\Shared\Infrastructure\Database;

/**
 * SQL file parser for reading and splitting SQL files into individual queries.
 *
 * @since 3.0.0
 */
class SqlFileParser
{
    /**
     * Parse a SQL file by returning an array of the different queries it contains.
     *
     * @param string $filename File name
     *
     * @return list<string>
     */
    public static function parseFile(string $filename): array
    {
        $content = @file_get_contents($filename);
        if ($content === false) {
            return array();
        }
        // Normalize line endings up front so statement splitting does not depend
        // on the file's origin OS. A CRLF/CR file (e.g. committed from Windows or
        // baked into an image) must parse the same as an LF file on a Linux host,
        // where PHP_EOL is "\n" and would never match a ";\r\n" boundary.
        $content = str_replace(["\r\n", "\r"], "\n", $content);

        $queries_list = array();
        $curr_content = '';
        foreach (explode("\n", $content) as $line) {
            // Skip comments
            if (str_starts_with($line, '-- ')) {
                continue;
            }
            // Add line (with its newline restored) to the accumulator
            $curr_content .= $line . "\n";
            // Pull out every complete statement, keep the trailing remainder
            $queries = explode(";\n", $curr_content);
            $curr_content = array_pop($queries);
            foreach ($queries as $query) {
                $queries_list[] = trim($query);
            }
        }
        // Add final query if there's any remaining content
        if (trim($curr_content) !== '') {
            $queries_list[] = trim($curr_content);
        }
        return $queries_list;
    }
}
