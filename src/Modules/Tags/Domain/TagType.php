<?php

/**
 * Tag Type Enum
 *
 * Discriminates between term tags and text tags.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Tags\Domain
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Tags\Domain;

/**
 * Enum representing the type of tag (term or text).
 *
 * Each type has its own database table and column naming conventions.
 *
 * @since 3.0.0
 */
enum TagType: string
{
    case TERM = 'term';
    case TEXT = 'text';

    /**
     * Get the main table name for this tag type.
     *
     * @return string Table name ('tags' or 'text_tags')
     */
    public function tableName(): string
    {
        return match ($this) {
            self::TERM => 'tags',
            self::TEXT => 'text_tags',
        };
    }

    /**
     * Get the column prefix for this tag type.
     *
     * @return string Column prefix ('Tg' or 'T2')
     */
    public function columnPrefix(): string
    {
        return match ($this) {
            self::TERM => 'Tg',
            self::TEXT => 'T2',
        };
    }

    /**
     * Get the ID column name.
     *
     * @return string Column name ('TgID' or 'T2ID')
     */
    public function idColumn(): string
    {
        return $this->columnPrefix() . 'ID';
    }

    /**
     * Get the text column name.
     *
     * @return string Column name ('TgText' or 'T2Text')
     */
    public function textColumn(): string
    {
        return $this->columnPrefix() . 'Text';
    }

    /**
     * Get the comment column name.
     *
     * @return string Column name ('TgComment' or 'T2Comment')
     */
    public function commentColumn(): string
    {
        return $this->columnPrefix() . 'Comment';
    }

    /**
     * Get the user ID column name.
     *
     * @return string Column name ('TgUsID' or 'T2UsID')
     */
    public function userIdColumn(): string
    {
        return $this->columnPrefix() . 'UsID';
    }

    /**
     * Get the primary association table for this tag type.
     *
     * For term tags, this is 'word_tag_map'.
     * For text tags, this is 'text_tag_map'.
     *
     * @return string Table name
     */
    public function associationTable(): string
    {
        return match ($this) {
            self::TERM => 'word_tag_map',
            self::TEXT => 'text_tag_map',
        };
    }

    /**
     * Get the human-readable label for this tag type.
     *
     * @return string 'Term' or 'Text'
     */
    public function label(): string
    {
        return match ($this) {
            self::TERM => 'Term',
            self::TEXT => 'Text',
        };
    }

    /**
     * Get the base URL for this tag type.
     *
     * @return string '/tags' or '/tags/text'
     */
    public function baseUrl(): string
    {
        return match ($this) {
            self::TERM => '/tags',
            self::TEXT => '/tags/text',
        };
    }

    /**
     * Get the items URL pattern for this tag type.
     *
     * @return string URL pattern for viewing tagged items
     */
    public function itemsUrlPattern(): string
    {
        return match ($this) {
            self::TERM => '/words?tag=%d',
            self::TEXT => '/texts?tag=%d',
        };
    }

    /**
     * Check if this is a term tag type.
     *
     * @return bool
     */
    public function isTerm(): bool
    {
        return $this === self::TERM;
    }

    /**
     * Check if this is a text tag type.
     *
     * @return bool
     */
    public function isText(): bool
    {
        return $this === self::TEXT;
    }
}
