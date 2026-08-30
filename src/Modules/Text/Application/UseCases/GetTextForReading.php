<?php

/**
 * Get Text For Reading Use Case
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
use Lwt\Shared\Infrastructure\Database\Settings;
use Lwt\Shared\Infrastructure\Database\UserScopedQuery;
use Lwt\Modules\Text\Domain\TextRepositoryInterface;

/**
 * Use case for retrieving text data for reading interface.
 *
 * Prepares all necessary data for the text reading view including
 * text content, language settings, TTS configuration, and navigation.
 *
 * @since 3.0.0
 */
class GetTextForReading
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
     * Get text data for reading interface.
     *
     * @param int $textId Text ID
     *
     * @return array|null Text data for reading or null if not found
     */
    public function execute(int $textId): ?array
    {
        $bindings = [$textId];
        return Connection::preparedFetchOne(
            "SELECT LgName, TxLgID, TxText, TxTitle, TxAudioURI, TxSourceURI, TxAudioPosition
            FROM texts
            JOIN languages ON TxLgID = LgID
            WHERE TxID = ?"
            . UserScopedQuery::forTablePrepared('texts', $bindings)
            . UserScopedQuery::forTablePrepared('languages', $bindings),
            $bindings
        );
    }

    /**
     * Get language settings for reading display.
     *
     * @param int $languageId Language ID
     *
     * @return array|null Language settings or null if not found
     */
    public function getLanguageSettingsForReading(int $languageId): ?array
    {
        $bindings = [$languageId];
        return Connection::preparedFetchOne(
            "SELECT LgName, LgDict1URI, LgDict2URI, LgGoogleTranslateURI,
                LgTextSize, LgRegexpWordCharacters, LgRemoveSpaces, LgRightToLeft
            FROM languages
            WHERE LgID = ?"
            . UserScopedQuery::forTablePrepared('languages', $bindings),
            $bindings
        );
    }

    /**
     * Get TTS voice API for a language.
     *
     * @param int $languageId Language ID
     *
     * @return string TTS voice API string or empty
     */
    public function getTtsVoiceApi(int $languageId): string
    {
        $bindings = [$languageId];
        /**
 * @var string|null $result
*/
        $result = Connection::preparedFetchValue(
            "SELECT LgTTSVoiceAPI FROM languages WHERE LgID = ?"
            . UserScopedQuery::forTablePrepared('languages', $bindings),
            $bindings,
            'LgTTSVoiceAPI'
        );
        return $result ?? '';
    }

    /**
     * Get language ID by language name.
     *
     * @param string $languageName Language name
     *
     * @return int|null Language ID or null if not found
     */
    public function getLanguageIdByName(string $languageName): ?int
    {
        $bindings = [$languageName];
        /**
 * @var int|string|null $result
*/
        $result = Connection::preparedFetchValue(
            "SELECT LgID FROM languages WHERE LgName = ?"
            . UserScopedQuery::forTablePrepared('languages', $bindings),
            $bindings,
            'LgID'
        );
        return $result !== null ? (int) $result : null;
    }

    /**
     * Get previous and next text IDs for navigation.
     *
     * @param int $textId     Current text ID
     * @param int $languageId Language ID
     *
     * @return array{previous: int|null, next: int|null}
     */
    public function getNavigation(int $textId, int $languageId): array
    {
        return [
            'previous' => $this->textRepository->getPreviousTextId($textId, $languageId),
            'next' => $this->textRepository->getNextTextId($textId, $languageId),
        ];
    }

    /**
     * Get Google Translate URIs for languages.
     *
     * @return array<int, string> Map of language ID to translate URI
     */
    public function getLanguageTranslateUris(): array
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
}
