<?php

/**
 * FeedOptions Value Object
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
 * Value object representing feed options.
 *
 * Options are stored as comma-separated key=value pairs in the database.
 * This class provides type-safe access to common options.
 *
 * Supported options:
 * - edit_text: Whether to show edit form before importing (1 or 0)
 * - autoupdate: Auto-update interval (e.g., "2h", "1d", "1w")
 * - max_links: Maximum number of article links to keep
 * - max_texts: Maximum number of texts before auto-archival
 * - charset: Character encoding for article fetching
 * - tag: Tag name to apply to imported texts
 * - article_source: Source for article text (description, encoded, content, webpage)
 *
 * @category Lwt
 * @package  Lwt\Modules\Feed\Domain
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */
final class FeedOptions
{
    /** @var array<string, string> */
    private array $options;

    /**
     * @param array<string, string> $options
     */
    private function __construct(array $options)
    {
        $this->options = $options;
    }

    /**
     * Create from options string (comma-separated key=value pairs).
     *
     * @param string $optionsString Options string
     *
     * @return self
     */
    public static function fromString(string $optionsString): self
    {
        $optionsString = trim($optionsString);
        if ($optionsString === '') {
            return new self([]);
        }

        $options = [];
        $optionList = explode(',', $optionsString);

        foreach ($optionList as $opt) {
            $parts = explode('=', $opt, 2);
            $key = trim($parts[0] ?? '');
            $value = trim($parts[1] ?? '');

            if ($key !== '') {
                $options[$key] = $value;
            }
        }

        return new self($options);
    }

    /**
     * Create from array of options.
     *
     * @param array<string, string|int|bool|null> $options Options array
     *
     * @return self
     */
    public static function fromArray(array $options): self
    {
        $normalized = [];
        foreach ($options as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            if (is_bool($value)) {
                $normalized[$key] = $value ? '1' : '0';
            } else {
                $normalized[$key] = (string) $value;
            }
        }
        return new self($normalized);
    }

    /**
     * Create empty options.
     *
     * @return self
     */
    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * Get a specific option value.
     *
     * @param string $key Option key
     *
     * @return string|null Option value or null if not set
     */
    public function get(string $key): ?string
    {
        return $this->options[$key] ?? null;
    }

    /**
     * Check if an option is set.
     *
     * @param string $key Option key
     *
     * @return bool
     */
    public function has(string $key): bool
    {
        return isset($this->options[$key]);
    }

    /**
     * Get all options as array.
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->options;
    }

    /**
     * Serialize to string format for database storage.
     *
     * @return string
     */
    public function toString(): string
    {
        $parts = [];
        foreach ($this->options as $key => $value) {
            $parts[] = $key . '=' . $value;
        }
        return implode(',', $parts);
    }

    // Typed accessors for common options

    /**
     * Whether to show edit form before importing articles.
     *
     * @return bool
     */
    public function editText(): bool
    {
        return ($this->options['edit_text'] ?? '0') === '1';
    }

    /**
     * Get auto-update interval string (e.g., "2h", "1d", "1w").
     *
     * @return string|null
     */
    public function autoUpdate(): ?string
    {
        $value = $this->options['autoupdate'] ?? null;
        return $value !== '' ? $value : null;
    }

    /**
     * Get auto-update interval in seconds.
     *
     * @return int|null Seconds or null if not set/invalid
     */
    public function autoUpdateSeconds(): ?int
    {
        $interval = $this->autoUpdate();
        if ($interval === null) {
            return null;
        }

        if (str_contains($interval, 'h')) {
            $value = (int) str_replace('h', '', $interval);
            return 60 * 60 * $value;
        } elseif (str_contains($interval, 'd')) {
            $value = (int) str_replace('d', '', $interval);
            return 60 * 60 * 24 * $value;
        } elseif (str_contains($interval, 'w')) {
            $value = (int) str_replace('w', '', $interval);
            return 60 * 60 * 24 * 7 * $value;
        }

        return null;
    }

