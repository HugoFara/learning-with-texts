<?php

/**
 * Text Entity
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Text\Domain
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    2.9.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Text\Domain;

use InvalidArgumentException;
use Lwt\Modules\Language\Domain\ValueObject\LanguageId;
use Lwt\Modules\Text\Domain\ValueObject\TextId;

/**
 * A text represented as a rich domain object.
 *
 * Texts are the primary learning material. Users read texts and learn
 * vocabulary from them. Each text belongs to a language and can have
 * associated media (audio/video).
 *
 * @category Lwt
 * @package  Lwt\Modules\Text\Domain
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */
class Text
{
    private TextId $id;
    private LanguageId $languageId;
    private string $title;
    private string $text;
    private string $annotatedText;
    private string $mediaUri;
    private string $sourceUri;
    private int $position;
    private float $audioPosition;

    /**
     * Private constructor - use factory methods instead.
     */
    private function __construct(
        TextId $id,
        LanguageId $languageId,
        string $title,
        string $text,
        string $annotatedText,
        string $mediaUri,
        string $sourceUri,
        int $position,
        float $audioPosition
    ) {
        $this->id = $id;
        $this->languageId = $languageId;
        $this->title = $title;
        $this->text = $text;
        $this->annotatedText = $annotatedText;
        $this->mediaUri = $mediaUri;
        $this->sourceUri = $sourceUri;
        $this->position = $position;
        $this->audioPosition = $audioPosition;
    }

    /**
     * Create a new text.
     *
     * @param LanguageId $languageId The language this text is in
     * @param string     $title      Text title
     * @param string     $content    The actual text content
     *
     * @return self
     *
     * @throws InvalidArgumentException If title or content is empty
     */
    public static function create(
        LanguageId $languageId,
        string $title,
        string $content
    ): self {
        $trimmedTitle = trim($title);
        if ($trimmedTitle === '') {
            throw new InvalidArgumentException('Text title cannot be empty');
        }

        $trimmedContent = trim($content);
        if ($trimmedContent === '') {
            throw new InvalidArgumentException('Text content cannot be empty');
        }

        return new self(
            TextId::new(),
            $languageId,
            $trimmedTitle,
            $trimmedContent,
            '',
            '',
            '',
            0,
            0.0
        );
    }

    /**
     * Reconstitute a text from persistence.
     *
     * @param int    $id            The text ID
     * @param int    $languageId    The language ID
     * @param string $title         Text title
     * @param string $text          Text content
     * @param string $annotatedText Annotated version
     * @param string $mediaUri      Media URI
     * @param string $sourceUri     Source URI
     * @param int    $position      Reading position
     * @param float  $audioPosition Audio position
     *
     * @return self
     *
     * @internal This method is for repository use only
     */
    public static function reconstitute(
        int $id,
        int $languageId,
        string $title,
        string $text,
        string $annotatedText,
        string $mediaUri,
        string $sourceUri,
        int $position,
        float $audioPosition
    ): self {
        return new self(
            TextId::fromInt($id),
            LanguageId::fromInt($languageId),
            $title,
            $text,
            $annotatedText,
            $mediaUri,
            $sourceUri,
            $position,
            $audioPosition
        );
    }

    // Domain behavior methods

    /**
     * Update the text title.
     *
     * @param string $title The new title
     *
     * @return void
     *
     * @throws InvalidArgumentException If title is empty
     */
    public function rename(string $title): void
    {
        $trimmedTitle = trim($title);
        if ($trimmedTitle === '') {
            throw new InvalidArgumentException('Text title cannot be empty');
        }
        $this->title = $trimmedTitle;
    }

    /**
     * Update the text content.
     *
     * Note: This will invalidate any existing annotations.
     *
     * @param string $content The new content
     *
     * @return void
     *
     * @throws InvalidArgumentException If content is empty
     */
    public function updateContent(string $content): void
    {
        $trimmedContent = trim($content);
        if ($trimmedContent === '') {
            throw new InvalidArgumentException('Text content cannot be empty');
        }
        $this->text = $trimmedContent;
        $this->annotatedText = ''; // Invalidate annotations
    }

