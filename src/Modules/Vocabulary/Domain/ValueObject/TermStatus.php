<?php

/**
 * Term Status Value Object
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Vocabulary\Domain\ValueObject
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Vocabulary\Domain\ValueObject;

use InvalidArgumentException;

/**
 * Value object representing a Term's learning status.
 *
 * Encapsulates the business rules around term status transitions.
 *
 * Status values:
 * - 1-5: Learning stages (1=new, 5=learned)
 * - 98: Ignored words
 * - 99: Well-known words
 *
 * @since 3.0.0
 */
final readonly class TermStatus
{
    /** @var int New/unknown term */
    public const NEW = 1;

    /** @var int Learning stage 2 */
    public const LEARNING_2 = 2;

    /** @var int Learning stage 3 */
    public const LEARNING_3 = 3;

    /** @var int Learning stage 4 */
    public const LEARNING_4 = 4;

    /** @var int Fully learned */
    public const LEARNED = 5;

    /** @var int Ignored term */
    public const IGNORED = 98;

    /** @var int Well-known term (no need to learn) */
    public const WELL_KNOWN = 99;

    /** @var int[] Valid status values, in canonical display order */
    private const VALID_STATUSES = [
        self::NEW,
        self::LEARNING_2,
        self::LEARNING_3,
        self::LEARNING_4,
        self::LEARNED,
        self::WELL_KNOWN,
        self::IGNORED,
    ];

    /**
     * Language-neutral abbreviations.
     *
     * Learning levels use their digit; 98/99 have no good cross-language
     * abbreviation, so the empty string signals "fall back to the full name".
     *
     * @var array<int, string>
     */
    private const ABBREVIATIONS = [
        self::NEW         => '1',
        self::LEARNING_2  => '2',
        self::LEARNING_3  => '3',
        self::LEARNING_4  => '4',
        self::LEARNED     => '5',
        self::WELL_KNOWN  => '',
        self::IGNORED     => '',
    ];

    /**
     * CSS class names used by the reading view and status charts.
     *
     * @var array<int, string>
     */
    private const CSS_CLASSES = [
        self::NEW         => 'status1',
        self::LEARNING_2  => 'status2',
        self::LEARNING_3  => 'status3',
        self::LEARNING_4  => 'status4',
        self::LEARNED     => 'status5',
        self::WELL_KNOWN  => 'status99',
        self::IGNORED     => 'status98',
    ];

    /**
     * Canonical light-theme colour for each status.
     *
     * These mirror the `--lwt-status*` CSS custom properties (the visual
     * source of truth for the web UI); they are exposed here so machine
     * clients (the mobile app, the REST API) have concrete values.
     *
     * @var array<int, string>
     */
    private const COLOURS = [
        self::NEW         => '#E85A3C',
        self::LEARNING_2  => '#E8893C',
        self::LEARNING_3  => '#E8B83C',
        self::LEARNING_4  => '#E8E23C',
        self::LEARNED     => '#66CC66',
        self::WELL_KNOWN  => '#CCFFCC',
        self::IGNORED     => '#888888',
    ];

    /**
     * @param int $value The status value
     */
    private function __construct(private int $value)
    {
    }

    /**
     * Create from a database value.
     *
     * @param int $status The status value from database
     *
     * @return self
     *
     * @throws InvalidArgumentException If status is invalid
     */
    public static function fromInt(int $status): self
    {
        if (!in_array($status, self::VALID_STATUSES, true)) {
            throw new InvalidArgumentException(
                'Invalid term status: ' . $status . '. Valid values: ' . implode(', ', self::VALID_STATUSES)
            );
        }
        return new self($status);
    }

    /**
     * Check whether an integer is a valid term status.
     *
     * @param int $status The status value to check
     *
     * @return bool
     */
    public static function isValid(int $status): bool
    {
        return in_array($status, self::VALID_STATUSES, true);
    }

    /**
     * All valid status values, in canonical display order
     * (learning 1-5, then well-known, then ignored).
     *
     * @return int[]
     */
    public static function values(): array
    {
        return self::VALID_STATUSES;
    }

    /**
     * Whether a raw status value represents a known term (learned or
     * well-known). Returns false for invalid values rather than throwing,
     * so it is safe to call on unvalidated input.
     *
     * @param int $status The status value
     *
     * @return bool
     */
    public static function isKnownValue(int $status): bool
    {
        return $status === self::LEARNED || $status === self::WELL_KNOWN;
    }

    /**
     * Whether a raw status value represents an ignored term.
     *
     * @param int $status The status value
     *
     * @return bool
     */
    public static function isIgnoredValue(int $status): bool
    {
        return $status === self::IGNORED;
    }

    /**
     * Whether a raw status value is a learning stage (1-5).
     *
     * @param int $status The status value
     *
     * @return bool
     */
    public static function isLearningValue(int $status): bool
    {
        return $status >= self::NEW && $status <= self::LEARNED;
    }

    /**
     * Create a new (unknown) status.
     *
     * @return self
     */
    public static function new(): self
    {
        return new self(self::NEW);
    }

    /**
     * Create a learned status.
     *
     * @return self
     */
    public static function learned(): self
    {
        return new self(self::LEARNED);
    }

    /**
     * Create an ignored status.
     *
     * @return self
     */
    public static function ignored(): self
    {
        return new self(self::IGNORED);
    }

    /**
     * Create a well-known status.
     *
     * @return self
     */
    public static function wellKnown(): self
    {
        return new self(self::WELL_KNOWN);
    }

    /**
     * Advance to the next learning stage.
     *
     * Returns a new TermStatus with the next stage, or the same status
     * if already at maximum learning level or special status.
     *
     * @return self
     */
    public function advance(): self
    {
        if ($this->value >= self::LEARNED || $this->isSpecial()) {
            return $this;
        }
        return new self($this->value + 1);
    }

    /**
     * Decrease to the previous learning stage.
     *
     * Returns a new TermStatus with the previous stage, or the same status
     * if already at minimum level or special status.
     *
     * @return self
     */
    public function decrease(): self
    {
        if ($this->value <= self::NEW || $this->isSpecial()) {
            return $this;
        }
        return new self($this->value - 1);
    }

    /**
     * Check if the term is known (learned or well-known).
     *
     * @return bool
     */
    public function isKnown(): bool
    {
        return $this->value === self::LEARNED || $this->value === self::WELL_KNOWN;
    }

    /**
     * Check if the term is in a learning stage (1-4).
     *
     * @return bool
     */
    public function isLearning(): bool
    {
        return $this->value >= self::NEW && $this->value <= self::LEARNING_4;
    }

    /**
     * Check if this is a special status (ignored or well-known).
     *
     * @return bool
     */
    public function isSpecial(): bool
    {
        return $this->value === self::IGNORED || $this->value === self::WELL_KNOWN;
    }

    /**
     * Check if the term is ignored.
     *
     * @return bool
     */
    public function isIgnored(): bool
    {
        return $this->value === self::IGNORED;
    }

    /**
     * Check if the term needs review (learning stages 1-4).
     *
     * @return bool
     */
    public function needsReview(): bool
    {
        return $this->isLearning();
    }

    /**
     * Get the integer value.
     *
     * @return int
     */
    public function toInt(): int
    {
        return $this->value;
    }

    /**
     * Check equality with another TermStatus.
     *
     * @param TermStatus $other The other status to compare
     *
     * @return bool
     */
    public function equals(TermStatus $other): bool
    {
        return $this->value === $other->value;
    }

    /**
     * Get a human-readable label.
     *
     * @return string
     */
    public function label(): string
    {
        return match ($this->value) {
            self::NEW => 'New',
            self::LEARNING_2 => 'Learning (2)',
            self::LEARNING_3 => 'Learning (3)',
            self::LEARNING_4 => 'Learning (4)',
            self::LEARNED => 'Learned',
            self::IGNORED => 'Ignored',
            self::WELL_KNOWN => 'Well Known',
            default => 'Unknown',
        };
    }

    /**
     * Localized, user-facing status name.
     *
     * Learning stages 1-4 all read "Learning"; 5 is "Learned". Unlike
     * {@see label()} (a fixed English developer label), this is translated
     * and is what the UI and API surface to users.
     *
     * @return string
     */
    public function displayName(): string
    {
        $key = match ($this->value) {
            self::LEARNED    => 'common.status_learned',
            self::WELL_KNOWN => 'common.status_well_known',
            self::IGNORED    => 'common.status_ignored',
            default          => 'common.status_learning',
        };
        return self::translate($key);
    }

    /**
     * Language-neutral abbreviation ('1'..'5'); empty for 98/99, where
     * display code should fall back to {@see displayName()}.
     *
     * @return string
     */
    public function abbreviation(): string
    {
        return self::ABBREVIATIONS[$this->value];
    }

    /**
     * CSS class used by the reading view and status charts (e.g. 'status1').
     *
     * @return string
     */
    public function cssClass(): string
    {
        return self::CSS_CLASSES[$this->value];
    }

    /**
     * Canonical light-theme colour hex (e.g. '#E85A3C'). Mirrors the
     * `--lwt-status*` CSS variables for machine clients.
     *
     * @return string
     */
    public function colourHex(): string
    {
        return self::COLOURS[$this->value];
    }

    /**
     * Position in the canonical display order (1-based).
     *
     * @return int
     */
    public function order(): int
    {
        return (int) array_search($this->value, self::VALID_STATUSES, true) + 1;
    }

    /**
     * Full machine-readable definition of every status, in display order.
     *
     * This is the single source of truth shared by the PHP UI, the REST API
     * (`GET /api/v1/settings/status-definitions`) and — via that endpoint —
     * the frontend status store.
     *
     * @return list<array{
     *     value: int, name: string, abbr: string, cssClass: string,
     *     colour: string, order: int, isKnown: bool, isLearning: bool,
     *     isIgnored: bool
     * }>
     */
    public static function definitions(): array
    {
        $definitions = [];
        foreach (self::VALID_STATUSES as $value) {
            $status = new self($value);
            $definitions[] = [
                'value'      => $value,
                'name'       => $status->displayName(),
                'abbr'       => $status->abbreviation(),
                'cssClass'   => $status->cssClass(),
                'colour'     => $status->colourHex(),
                'order'      => $status->order(),
                'isKnown'    => $status->isKnown(),
                'isLearning' => $status->isLearning(),
                'isIgnored'  => $status->isIgnored(),
            ];
        }
        return $definitions;
    }

    /**
     * Resolve a translation key, falling back to a sensible English label
     * when the translator is unavailable (e.g. during a Container reset).
     */
    private static function translate(string $key): string
    {
        $value = __($key);
        if ($value !== $key) {
            return $value;
        }
        return match ($key) {
            'common.status_learned'    => 'Learned',
            'common.status_well_known' => 'Well Known',
            'common.status_ignored'    => 'Ignored',
            default                    => 'Learning',
        };
    }

    /**
     * String representation.
     *
     * @return string
     */
    public function __toString(): string
    {
        return (string) $this->value;
    }
}
