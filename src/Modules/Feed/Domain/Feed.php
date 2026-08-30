<?php

/**
 * Feed Entity
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

use InvalidArgumentException;

/**
 * Feed entity representing an RSS/Atom feed subscription.
 *
 * Feeds are sources of texts that users can subscribe to. The feed
 * periodically fetches articles which can be imported as texts for reading.
 *
 * @category Lwt
 * @package  Lwt\Modules\Feed\Domain
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */
class Feed
{
    private ?int $id;
    private int $languageId;
    private string $name;
    private string $sourceUri;
    private string $articleSectionTags;
    private string $filterTags;
    private int $updateTimestamp;
    private FeedOptions $options;

    /**
     * Private constructor - use factory methods instead.
     */
    private function __construct(
        ?int $id,
        int $languageId,
        string $name,
        string $sourceUri,
        string $articleSectionTags,
        string $filterTags,
        int $updateTimestamp,
        FeedOptions $options
    ) {
        $this->id = $id;
        $this->languageId = $languageId;
        $this->name = $name;
        $this->sourceUri = $sourceUri;
        $this->articleSectionTags = $articleSectionTags;
        $this->filterTags = $filterTags;
        $this->updateTimestamp = $updateTimestamp;
        $this->options = $options;
    }

    /**
     * Create a new feed.
     *
     * @param int    $languageId         Language ID for this feed
     * @param string $name               Human-readable feed name
     * @param string $sourceUri          RSS/Atom feed URL
     * @param string $articleSectionTags XPath selector for article content
     * @param string $filterTags         XPath selectors for elements to remove
     * @param string $optionsString      Options string (key=value,key=value)
     *
     * @return self
     *
     * @throws InvalidArgumentException If name or sourceUri is empty
     */
    public static function create(
        int $languageId,
        string $name,
        string $sourceUri,
        string $articleSectionTags = '',
        string $filterTags = '',
        string $optionsString = ''
    ): self {
        $trimmedName = trim($name);
        if ($trimmedName === '') {
            throw new InvalidArgumentException('Feed name cannot be empty');
        }
        if (mb_strlen($trimmedName) > 40) {
            throw new InvalidArgumentException('Feed name cannot exceed 40 characters');
        }

        $trimmedUri = trim($sourceUri);
        if ($trimmedUri === '') {
            throw new InvalidArgumentException('Feed source URI cannot be empty');
        }
        if (mb_strlen($trimmedUri) > 200) {
            throw new InvalidArgumentException('Feed source URI cannot exceed 200 characters');
        }

        if ($languageId <= 0) {
            throw new InvalidArgumentException('Language ID must be positive');
        }

        return new self(
            null,
            $languageId,
            $trimmedName,
            $trimmedUri,
            trim($articleSectionTags),
            trim($filterTags),
            0,
            FeedOptions::fromString($optionsString)
        );
    }

    /**
     * Reconstitute a feed from persistence.
     *
     * @param int    $id                 Feed ID
     * @param int    $languageId         Language ID
     * @param string $name               Feed name
     * @param string $sourceUri          Source URI
     * @param string $articleSectionTags Article section XPath
     * @param string $filterTags         Filter XPath
     * @param int    $updateTimestamp    Last update timestamp
     * @param string $optionsString      Options string
     *
     * @return self
     *
     * @internal This method is for repository use only
     */
    public static function reconstitute(
        int $id,
        int $languageId,
        string $name,
        string $sourceUri,
        string $articleSectionTags,
        string $filterTags,
        int $updateTimestamp,
        string $optionsString
    ): self {
        return new self(
            $id,
            $languageId,
            $name,
            $sourceUri,
            $articleSectionTags,
            $filterTags,
            $updateTimestamp,
            FeedOptions::fromString($optionsString)
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
            (int) $record['NfID'],
            (int) $record['NfLgID'],
            (string) $record['NfName'],
            (string) $record['NfSourceURI'],
            (string) ($record['NfArticleSectionTags'] ?? ''),
            (string) ($record['NfFilterTags'] ?? ''),
            (int) ($record['NfUpdate'] ?? 0),
            (string) ($record['NfOptions'] ?? '')
        );
    }

    // Domain behavior methods

    /**
     * Update the feed name.
     *
     * @param string $name The new name
     *
     * @return void
     *
     * @throws InvalidArgumentException If name is empty or too long
     */
    public function rename(string $name): void
    {
        $trimmedName = trim($name);
        if ($trimmedName === '') {
            throw new InvalidArgumentException('Feed name cannot be empty');
        }
        if (mb_strlen($trimmedName) > 40) {
            throw new InvalidArgumentException('Feed name cannot exceed 40 characters');
        }
        $this->name = $trimmedName;
    }