    /**
     * Get maximum number of article links to keep.
     *
     * @return int|null
     */
    public function maxLinks(): ?int
    {
        $value = $this->options['max_links'] ?? null;
        return $value !== null && $value !== '' ? (int) $value : null;
    }

    /**
     * Get maximum number of texts before auto-archival.
     *
     * @return int|null
     */
    public function maxTexts(): ?int
    {
        $value = $this->options['max_texts'] ?? null;
        return $value !== null && $value !== '' ? (int) $value : null;
    }

    /**
     * Get character encoding override for article fetching.
     *
     * @return string|null
     */
    public function charset(): ?string
    {
        $value = $this->options['charset'] ?? null;
        return $value !== '' ? $value : null;
    }

    /**
     * Get tag name to apply to imported texts.
     *
     * @return string|null
     */
    public function tag(): ?string
    {
        $value = $this->options['tag'] ?? null;
        return $value !== '' ? $value : null;
    }

    /**
     * Get article text source (description, encoded, content, webpage).
     *
     * @return string|null
     */
    public function articleSource(): ?string
    {
        $value = $this->options['article_source'] ?? null;
        return $value !== '' ? $value : null;
    }

    // Builder methods (immutable)

    /**
     * Return a new instance with edit_text option set.
     *
     * @param bool $editText Whether to show edit form
     *
     * @return self
     */
    public function withEditText(bool $editText): self
    {
        $options = $this->options;
        if ($editText) {
            $options['edit_text'] = '1';
        } else {
            unset($options['edit_text']);
        }
        return new self($options);
    }

    /**
     * Return a new instance with autoupdate option set.
     *
     * @param string|null $interval Interval string (e.g., "2h") or null to remove
     *
     * @return self
     */
    public function withAutoUpdate(?string $interval): self
    {
        $options = $this->options;
        if ($interval !== null && $interval !== '') {
            $options['autoupdate'] = $interval;
        } else {
            unset($options['autoupdate']);
        }
        return new self($options);
    }

    /**
     * Return a new instance with max_links option set.
     *
     * @param int|null $maxLinks Maximum links or null to remove
     *
     * @return self
     */
    public function withMaxLinks(?int $maxLinks): self
    {
        $options = $this->options;
        if ($maxLinks !== null && $maxLinks > 0) {
            $options['max_links'] = (string) $maxLinks;
        } else {
            unset($options['max_links']);
        }
        return new self($options);
    }

    /**
     * Return a new instance with max_texts option set.
     *
     * @param int|null $maxTexts Maximum texts or null to remove
     *
     * @return self
     */
    public function withMaxTexts(?int $maxTexts): self
    {
        $options = $this->options;
        if ($maxTexts !== null && $maxTexts > 0) {
            $options['max_texts'] = (string) $maxTexts;
        } else {
            unset($options['max_texts']);
        }
        return new self($options);
    }

    /**
     * Return a new instance with charset option set.
     *
     * @param string|null $charset Charset or null to remove
     *
     * @return self
     */
    public function withCharset(?string $charset): self
    {
        $options = $this->options;
        if ($charset !== null && $charset !== '') {
            $options['charset'] = $charset;
        } else {
            unset($options['charset']);
        }
        return new self($options);
    }

    /**
     * Return a new instance with tag option set.
     *
     * @param string|null $tag Tag name or null to remove
     *
     * @return self
     */
    public function withTag(?string $tag): self
    {
        $options = $this->options;
        if ($tag !== null && $tag !== '') {
            $options['tag'] = $tag;
        } else {
            unset($options['tag']);
        }
        return new self($options);
    }

    /**
     * Return a new instance with article_source option set.
     *
     * @param string|null $source Source type or null to remove
     *
     * @return self
     */
    public function withArticleSource(?string $source): self
    {
        $options = $this->options;
        if ($source !== null && $source !== '') {
            $options['article_source'] = $source;
        } else {
            unset($options['article_source']);
        }
        return new self($options);
    }
}
