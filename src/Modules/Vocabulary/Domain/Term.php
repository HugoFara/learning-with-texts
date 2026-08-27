<?php

/**
 * Term Entity
 *
 * PHP version 8.1
 *
 * @category Lwt
 * @package  Lwt\Modules\Vocabulary\Domain
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    2.7.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Vocabulary\Domain;

use DateTimeImmutable;
use InvalidArgumentException;
use Lwt\Modules\Language\Domain\ValueObject\LanguageId;
use Lwt\Modules\Vocabulary\Domain\ValueObject\TermId;
use Lwt\Modules\Vocabulary\Domain\ValueObject\TermStatus;

/**
 * A term (word or multi-word) represented as a rich domain object.
 *
 * Terms are vocabulary items that users learn. They have a status indicating
 * learning progress (1-5), or special statuses (98=ignored, 99=well-known).
 *
 * This class enforces domain invariants and encapsulates business logic.
 *
 * @category Lwt
 * @package  Lwt\Modules\Vocabulary\Domain
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */
class Term
{
    private TermId $id;
    private LanguageId $languageId;
    private string $text;
    private string $textLowercase;
    private ?string $lemma = null;
    private ?string $lemmaLc = null;
    private TermStatus $status;
    private string $translation;
    private string $sentence;
    private string $notes;
    private string $romanization;
    private int $wordCount;
    private DateTimeImmutable $createdAt;
    private DateTimeImmutable $statusChangedAt;

    /**
     * Private constructor - use factory methods instead.
     */
    private function __construct(
        TermId $id,
        LanguageId $languageId,
        string $text,
        string $textLowercase,
        ?string $lemma,
        ?string $lemmaLc,
        TermStatus $status,
        string $translation,
        string $sentence,
        string $notes,
        string $romanization,
        int $wordCount,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $statusChangedAt
    ) {
        $this->id = $id;
        $this->languageId = $languageId;
        $this->text = $text;
        $this->textLowercase = $textLowercase;
        $this->lemma = $lemma;
        $this->lemmaLc = $lemmaLc;
        $this->status = $status;
        $this->translation = $translation;
        $this->sentence = $sentence;
        $this->notes = $notes;
        $this->romanization = $romanization;
        $this->wordCount = $wordCount;
        $this->createdAt = $createdAt;
        $this->statusChangedAt = $statusChangedAt;
    }

    /**
     * Create a new term.
     *
     * @param LanguageId $languageId  The language this term belongs to
     * @param string     $text        The term text
     * @param string     $translation Optional initial translation
     *
     * @return self
     *
     * @throws InvalidArgumentException If text is empty
     */
    public static function create(
        LanguageId $languageId,
        string $text,
        string $translation = ''
    ): self {
        $trimmedText = trim($text);
        if ($trimmedText === '') {
            throw new InvalidArgumentException('Term text cannot be empty');
        }

        $wordCount = self::calculateWordCount($trimmedText);

        return new self(
            TermId::new(),
            $languageId,
            $trimmedText,
            mb_strtolower($trimmedText, 'UTF-8'),
            null, // lemma
            null, // lemmaLc
            TermStatus::new(),
            $translation,
            '',
            '',
            '',
            $wordCount,
            new DateTimeImmutable(),
            new DateTimeImmutable()
        );
    }

    /**
     * Reconstitute a term from persistence.
     *
     * This is used by repositories to recreate entities from database records.
     * It bypasses validation since the data is assumed to be valid.
     *
     * @param int               $id              The term ID
     * @param int               $languageId      The language ID
     * @param string            $text            The term text
     * @param string            $textLowercase   The lowercase text
     * @param string|null       $lemma           The lemma (base form)
     * @param string|null       $lemmaLc         The lowercase lemma
     * @param int               $status          The status value
     * @param string            $translation     The translation
     * @param string            $sentence        The example sentence
     * @param string            $notes           Personal notes
     * @param string            $romanization    The romanization
     * @param int               $wordCount       The word count
     * @param DateTimeImmutable $createdAt       When the term was created
     * @param DateTimeImmutable $statusChangedAt When status last changed
     *
     * @return self
     *
     * @internal This method is for repository use only
     */
    public static function reconstitute(
        int $id,
        int $languageId,
        string $text,
        string $textLowercase,
        ?string $lemma,
        ?string $lemmaLc,
        int $status,
        string $translation,
        string $sentence,
        string $notes,
        string $romanization,
        int $wordCount,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $statusChangedAt
    ): self {
        return new self(
            $id === 0 ? TermId::new() : TermId::fromInt($id),
            LanguageId::fromInt($languageId),
            $text,
            $textLowercase,
            $lemma,
            $lemmaLc,
            TermStatus::fromInt($status),
            $translation,
            $sentence,
            $notes,
            $romanization,
            $wordCount,
            $createdAt,
            $statusChangedAt
        );
    }

    /**
     * Advance the term's status to the next learning stage.
     *
     * @return void
     */
    public function advanceStatus(): void
    {
        $newStatus = $this->status->advance();
        if (!$newStatus->equals($this->status)) {
            $this->status = $newStatus;
            $this->statusChangedAt = new DateTimeImmutable();
        }
    }

