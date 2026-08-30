<?php

/**
 * Update Term Use Case
 *
 * PHP version 8.2
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

use Lwt\Modules\Vocabulary\Domain\Term;
use Lwt\Modules\Vocabulary\Domain\TermRepositoryInterface;
use Lwt\Modules\Vocabulary\Domain\ValueObject\TermStatus;

/**
 * Use case for updating an existing term.
 *
 * @since 3.0.0
 */
class UpdateTerm
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
     * Execute the update term use case.
     *
     * @param int         $termId       Term ID to update
     * @param int|null    $status       New status (null to keep current)
     * @param string|null $translation  New translation (null to keep current)
     * @param string|null $sentence     New sentence (null to keep current)
     * @param string|null $notes        New notes (null to keep current)
     * @param string|null $romanization New romanization (null to keep current)
     *
     * @return Term The updated term entity
     *
     * @throws \InvalidArgumentException If term not found
     */
    public function execute(
        int $termId,
        ?int $status = null,
        ?string $translation = null,
        ?string $sentence = null,
        ?string $notes = null,
        ?string $romanization = null
    ): Term {
        $term = $this->repository->find($termId);

        if ($term === null) {
            throw new \InvalidArgumentException('Term not found: ' . $termId);
        }

        // Update fields if provided
        if ($status !== null) {
            $term->setStatus(TermStatus::fromInt($status));
        }

        if ($translation !== null) {
            $term->updateTranslation($this->normalizeTranslation($translation));
        }

        if ($sentence !== null) {
            $term->updateSentence($this->replaceTabNewline($sentence));
        }

        if ($notes !== null) {
            $term->updateNotes($this->replaceTabNewline($notes));
        }

        if ($romanization !== null) {
            $term->updateRomanization($romanization);
        }

        // Persist changes
        $this->repository->save($term);

        return $term;
    }

    /**
     * Execute and return result array (backward compatible with WordService).
     *
     * @param array $data Update data array with keys like WoID, WoStatus, etc.
     *
     * @return array{id: int, message: string, success: bool}
     */
    public function executeFromArray(array $data): array
    {
        try {
            $termId = (int) ($data['WoID'] ?? 0);

            $term = $this->execute(
                $termId,
                isset($data['WoStatus']) ? (int) $data['WoStatus'] : null,
                isset($data['WoTranslation']) ? (string)$data['WoTranslation'] : null,
                isset($data['WoSentence']) ? (string)$data['WoSentence'] : null,
                isset($data['WoNotes']) ? (string)$data['WoNotes'] : null,
                isset($data['WoRomanization']) ? (string)$data['WoRomanization'] : null
            );

            return [
                'id' => $term->id()->toInt(),
                'message' => __('vocabulary.flash.term_updated'),
                'success' => true
            ];
        } catch (\InvalidArgumentException $e) {
            return [
                'id' => 0,
                'message' => __('vocabulary.flash.error_prefix', ['message' => $e->getMessage()]),
                'success' => false
            ];
        } catch (\Exception $e) {
            return [
                'id' => 0,
                'message' => __('vocabulary.flash.error_prefix', ['message' => $e->getMessage()]),
                'success' => false
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
}
