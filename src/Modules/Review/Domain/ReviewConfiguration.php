<?php

/**
 * Review Configuration Value Object
 *
 * Represents the configuration for a review session.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Review\Domain
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Review\Domain;

/**
 * Value object representing review configuration parameters.
 *
 * Immutable after creation. Encapsulates review type, selection mode,
 * and other review parameters.
 *
 * @since 3.0.0
 */
final readonly class ReviewConfiguration
{
    /**
     * Review key types.
     */
    public const KEY_LANG = 'lang';
    public const KEY_TEXT = 'text';
    public const KEY_WORDS = 'words';
    public const KEY_TEXTS = 'texts';
    public const KEY_RAW_SQL = 'raw_sql';

    /**
     * Review types.
     */
    public const TYPE_TERM_TO_TRANSLATION = 1;
    public const TYPE_TRANSLATION_TO_TERM = 2;
    public const TYPE_SENTENCE_TO_TERM = 3;
    public const TYPE_TERM_TO_TRANSLATION_WORD = 4;
    public const TYPE_TRANSLATION_TO_TERM_WORD = 5;

    /**
     * Constructor.
     *
     * @param string          $reviewKey   Review key type (lang, text, words, texts, raw_sql)
     * @param int|int[]|string $selection   Selection value (ID, array of IDs, or SQL string)
     * @param int             $reviewType  Review type (1-5)
     * @param bool            $wordMode    Whether in word mode (no sentence)
     * @param bool            $isTableMode Whether in table review mode
     * @param array<int, int|string> $rawParams Pre-bound parameters for KEY_RAW_SQL
     */
    public function __construct(
        public string $reviewKey,
        public int|array|string $selection,
        public int $reviewType = 1,
        public bool $wordMode = false,
        public bool $isTableMode = false,
        public array $rawParams = []
    ) {
    }

    /**
     * Create configuration for reviewing a language.
     *
     * @param int  $langId     Language ID
     * @param int  $reviewType Review type (1-5)
     * @param bool $wordMode   Word mode flag
     *
     * @return self
     */
    public static function fromLanguage(int $langId, int $reviewType = 1, bool $wordMode = false): self
    {
        return new self(
            self::KEY_LANG,
            $langId,
            self::clampReviewType($reviewType),
            $wordMode || $reviewType > 3,
            false
        );
    }

    /**
     * Create configuration for reviewing a text.
     *
     * @param int  $textId     Text ID
     * @param int  $reviewType Review type (1-5)
     * @param bool $wordMode   Word mode flag
     *
     * @return self
     */
    public static function fromText(int $textId, int $reviewType = 1, bool $wordMode = false): self
    {
        return new self(
            self::KEY_TEXT,
            $textId,
            self::clampReviewType($reviewType),
            $wordMode || $reviewType > 3,
            false
        );
    }

    /**
     * Create configuration for reviewing specific words.
     *
     * @param int[] $wordIds    Array of word IDs
     * @param int   $reviewType Review type (1-5)
     * @param bool  $wordMode   Word mode flag
     *
     * @return self
     */
    public static function fromWords(array $wordIds, int $reviewType = 1, bool $wordMode = false): self
    {
        return new self(
            self::KEY_WORDS,
            array_map('intval', $wordIds),
            self::clampReviewType($reviewType),
            $wordMode || $reviewType > 3,
            false
        );
    }

    /**
     * Create configuration for reviewing words from specific texts.
     *
     * @param int[] $textIds    Array of text IDs
     * @param int   $reviewType Review type (1-5)
     * @param bool  $wordMode   Word mode flag
     *
     * @return self
     */
    public static function fromTexts(array $textIds, int $reviewType = 1, bool $wordMode = false): self
    {
        return new self(
            self::KEY_TEXTS,
            array_map('intval', $textIds),
            self::clampReviewType($reviewType),
            $wordMode || $reviewType > 3,
            false
        );
    }

    /**
     * Create table mode configuration.
     *
     * @param string          $reviewKey Review key type
     * @param int|int[]|string $selection Selection value
     *
     * @return self
     */
    public static function forTableMode(string $reviewKey, int|array|string $selection): self
    {
        return new self($reviewKey, $selection, 1, false, true);
    }

    /**
     * Get base review type (1-3, strips word mode offset).
     *
     * @return int Base review type
     */
    public function getBaseType(): int
    {
        return $this->reviewType > 3 ? $this->reviewType - 3 : $this->reviewType;
    }

    /**
     * Get SQL projection string with prepared statement placeholders.
     *
     * Returns SQL with `?` placeholders and pushes bound values into $params.
     *
     * @param array<int, int|string> $params Reference to params array for binding
     *
     * @return string SQL fragment for FROM/WHERE clause with ? placeholders
     *
     * @throws \InvalidArgumentException If review key is invalid
     */
    public function toSqlProjectionPrepared(array &$params): string
    {
        return match ($this->reviewKey) {
            self::KEY_LANG => $this->langPrepared($params),
            self::KEY_TEXT => $this->textPrepared($params),
            self::KEY_WORDS => $this->wordsPrepared($params),
            self::KEY_TEXTS => $this->textsPrepared($params),
            self::KEY_RAW_SQL => $this->rawSqlPrepared($params),
            default => throw new \InvalidArgumentException("Invalid review key: {$this->reviewKey}")
        };
    }

    /**
     * Build prepared SQL for language selection.
     *
     * @param array<int, int|string> $params Reference to params array
     *
     * @return string SQL fragment
     */
    private function langPrepared(array &$params): string
    {
        $langId = is_int($this->selection)
            ? $this->selection
            : (int) (is_array($this->selection) ? ($this->selection[0] ?? 0) : $this->selection);
        $params[] = $langId;
        return " words WHERE WoLgID = ? " . self::appendUserScope($params) . " ";
    }

    /**
     * Build prepared SQL for text selection.
     *
     * @param array<int, int|string> $params Reference to params array
     *
     * @return string SQL fragment
     */
    private function textPrepared(array &$params): string
    {
        $textId = is_int($this->selection)
            ? $this->selection
            : (int) (is_array($this->selection) ? ($this->selection[0] ?? 0) : $this->selection);
        $params[] = $textId;
        return " words, word_occurrences WHERE Ti2LgID = WoLgID AND Ti2WoID = WoID AND Ti2TxID = ? "
            . self::appendUserScope($params) . " ";
    }

    /**
     * Build prepared SQL for word ID list selection.
     *
     * @param array<int, int|string> $params Reference to params array
     *
     * @return string SQL fragment
     */
    private function wordsPrepared(array &$params): string
    {
        $ids = is_array($this->selection) ? $this->selection : [$this->selection];
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        foreach ($ids as $id) {
            $params[] = (int) $id;
        }
        return " words WHERE WoID IN ($placeholders) " . self::appendUserScope($params) . " ";
    }

    /**
     * Build prepared SQL for text ID list selection.
     *
     * @param array<int, int|string> $params Reference to params array
     *
     * @return string SQL fragment
     */
    private function textsPrepared(array &$params): string
    {
        $ids = is_array($this->selection) ? $this->selection : [$this->selection];
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        foreach ($ids as $id) {
            $params[] = (int) $id;
        }
        return " words, word_occurrences WHERE Ti2LgID = WoLgID AND Ti2WoID = WoID AND Ti2TxID IN ($placeholders) "
            . self::appendUserScope($params) . " ";
    }

    /**
     * Build prepared SQL for raw SQL selection.
     *
     * @param array<int, int|string> $params Reference to params array
     *
     * @return string SQL fragment
     */
    private function rawSqlPrepared(array &$params): string
    {
        foreach ($this->rawParams as $p) {
            $params[] = $p;
        }
        return is_string($this->selection) ? $this->selection : '';
    }

    /**
     * Get selection as string for URL parameters.
     *
     * @return string Selection as comma-separated string
     */
    public function getSelectionString(): string
    {
        if (is_array($this->selection)) {
            return implode(',', $this->selection);
        }
        return (string) $this->selection;
    }

    /**
     * Get URL property string for this configuration.
     *
     * @return string URL property (e.g., "lang=1" or "text=42")
     */
    public function toUrlProperty(): string
    {
        $selectionStr = $this->getSelectionString();
        return match ($this->reviewKey) {
            self::KEY_LANG => "lang={$selectionStr}",
            self::KEY_TEXT => "text={$selectionStr}",
            self::KEY_WORDS => "selection=2",
            self::KEY_TEXTS => "selection=3",
            default => ''
        };
    }

    /**
     * Check if configuration is valid (has a review key).
     *
     * @return bool True if valid
     */
    public function isValid(): bool
    {
        return $this->reviewKey !== '';
    }

    /**
     * Clamp review type to valid range.
     *
     * @param int $reviewType Raw review type
     *
     * @return int Clamped to 1-5
     */
    private static function clampReviewType(int $reviewType): int
    {
        return max(1, min(5, $reviewType));
    }

    /**
     * Append the words-table user scope to $params and return the SQL fragment.
     *
     * Bridges Psalm's typed-bindings ($params is `int|string`) and
     * UserScopedQuery's `mixed` reference signature: we collect the
     * appended user id locally and copy it across with a known type.
     *
     * @param array<int, int|string> $params Reference to params array
     *
     * @return string SQL scope fragment (e.g. " AND WoUsID = ?")
     */
    private static function appendUserScope(array &$params): string
    {
        // Inline the words-table user scope so Psalm preserves the
        // `int|string` element type of $params (UserScopedQuery's
        // by-reference signature uses `array<int, mixed>`, which would
        // pollute the type otherwise). The appended value is always an
        // int (the current UsID).
        if (!\Lwt\Shared\Infrastructure\Globals::isMultiUserEnabled()) {
            return '';
        }
        $userId = \Lwt\Shared\Infrastructure\Globals::getCurrentUserId();
        if ($userId === null) {
            return '';
        }
        $params[] = $userId;
        return ' AND WoUsID = ?';
    }
}
