<?php

/**
 * Get Term By ID Use Case
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

/**
 * Use case for retrieving a term by its ID.
 *
 * @since 3.0.0
 */
class GetTermById
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
     * Execute the get term use case.
     *
     * @param int $termId Term ID
     *
     * @return Term|null The term entity or null if not found
     */
    public function execute(int $termId): ?Term
    {
        if ($termId <= 0) {
            return null;
        }

        return $this->repository->find($termId);
    }

    /**
     * Execute and return array format (backward compatible).
     *
     * @param int $termId Term ID
     *
     * @return array|null Term data array or null if not found
     */
    public function executeAsArray(int $termId): ?array
    {
        $term = $this->execute($termId);

        if ($term === null) {
            return null;
        }

        return [
            'WoID' => $term->id()->toInt(),
            'WoLgID' => $term->languageId()->toInt(),
            'WoText' => $term->text(),
            'WoTextLC' => $term->textLowercase(),
            'WoStatus' => $term->status()->toInt(),
            'WoTranslation' => $term->translation(),
            'WoSentence' => $term->sentence(),
            'WoNotes' => $term->notes(),
            'WoRomanization' => $term->romanization(),
            'WoWordCount' => $term->wordCount(),
            'WoCreated' => $term->createdAt()->format('Y-m-d H:i:s'),
            'WoStatusChanged' => $term->statusChangedAt()->format('Y-m-d H:i:s'),
        ];
    }
}
