<?php

/**
 * \file
 * \brief Value object for a single parsed text token.
 *
 * PHP version 8.2
 *
 * @category Database
 * @package  Lwt
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.2.2
 */

declare(strict_types=1);

namespace Lwt\Shared\Infrastructure\Database;

/**
 * One token produced while parsing a text: a word or a non-word run
 * (whitespace / punctuation).
 *
 * This replaces the rows that used to be written to the `temp_word_occurrences`
 * scratch table during parsing. Tokens are produced fully in PHP and consumed
 * by TokenPersistence, so no scratch table is needed. See ScratchTables for the
 * history of why the old temp tables were removed.
 *
 * Field meanings mirror the former temp_word_occurrences columns:
 *  - $sentence: 1-based sentence index within this text (was TiSeID, but local,
 *    not a pre-computed SeID).
 *  - $order: global monotonic token order across the whole text (was TiOrder).
 *  - $wordCount: 1 for a word token, 0 for a non-word run (was TiWordCount).
 *  - $text: the token text (was TiText).
 *
 * @since 3.2.2
 */
final class ParsedToken
{
    /**
     * @param int    $sentence  1-based sentence index within the text
     * @param int    $order     Global monotonic token order across the text
     * @param int    $wordCount 1 for a word, 0 for a non-word run
     * @param string $text      The token text
     */
    public function __construct(
        public readonly int $sentence,
        public readonly int $order,
        public readonly int $wordCount,
        public readonly string $text
    ) {
    }

    /**
     * Whether this token is a word (as opposed to whitespace/punctuation).
     *
     * @return bool
     */
    public function isWord(): bool
    {
        return $this->wordCount !== 0;
    }
}
