<?php

/**
 * Tag Entity
 *
 * Domain entity representing a tag (term or text).
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

use InvalidArgumentException;
use Lwt\Modules\Tags\Domain\ValueObject\TagId;

/**
 * Tag entity representing a term tag or text tag.
 *
 * Tags are used to categorize words (term tags) or texts (text tags).
 * Both types share the same structure: ID, text, and comment.
 *
 * @since 3.0.0
 */
class Tag
{
    private TagId $id;
    private TagType $type;
    private string $text;
    private string $comment;

    /**
     * Maximum length for tag text.
     */
    public const MAX_TEXT_LENGTH = 20;

    /**
     * Maximum length for tag comment.
     */
    public const MAX_COMMENT_LENGTH = 200;

    /**
     * Private constructor - use factory methods instead.
     *
     * @param TagId   $id      Tag ID
     * @param TagType $type    Tag type (term or text)
     * @param string  $text    Tag text
     * @param string  $comment Tag comment
     */
    private function __construct(
        TagId $id,
        TagType $type,
        string $text,
        string $comment
    ) {
        $this->id = $id;
        $this->type = $type;
        $this->text = $text;
        $this->comment = $comment;
    }

    /**
     * Create a new tag.
     *
     * @param TagType $type    Tag type (term or text)
     * @param string  $text    Tag text (max 20 chars, no spaces/commas)
     * @param string  $comment Tag comment (max 200 chars)
     *
     * @return self
     *
     * @throws InvalidArgumentException If text is empty or invalid
     */
    public static function create(
        TagType $type,
        string $text,
        string $comment = ''
    ): self {
        $normalizedText = self::normalizeText($text);
        self::validateText($normalizedText);
        self::validateComment($comment);

        return new self(
            TagId::new(),
            $type,
            $normalizedText,
            trim($comment)
        );
    }

    /**
     * Reconstitute a tag from persistence.
     *
     * @param int     $id      Tag ID
     * @param TagType $type    Tag type
     * @param string  $text    Tag text
     * @param string  $comment Tag comment
     *
     * @return self
     *
     * @internal This method is for repository use only
     */
    public static function reconstitute(
        int $id,
        TagType $type,
        string $text,
        string $comment
    ): self {
        return new self(
            TagId::fromInt($id),
            $type,
            $text,
            $comment
        );
    }

    /**
     * Normalize tag text.
     *
     * Trims whitespace and removes spaces/commas (not allowed in tags).
     *
     * @param string $text Raw tag text
     *
     * @return string Normalized text
     */
    private static function normalizeText(string $text): string
    {
        $text = trim($text);
        // Remove spaces and commas as they are used as separators
        return str_replace([' ', ','], '', $text);
    }

    /**
     * Validate tag text.
     *
     * @param string $text The text to validate
     *
     * @return void
     *
     * @throws InvalidArgumentException If text is invalid
     */
    private static function validateText(string $text): void
    {
        if ($text === '') {
            throw new InvalidArgumentException('Tag text cannot be empty');
        }

        if (mb_strlen($text) > self::MAX_TEXT_LENGTH) {
            throw new InvalidArgumentException(
                sprintf(
                    'Tag text cannot exceed %d characters, got %d',
                    self::MAX_TEXT_LENGTH,
                    mb_strlen($text)
                )
            );
        }
    }

    /**
     * Validate tag comment.
     *
     * @param string $comment The comment to validate
     *
     * @return void
     *
     * @throws InvalidArgumentException If comment is too long
     */
    private static function validateComment(string $comment): void
    {
        if (mb_strlen($comment) > self::MAX_COMMENT_LENGTH) {
            throw new InvalidArgumentException(
                sprintf(
                    'Tag comment cannot exceed %d characters, got %d',
                    self::MAX_COMMENT_LENGTH,
                    mb_strlen($comment)
                )
            );
        }
    }

    // Domain behavior methods

    /**
     * Rename the tag.
     *
     * @param string $text New tag text
     *
     * @return void
     *
     * @throws InvalidArgumentException If text is invalid
     */
    public function rename(string $text): void
    {
        $normalizedText = self::normalizeText($text);
        self::validateText($normalizedText);
        $this->text = $normalizedText;
    }

    /**
     * Update the tag comment.
     *
     * @param string $comment New comment
     *
     * @return void
     *
     * @throws InvalidArgumentException If comment is too long
     */
    public function updateComment(string $comment): void
    {
        self::validateComment($comment);
        $this->comment = trim($comment);
    }

    // Query methods

    /**
     * Check if this is an unsaved entity.
     *
     * @return bool
     */
    public function isNew(): bool
    {
        return $this->id->isNew();
    }

    /**
     * Get the tag ID.
     *
     * @return TagId
     */
    public function id(): TagId
    {
        return $this->id;
    }

    /**
     * Get the tag type.
     *
     * @return TagType
     */
    public function type(): TagType
    {
        return $this->type;
    }

    /**
     * Get the tag text.
     *
     * @return string
     */
    public function text(): string
    {
        return $this->text;
    }

    /**
     * Get the tag comment.
     *
     * @return string
     */
    public function comment(): string
    {
        return $this->comment;
    }

    /**
     * Check if this is a term tag.
     *
     * @return bool
     */
    public function isTermTag(): bool
    {
        return $this->type->isTerm();
    }

    /**
     * Check if this is a text tag.
     *
     * @return bool
     */
    public function isTextTag(): bool
    {
        return $this->type->isText();
    }

    /**
     * Internal method to set the ID after persistence.
     *
     * @param TagId $id The new ID
     *
     * @return void
     *
     * @throws \LogicException If the tag already has an ID
     *
     * @internal This method is for repository use only
     */
    public function setId(TagId $id): void
    {
        if (!$this->id->isNew()) {
            throw new \LogicException('Cannot change ID of a persisted tag');
        }
        $this->id = $id;
    }

    /**
     * Convert to array for backward compatibility.
     *
     * @return array{id: int, text: string, comment: string, type: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id->toInt(),
            'text' => $this->text,
            'comment' => $this->comment,
            'type' => $this->type->value,
        ];
    }
}