    /**
     * Decrease the term's status to the previous learning stage.
     *
     * @return void
     */
    public function decreaseStatus(): void
    {
        $newStatus = $this->status->decrease();
        if (!$newStatus->equals($this->status)) {
            $this->status = $newStatus;
            $this->statusChangedAt = new DateTimeImmutable();
        }
    }

    /**
     * Set the term's status to a specific value.
     *
     * @param TermStatus $status The new status
     *
     * @return void
     */
    public function setStatus(TermStatus $status): void
    {
        if (!$status->equals($this->status)) {
            $this->status = $status;
            $this->statusChangedAt = new DateTimeImmutable();
        }
    }

    /**
     * Mark the term as fully learned.
     *
     * @return void
     */
    public function markAsLearned(): void
    {
        $this->setStatus(TermStatus::learned());
    }

    /**
     * Mark the term as ignored.
     *
     * @return void
     */
    public function ignore(): void
    {
        $this->setStatus(TermStatus::ignored());
    }

    /**
     * Mark the term as well-known.
     *
     * @return void
     */
    public function markAsWellKnown(): void
    {
        $this->setStatus(TermStatus::wellKnown());
    }

    /**
     * Update the translation.
     *
     * @param string $translation The new translation
     *
     * @return void
     */
    public function updateTranslation(string $translation): void
    {
        $this->translation = trim($translation);
    }

    /**
     * Update the example sentence.
     *
     * @param string $sentence The new sentence
     *
     * @return void
     */
    public function updateSentence(string $sentence): void
    {
        $this->sentence = trim($sentence);
    }

    /**
     * Update the romanization.
     *
     * @param string $romanization The new romanization
     *
     * @return void
     */
    public function updateRomanization(string $romanization): void
    {
        $this->romanization = trim($romanization);
    }

    /**
     * Update the notes.
     *
     * @param string $notes The new notes
     *
     * @return void
     */
    public function updateNotes(string $notes): void
    {
        $this->notes = trim($notes);
    }

    /**
     * Update the lemma (base form).
     *
     * @param string|null $lemma The new lemma, or null to clear
     *
     * @return void
     */
    public function updateLemma(?string $lemma): void
    {
        if ($lemma === null || $lemma === '') {
            $this->lemma = null;
            $this->lemmaLc = null;
        } else {
            $this->lemma = trim($lemma);
            $this->lemmaLc = mb_strtolower($this->lemma, 'UTF-8');
        }
    }


    /**
     * Check if the term is known (learned or well-known).
     *
     * @return bool
     */
    public function isKnown(): bool
    {
        return $this->status->isKnown();
    }

    /**
     * Check if the term is in a learning stage.
     *
     * @return bool
     */
    public function isLearning(): bool
    {
        return $this->status->isLearning();
    }

    /**
     * Check if the term is ignored.
     *
     * @return bool
     */
    public function isIgnored(): bool
    {
        return $this->status->isIgnored();
    }

    /**
     * Check if the term needs review.
     *
     * @return bool
     */
    public function needsReview(): bool
    {
        return $this->status->needsReview();
    }

    /**
     * Check if this is a multi-word term.
     *
     * @return bool
     */
    public function isMultiWord(): bool
    {
        return $this->wordCount > 1;
    }

    /**
     * Check if the term has a translation.
     *
     * @return bool
     */
    public function hasTranslation(): bool
    {
        return $this->translation !== '' && $this->translation !== '*';
    }

    // Getters

    public function id(): TermId
    {
        return $this->id;
    }

    public function languageId(): LanguageId
    {
        return $this->languageId;
    }

    public function text(): string
    {
        return $this->text;
    }

    public function textLowercase(): string
    {
        return $this->textLowercase;
    }

    public function lemma(): ?string
    {
        return $this->lemma;
    }

    public function lemmaLc(): ?string
    {
        return $this->lemmaLc;
    }

    public function status(): TermStatus
    {
        return $this->status;
    }

    public function translation(): string
    {
        return $this->translation;
    }

    public function sentence(): string
    {
        return $this->sentence;
    }

    public function romanization(): string
    {
        return $this->romanization;
    }

    public function notes(): string
    {
        return $this->notes;
    }

    public function wordCount(): int
    {
        return $this->wordCount;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function statusChangedAt(): DateTimeImmutable
    {
        return $this->statusChangedAt;
    }

    /**
     * Calculate word count for a text.
     *
     * @param string $text The text to count words in
     *
     * @return int
     */
    private static function calculateWordCount(string $text): int
    {
        // Simple word count based on spaces
        // This matches the legacy behavior
        $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        if ($words === false) {
            return 1;
        }
        return count($words);
    }

    /**
     * Internal method to set the ID after persistence.
     *
     * @param TermId $id The new ID
     *
     * @return void
     *
     * @internal This method is for repository use only
     */
    public function setId(TermId $id): void
    {
        if (!$this->id->isNew()) {
            throw new \LogicException('Cannot change ID of a persisted term');
        }
        $this->id = $id;
    }
}
