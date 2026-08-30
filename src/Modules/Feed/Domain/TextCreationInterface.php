<?php

/**
 * Text Creation Interface
 *
 * Domain port for creating texts from feed articles.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Feed\Domain
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Feed\Domain;

/**
 * Interface for creating texts from feed articles.
 *
 * This port allows the Feed module to create texts without
 * directly depending on TextService/TextRepository. The infrastructure
 * layer provides an adapter implementation.
 *
 * @since 3.0.0
 */
interface TextCreationInterface
{
    /**
     * Create a text from extracted article content.
     *
     * The implementation should:
     * 1. Insert the text into the texts table
     * 2. Parse the text into sentences and textitems
     * 3. Create/find the tag and associate it with the text
     *
     * @param int    $languageId Language ID for the text
     * @param string $title      Text title
     * @param string $text       Text content
     * @param string $audioUri   Audio URI (optional, empty string if none)
     * @param string $sourceUri  Source URI (the article link)
     * @param string $tagName    Tag name to apply to the text
     *
     * @return int The newly created text ID
     */
    public function createText(
        int $languageId,
        string $title,
        string $text,
        string $audioUri,
        string $sourceUri,
        string $tagName
    ): int;

    /**
     * Archive old texts to maintain max texts limit.
     *
     * Archives the oldest texts with the given tag until the count
     * is at or below maxTexts.
     *
     * @param string $tagName  Tag name to filter by
     * @param int    $maxTexts Maximum number of texts to keep
     *
     * @return array{archived: int, sentences: int, textitems: int}
     */
    public function archiveOldTexts(string $tagName, int $maxTexts): array;

    /**
     * Count texts with the given tag.
     *
     * @param string $tagName Tag name
     *
     * @return int
     */
    public function countTextsWithTag(string $tagName): int;

    /**
     * Check if a text with the given source URI already exists.
     *
     * @param string $sourceUri Source URI to check
     *
     * @return bool True if a text or archived text exists with this source URI
     */
    public function sourceUriExists(string $sourceUri): bool;
}