    /**
     * Update the feed source URI.
     *
     * @param string $uri The new source URI
     *
     * @return void
     *
     * @throws InvalidArgumentException If URI is empty or too long
     */
    public function updateSourceUri(string $uri): void
    {
        $trimmedUri = trim($uri);
        if ($trimmedUri === '') {
            throw new InvalidArgumentException('Feed source URI cannot be empty');
        }
        if (mb_strlen($trimmedUri) > 200) {
            throw new InvalidArgumentException('Feed source URI cannot exceed 200 characters');
        }
        $this->sourceUri = $trimmedUri;
    }

    /**
     * Update the article section tags (XPath selector).
     *
     * @param string $tags XPath selector for article content
     *
     * @return void
     */
    public function updateArticleSectionTags(string $tags): void
    {
        $this->articleSectionTags = trim($tags);
    }

    /**
     * Update the filter tags (XPath selectors for removal).
     *
     * @param string $tags XPath selectors for elements to remove
     *
     * @return void
     */
    public function updateFilterTags(string $tags): void
    {
        $this->filterTags = trim($tags);
    }

    /**
     * Update the feed options.
     *
     * @param FeedOptions $options New options
     *
     * @return void
     */
    public function updateOptions(FeedOptions $options): void
    {
        $this->options = $options;
    }

    /**
     * Update the last update timestamp.
     *
     * @param int $timestamp Unix timestamp
     *
     * @return void
     */
    public function markUpdated(int $timestamp): void
    {
        $this->updateTimestamp = $timestamp;
    }

    /**
     * Change the language for this feed.
     *
     * @param int $languageId New language ID
     *
     * @return void
     *
     * @throws InvalidArgumentException If language ID is invalid
     */
    public function changeLanguage(int $languageId): void
    {
        if ($languageId <= 0) {
            throw new InvalidArgumentException('Language ID must be positive');
        }
        $this->languageId = $languageId;
    }

    /**
     * Update multiple feed properties at once.
     *
     * @param int         $languageId         Language ID
     * @param string      $name               Feed name
     * @param string      $sourceUri          Source URI
     * @param string      $articleSectionTags XPath selectors for article content
     * @param string      $filterTags         XPath selectors for elements to remove
     * @param FeedOptions $options            Feed options
     *
     * @return void
     *
     * @throws InvalidArgumentException If any value is invalid
     */
    public function update(
        int $languageId,
        string $name,
        string $sourceUri,
        string $articleSectionTags,
        string $filterTags,
        FeedOptions $options
    ): void {
        $this->changeLanguage($languageId);
        $this->rename($name);
        $this->updateSourceUri($sourceUri);
        $this->updateArticleSectionTags($articleSectionTags);
        $this->updateFilterTags($filterTags);
        $this->updateOptions($options);
    }

    // Query methods

    /**
     * Check if the feed has auto-update enabled.
     *
     * @return bool
     */
    public function hasAutoUpdate(): bool
    {
        return $this->options->autoUpdate() !== null;
    }

    /**
     * Check if the feed needs an update based on auto-update interval.
     *
     * @param int $currentTime Current Unix timestamp
     *
     * @return bool
     */
    public function needsUpdate(int $currentTime): bool
    {
        $interval = $this->options->autoUpdateSeconds();
        if ($interval === null) {
            return false;
        }
        return $currentTime > ($this->updateTimestamp + $interval);
    }

    /**
     * Check if this feed has been updated at least once.
     *
     * @return bool
     */
    public function hasBeenUpdated(): bool
    {
        return $this->updateTimestamp > 0;
    }

    /**
     * Check if this is a new (unsaved) feed.
     *
     * @return bool
     */
    public function isNew(): bool
    {
        return $this->id === null;
    }

    // Getters

    public function id(): ?int
    {
        return $this->id;
    }

    public function languageId(): int
    {
        return $this->languageId;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function sourceUri(): string
    {
        return $this->sourceUri;
    }

    public function articleSectionTags(): string
    {
        return $this->articleSectionTags;
    }

    public function filterTags(): string
    {
        return $this->filterTags;
    }

    public function updateTimestamp(): int
    {
        return $this->updateTimestamp;
    }

    public function options(): FeedOptions
    {
        return $this->options;
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
            throw new \LogicException('Cannot change ID of a persisted feed');
        }
        $this->id = $id;
    }
}
