<?php

/**
 * Article Entity
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
 * Article entity representing an RSS feed item (feed_links table).
 *
 * Articles are individual items fetched from RSS feeds. They can be
 * imported as texts for reading practice.
 *
 * @category Lwt
 * @package  Lwt\Modules\Feed\Domain
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */
class Article
{
    /**
     * Article status constants.
     */
    public const STATUS_NEW = 'new';
    public const STATUS_IMPORTED = 'imported';
    public const STATUS_ARCHIVED = 'archived';
    public const STATUS_ERROR = 'error';

    private ?int $id;
    private int $feedId;
    private string $title;
    private string $link;
    private string $description;
    private string $date;
    private string $audio;
    private string $text;

    /**
     * Private constructor - use factory methods instead.
     */
    private function __construct(
        ?int $id,
        int $feedId,
        string $title,
        string $link,
        string $description,
        string $date,
        string $audio,
        string $text
    ) {
        $this->id = $id;
        $this->feedId = $feedId;
        $this->title = $title;
        $this->link = $link;
        $this->description = $description;
        $this->date = $date;
        $this->audio = $audio;
        $this->text = $text;
    }

    /**
     * Create a new article from RSS feed item.
     *
     * @param int    $feedId      Feed ID this article belongs to
     * @param string $title       Article title
     * @param string $link        Article URL
     * @param string $description Article description/summary
     * @param string $date        Publication date (MySQL datetime format)
     * @param string $audio       Audio URL (for podcasts)
     * @param string $text        Extracted article text
     *
     * @return self
     */
    public static function create(
        int $feedId,
        string $title,
        string $link,
        string $description = '',
        string $date = '',
        string $audio = '',
        string $text = ''
    ): self {
        return new self(
            null,
            $feedId,
            self::truncate($title, 200),
            self::truncate($link, 400),
            $description,
            $date ?: date('Y-m-d H:i:s'),
            self::truncate($audio, 200),
            $text
        );
    }

    /**
     * Reconstitute an article from persistence.
     *
     * @param int    $id          Article ID
     * @param int    $feedId      Feed ID
     * @param string $title       Title
     * @param string $link        Link
     * @param string $description Description
     * @param string $date        Date
     * @param string $audio       Audio URL
     * @param string $text        Text content
     *
     * @return self
     *
     * @internal This method is for repository use only
     */
    public static function reconstitute(
        int $id,
        int $feedId,
        string $title,
        string $link,
        string $description,
        string $date,
        string $audio,
        string $text
    ): self {
        return new self(
            $id,
            $feedId,
            $title,
            $link,
            $description,
            $date,
            $audio,
            $text
        );
    }

    /**
     * Load from a database record.
     *
     * @param array<string, mixed> $record Database record
     *
     * @return self
     */
    public static function fromDbRecord(array $record): self
    {
        return self::reconstitute(
            (int) $record['FlID'],
            (int) $record['FlNfID'],
            (string) $record['FlTitle'],
            (string) $record['FlLink'],
            (string) ($record['FlDescription'] ?? ''),
            (string) ($record['FlDate'] ?? ''),
            (string) ($record['FlAudio'] ?? ''),
            (string) ($record['FlText'] ?? '')
        );
    }

    /**
     * Truncate string to max length.
     *
     * @param string $value     String to truncate
     * @param int    $maxLength Maximum length
     *
     * @return string
     */
    private static function truncate(string $value, int $maxLength): string
    {
        if (mb_strlen($value) > $maxLength) {
            return mb_substr($value, 0, $maxLength);
        }
        return $value;
    }

    // Domain behavior methods

    /**
     * Update the extracted text content.
     *
     * @param string $text New text content
     *
     * @return void
     */
    public function updateText(string $text): void
    {
        $this->text = $text;
    }

    /**
     * Mark article as error by prefixing link with space.
     *
     * This is a legacy pattern - articles with space-prefixed links
     * are considered "unloadable" and won't be re-fetched.
     *
     * @return void
     */
    public function markAsError(): void
    {
        if (!str_starts_with($this->link, ' ')) {
            $this->link = ' ' . $this->link;
        }
    }

    /**
     * Reset error status by trimming the link.
     *
     * @return void
     */
    public function resetError(): void
    {
        $this->link = trim($this->link);
    }

    // Query methods

    /**
     * Check if this article has an error (unloadable).
     *
     * @return bool
     */
    public function hasError(): bool
    {
        return str_starts_with($this->link, ' ');
    }

    /**
     * Check if this article has audio attached.
     *
     * @return bool
     */
    public function hasAudio(): bool
    {
        return $this->audio !== '';
    }

    /**
     * Check if this article has extracted text.
     *
     * @return bool
     */
    public function hasText(): bool
    {
        return $this->text !== '';
    }

    /**
     * Check if this is a new (unsaved) article.
     *
     * @return bool
     */
    public function isNew(): bool
    {
        return $this->id === null;
    }

    /**
     * Get the clean link (without error marker).
     *
     * @return string
     */
    public function cleanLink(): string
    {
        return trim($this->link);
    }

    /**
     * Determine article status based on associated text/archived data.
     *
     * @param int|null $textId     Associated text ID (from LEFT JOIN)
     * @param int|null $archivedId Associated archived text ID (from LEFT JOIN)
     *
     * @return string One of STATUS_* constants
     */
    public function determineStatus(?int $textId, ?int $archivedId): string
    {
        if ($this->hasError()) {
            return self::STATUS_ERROR;
        }
        if ($textId !== null && $textId > 0) {
            return self::STATUS_IMPORTED;
        }
        if ($archivedId !== null && $archivedId > 0) {
            return self::STATUS_ARCHIVED;
        }
        return self::STATUS_NEW;
    }

    // Getters

    public function id(): ?int
    {
        return $this->id;
    }

    public function feedId(): int
    {
        return $this->feedId;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function link(): string
    {
        return $this->link;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function date(): string
    {
        return $this->date;
    }

    public function audio(): string
    {
        return $this->audio;
    }

    public function text(): string
    {
        return $this->text;
    }

    /**
     * Internal method to set the ID after persistence.
     *
     * @param int $id The new ID
     *
     * @return void
     *
     * @internal This method is for repository use only
     */
    public function setId(int $id): void
    {
        if ($this->id !== null) {
            throw new \LogicException('Cannot change ID of a persisted article');
        }
        $this->id = $id;
    }
}