    /**
     * Set the annotated version of the text.
     *
     * @param string $annotated The annotated text
     *
     * @return void
     */
    public function setAnnotatedText(string $annotated): void
    {
        $this->annotatedText = $annotated;
    }

    /**
     * Set the media URI (audio or video).
     *
     * @param string $uri The media URI (URL or local path)
     *
     * @return void
     */
    public function setMediaUri(string $uri): void
    {
        $this->mediaUri = trim($uri);
    }

    /**
     * Set the source URI.
     *
     * @param string $uri The source URI
     *
     * @return void
     */
    public function setSourceUri(string $uri): void
    {
        $this->sourceUri = trim($uri);
    }

    /**
     * Update the reading position.
     *
     * @param int $position The new position
     *
     * @return void
     */
    public function updatePosition(int $position): void
    {
        if ($position < 0) {
            $position = 0;
        }
        $this->position = $position;
    }

    /**
     * Update the audio position.
     *
     * @param float $position The new audio position in seconds
     *
     * @return void
     */
    public function updateAudioPosition(float $position): void
    {
        if ($position < 0.0) {
            $position = 0.0;
        }
        $this->audioPosition = $position;
    }

    /**
     * Reset reading progress.
     *
     * @return void
     */
    public function resetProgress(): void
    {
        $this->position = 0;
        $this->audioPosition = 0.0;
    }

    // Query methods

    /**
     * Check if the text has media attached.
     *
     * @return bool
     */
    public function hasMedia(): bool
    {
        return $this->mediaUri !== '';
    }

    /**
     * Check if the text has a source URI.
     *
     * @return bool
     */
    public function hasSource(): bool
    {
        return $this->sourceUri !== '';
    }

    /**
     * Check if the text has been annotated.
     *
     * @return bool
     */
    public function isAnnotated(): bool
    {
        return $this->annotatedText !== '';
    }

    /**
     * Check if reading has started.
     *
     * @return bool
     */
    public function hasStartedReading(): bool
    {
        return $this->position > 0 || $this->audioPosition > 0.0;
    }

    /**
     * Check if media is a YouTube video.
     *
     * @return bool
     */
    public function isYouTubeMedia(): bool
    {
        return str_contains($this->mediaUri, 'youtube.com')
            || str_contains($this->mediaUri, 'youtu.be');
    }

    /**
     * Check if media is a local file.
     *
     * @return bool
     */
    public function isLocalMedia(): bool
    {
        if ($this->mediaUri === '') {
            return false;
        }
        return !str_starts_with($this->mediaUri, 'http://')
            && !str_starts_with($this->mediaUri, 'https://');
    }

    /**
     * Get the word count of the text.
     *
     * @return int
     */
    public function wordCount(): int
    {
        $words = preg_split('/\s+/', $this->text, -1, PREG_SPLIT_NO_EMPTY);
        if ($words === false) {
            return 0;
        }
        return count($words);
    }

    /**
     * Get the character count of the text.
     *
     * @return int
     */
    public function characterCount(): int
    {
        return mb_strlen($this->text, 'UTF-8');
    }

    // Getters

    public function id(): TextId
    {
        return $this->id;
    }

    public function languageId(): LanguageId
    {
        return $this->languageId;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function text(): string
    {
        return $this->text;
    }

    public function annotatedText(): string
    {
        return $this->annotatedText;
    }

    public function mediaUri(): string
    {
        return $this->mediaUri;
    }

    public function sourceUri(): string
    {
        return $this->sourceUri;
    }

    public function position(): int
    {
        return $this->position;
    }

    public function audioPosition(): float
    {
        return $this->audioPosition;
    }

    /**
     * Internal method to set the ID after persistence.
     *
     * @param TextId $id The new ID
     *
     * @return void
     *
     * @internal This method is for repository use only
     */
    public function setId(TextId $id): void
    {
        if (!$this->id->isNew()) {
            throw new \LogicException('Cannot change ID of a persisted text');
        }
        $this->id = $id;
    }
}
