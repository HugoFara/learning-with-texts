<?php

/**
 * Review Word Entity
 *
 * Represents a word being reviewed with its context.
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

use Lwt\Modules\Vocabulary\Domain\ValueObject\TermStatus;

/**
 * Entity representing a word being reviewed.
 *
 * Contains all word data needed for the test interface including
 * the word itself, translation, sentence context, and scoring info.
 *
 * @since 3.0.0
 */
final class ReviewWord
{
    /**
     * Constructor.
     *
     * @param int         $id            Word ID
     * @param string      $text          Word text
     * @param string      $textLowercase Lowercase word text
     * @param string      $translation   Translation
     * @param string|null $romanization  Romanization (optional)
     * @param string|null $sentence      Sentence context (optional)
     * @param int         $languageId    Language ID
     * @param int         $status        Current status (1-5, 98, 99)
     * @param int         $score         Today's score
     * @param int         $daysOld       Days since status changed
     */
    public function __construct(
        public readonly int $id,
        public readonly string $text,
        public readonly string $textLowercase,
        public readonly string $translation,
        public readonly ?string $romanization,
        public readonly ?string $sentence,
        public readonly int $languageId,
        public readonly int $status,
        public readonly int $score,
        public readonly int $daysOld
    ) {
    }

    /**
     * Create from database record.
     *
     * @param array<string, mixed> $record Database record
     *
     * @return self
     */
    public static function fromRecord(array $record): self
    {
        /** @var mixed $romanization */
        $romanization = $record['WoRomanization'] ?? null;
        /** @var mixed $sentence */
        $sentence = $record['WoSentence'] ?? null;

        return new self(
            (int) $record['WoID'],
            (string) $record['WoText'],
            (string) $record['WoTextLC'],
            (string) $record['WoTranslation'],
            is_string($romanization) ? $romanization : null,
            is_string($sentence) ? $sentence : null,
            (int) $record['WoLgID'],
            (int) $record['WoStatus'],
            (int) ($record['Score'] ?? 0),
            (int) ($record['Days'] ?? 0)
        );
    }

    /**
     * Check if word has a sentence context.
     *
     * @psalm-assert-if-true non-empty-string $this->sentence
     *
     * @return bool
     */
    public function hasSentence(): bool
    {
        return $this->sentence !== null && $this->sentence !== '';
    }

    /**
     * Check if the stored sentence needs updating.
     *
     * A sentence needs updating if it doesn't contain the word
     * marked with curly braces.
     *
     * @return bool True if sentence is invalid/missing
     */
    public function needsNewSentence(): bool
    {
        if (!$this->hasSentence()) {
            return true;
        }
        return strpos($this->sentence, '{' . $this->text . '}') === false;
    }

    /**
     * Get sentence for display (with word marked).
     *
     * If no sentence exists, returns the word wrapped in braces.
     *
     * @return string Sentence with word marked
     */
    public function getSentenceForDisplay(): string
    {
        if ($this->hasSentence()) {
            return $this->sentence;
        }
        return '{' . $this->text . '}';
    }

    /**
     * Check if word is in learning status (1-5).
     *
     * @return bool
     */
    public function isLearning(): bool
    {
        return TermStatus::isLearningValue($this->status);
    }

    /**
     * Check if word is well-known (status 99).
     *
     * @return bool
     */
    public function isWellKnown(): bool
    {
        return $this->status === TermStatus::WELL_KNOWN;
    }

    /**
     * Check if word is ignored (status 98).
     *
     * @return bool
     */
    public function isIgnored(): bool
    {
        return TermStatus::isIgnoredValue($this->status);
    }

    /**
     * Convert to array for JSON response.
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'text' => $this->text,
            'textLowercase' => $this->textLowercase,
            'translation' => $this->translation,
            'romanization' => $this->romanization,
            'sentence' => $this->sentence,
            'languageId' => $this->languageId,
            'status' => $this->status,
            'score' => $this->score,
            'daysOld' => $this->daysOld
        ];
    }
}
