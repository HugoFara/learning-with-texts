<?php

/**
 * Get Text For Edit Use Case
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Text\Application\UseCases
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Text\Application\UseCases;

use Lwt\Shared\Infrastructure\Database\Connection;
use Lwt\Shared\Infrastructure\Database\UserScopedQuery;
use Lwt\Modules\Text\Domain\Text;
use Lwt\Modules\Text\Domain\TextRepositoryInterface;

/**
 * Use case for retrieving text data for editing.
 *
 * Gets text details for edit forms for both active and archived texts.
 *
 * @since 3.0.0
 */
class GetTextForEdit
{
    private TextRepositoryInterface $textRepository;

    /**
     * Constructor.
     *
     * @param TextRepositoryInterface $textRepository Text repository
     */
    public function __construct(TextRepositoryInterface $textRepository)
    {
        $this->textRepository = $textRepository;
    }

    /**
     * Get active text by ID.
     *
     * @param int $textId Text ID
     *
     * @return array|null Text data or null if not found
     */
    public function getTextById(int $textId): ?array
    {
        $text = $this->textRepository->find($textId);
        if ($text === null) {
            return null;
        }

        return $this->textEntityToArray($text);
    }

    /**
     * Get archived text by ID.
     *
     * @param int $textId Archived text ID
     *
     * @return array|null Archived text data or null if not found
     */
    public function getArchivedTextById(int $textId): ?array
    {
        $bindings = [$textId];
        return Connection::preparedFetchOne(
            "SELECT TxLgID, TxTitle, TxText, TxAudioURI, TxSourceURI,
            LENGTH(TxAnnotatedText) AS annotlen
            FROM texts
            WHERE TxID = ? AND TxArchivedAt IS NOT NULL"
            . UserScopedQuery::forTablePrepared('texts', $bindings),
            $bindings
        );
    }

    /**
     * Get text for edit form.
     *
     * @param int $textId Text ID
     *
     * @return array|null Text data for edit form or null if not found
     */
    public function getTextForEdit(int $textId): ?array
    {
        $bindings = [$textId];
        return Connection::preparedFetchOne(
            "SELECT TxID, TxLgID, TxTitle, TxText, TxAudioURI, TxSourceURI,
                LENGTH(TxAnnotatedText) AS annot_exists
            FROM texts
            WHERE TxID = ?"
            . UserScopedQuery::forTablePrepared('texts', $bindings),
            $bindings
        );
    }

    /**
     * Get language data for form dropdowns (translate URIs).
     *
     * @return array<int, string> Map of language ID to Google Translate URI
     */
    public function getLanguageDataForForm(): array
    {
        $bindings = [];
        $rows = Connection::preparedFetchAll(
            "SELECT LgID, LgGoogleTranslateURI FROM languages WHERE 1=1"
            . UserScopedQuery::forTablePrepared('languages', $bindings),
            $bindings
        );

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row['LgID']] = (string) $row['LgGoogleTranslateURI'];
        }
        return $result;
    }

    /**
     * Get texts formatted for select dropdown.
     *
     * @param int $languageId    Language ID (0 for all)
     * @param int $maxNameLength Maximum title length before truncation
     *
     * @return array<int, array{id: int, title: string, language_id: int}>
     */
    public function getTextsForSelect(int $languageId = 0, int $maxNameLength = 30): array
    {
        return $this->textRepository->getForSelect($languageId, $maxNameLength);
    }

    /**
     * Convert Text entity to array for backward compatibility.
     *
     * @param Text $text Text entity
     *
     * @return array Text data as associative array
     */
    private function textEntityToArray(Text $text): array
    {
        return [
            'TxID' => $text->id()->toInt(),
            'TxLgID' => $text->languageId()->toInt(),
            'TxTitle' => $text->title(),
            'TxText' => $text->text(),
            'TxAudioURI' => $text->mediaUri(),
            'TxSourceURI' => $text->sourceUri(),
            'annot_exists' => $text->isAnnotated() ? 1 : 0,
        ];
    }
}
