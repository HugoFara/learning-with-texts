<?php

/**
 * Create Term Use Case
 *
 * PHP version 8.1
 *
 * @category Lwt
 * @package  Lwt\Modules\Vocabulary\Application\UseCases
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Vocabulary\Application\UseCases;

use DateTimeImmutable;
use Lwt\Shared\Infrastructure\Database\Escaping;
use Lwt\Modules\Vocabulary\Domain\Term;
use Lwt\Modules\Vocabulary\Domain\TermRepositoryInterface;

/**
 * Use case for creating a new term/word.
 *
 * @since 3.0.0
 */
class CreateTerm
{
    private TermRepositoryInterface $repository;

    /**
     * Constructor.
     *
     * @param TermRepositoryInterface $repository Term repository
     */
    public function __construct(TermRepositoryInterface $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Execute the create term use case.
     *
     * @param int    $languageId    Language ID
     * @param string $text          Term text
     * @param int    $status        Learning status (1-5, 98, 99)
     * @param string $translation   Translation text
     * @param string $sentence      Example sentence
     * @param string $notes         Personal notes
     * @param string $romanization  Romanization/phonetic
     * @param int    $wordCount     Word count (for multi-word expressions)
     * @param string|null $lemma    Lemma (base form of the word)
     *
     * @return Term The created term entity
     *
     * @throws \InvalidArgumentException If validation fails
     */
    public function execute(
        int $languageId,
        string $text,
        int $status = 1,
        string $translation = '',
        string $sentence = '',
        string $notes = '',
        string $romanization = '',
        int $wordCount = 0,
        ?string $lemma = null
    ): Term {
        // Validate and clean text
        $cleanText = trim(Escaping::prepareTextdata($text));
        if ($cleanText === '') {
            throw new \InvalidArgumentException('Term text cannot be empty');
        }

        $textLc = mb_strtolower($cleanText, 'UTF-8');

        // Check for duplicate
        if ($this->repository->termExists($languageId, $textLc)) {
            throw new \InvalidArgumentException(
                'Term "' . $textLc . '" already exists in this language'
            );
        }

        // Normalize translation
        $normalizedTranslation = $this->normalizeTranslation($translation);

        // Clean sentence and notes
        $cleanSentence = $this->replaceTabNewline($sentence);
        $cleanNotes = $this->replaceTabNewline($notes);

        // Calculate word count if not provided
        if ($wordCount <= 0) {
            $wordCount = $this->calculateWordCount($cleanText);
        }

        // Process lemma
        $lemmaLc = null;
        if ($lemma !== null && $lemma !== '') {
            $lemma = trim($lemma);
            $lemmaLc = mb_strtolower($lemma, 'UTF-8');
        } else {
            $lemma = null;
        }

        // Create the term entity using reconstitute with ID 0 (new)
        $now = new DateTimeImmutable();
        $term = Term::reconstitute(
            0, // New term - will be assigned by repository
            $languageId,
            $cleanText,
            $textLc,
            $lemma,
            $lemmaLc,
            $status,
            $normalizedTranslation,
            $cleanSentence,
            $cleanNotes,
            $romanization,
            $wordCount,
            $now,
            $now
        );

        // Persist
        $this->repository->save($term);

        return $term;
    }

    /**
     * Execute and return result array (backward compatible with WordService).
     *
     * @param array $data Word data array with keys like WoLgID, WoText, etc.
     *
     * @return array{id: int, message: string, success: bool, textlc: string, text: string}
     */
    public function executeFromArray(array $data): array
    {
        try {
            $term = $this->execute(
                (int) ($data['WoLgID'] ?? 0),
                (string) ($data['WoText'] ?? ''),
                (int) ($data['WoStatus'] ?? 1),
                (string) ($data['WoTranslation'] ?? ''),
                (string) ($data['WoSentence'] ?? ''),
                (string) ($data['WoNotes'] ?? ''),
                (string) ($data['WoRomanization'] ?? ''),
                (int) ($data['WoWordCount'] ?? 1)
            );

            return [
                'id' => $term->id()->toInt(),
                'message' => __('vocabulary.flash.term_saved'),
                'success' => true,
                'textlc' => $term->textLowercase(),
                'text' => $term->text()
            ];
        } catch (\InvalidArgumentException $e) {
            return [
                'id' => 0,
                'message' => __('vocabulary.flash.error_prefix', ['message' => $e->getMessage()]),
                'success' => false,
                'textlc' => '',
                'text' => ''
            ];
        } catch (\Exception $e) {
            $message = $e->getMessage();
            if (strpos($message, 'Duplicate entry') !== false) {
                $message = 'Duplicate entry';
            }
            return [
                'id' => 0,
                'message' => __('vocabulary.flash.error_prefix', ['message' => $message]),
                'success' => false,
                'textlc' => '',
                'text' => ''
            ];
        }
    }

    /**
     * Normalize translation text.
     *
     * @param string $translation Raw translation
     *
     * @return string Normalized translation
     */
    private function normalizeTranslation(string $translation): string
    {
        $trans = trim($translation);
        if ($trans === '' || $trans === '*') {
            return '*';
        }
        return $trans;
    }

    /**
     * Replace tabs and newlines in text.
     *
     * @param string $text Input text
     *
     * @return string Cleaned text
     */
    private function replaceTabNewline(string $text): string
    {
        return str_replace(["\t", "\r\n", "\n", "\r"], ' ', $text);
    }

    /**
     * Calculate word count for a text.
     *
     * @param string $text The text to count words in
     *
     * @return int Word count (minimum 1)
     */
    private function calculateWordCount(string $text): int
    {
        $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
        if ($words === false) {
            return 1;
        }
        return max(1, count($words));
    }
}
