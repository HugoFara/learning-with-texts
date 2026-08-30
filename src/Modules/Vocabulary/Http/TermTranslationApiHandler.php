<?php

/**
 * Term Translation API Handler
 *
 * Handles API operations for term translations, dictionary lookups, and tags.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Vocabulary\Http
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Vocabulary\Http;

use Lwt\Shared\Infrastructure\Utilities\StringUtils;
use Lwt\Shared\Infrastructure\Database\Connection;
use Lwt\Shared\Infrastructure\Database\QueryBuilder;
use Lwt\Shared\Infrastructure\Database\UserScopedQuery;
use Lwt\Modules\Vocabulary\Application\UseCases\FindSimilarTerms;
use Lwt\Modules\Vocabulary\Application\Services\TermStatusService;
use Lwt\Modules\Vocabulary\Application\Services\WordLinkingService;
use Lwt\Shared\Infrastructure\Dictionary\DictionaryAdapter;
use Lwt\Modules\Tags\Application\TagsFacade;

/**
 * Handler for term translation, dictionary, and tag API operations.
 *
 * Provides endpoints for:
 * - Getting similar terms for autocomplete
 * - Dictionary link generation
 * - Term tag management
 * - Translation creation and updates
 *
 * @since 3.0.0
 */
class TermTranslationApiHandler
{
    private FindSimilarTerms $findSimilarTerms;
    private DictionaryAdapter $dictionaryAdapter;
    private WordLinkingService $linkingService;

    /**
     * Constructor.
     *
     * @param FindSimilarTerms|null   $findSimilarTerms  Find similar terms use case
     * @param DictionaryAdapter|null  $dictionaryAdapter Dictionary adapter
     * @param WordLinkingService|null $linkingService    Occurrence linking service
     */
    public function __construct(
        ?FindSimilarTerms $findSimilarTerms = null,
        ?DictionaryAdapter $dictionaryAdapter = null,
        ?WordLinkingService $linkingService = null
    ) {
        $this->findSimilarTerms = $findSimilarTerms ?? new FindSimilarTerms();
        $this->dictionaryAdapter = $dictionaryAdapter ?? new DictionaryAdapter();
        $this->linkingService = $linkingService ?? new WordLinkingService();
    }

    // =========================================================================
    // Similar Terms
    // =========================================================================

    /**
     * Get similar terms.
     *
     * @param int    $langId Language ID
     * @param string $term   Term text
     *
     * @return array{similar_terms: string}
     */
    public function getSimilarTerms(int $langId, string $term): array
    {
        return [
            'similar_terms' => $this->findSimilarTerms->getFormattedTerms($langId, $term)
        ];
    }

    /**
     * Format response for similar terms.
     *
     * @param int    $langId Language ID
     * @param string $term   Term text
     *
     * @return array
     */
    public function formatSimilarTerms(int $langId, string $term): array
    {
        return $this->getSimilarTerms($langId, $term);
    }

    // =========================================================================
    // Dictionary
    // =========================================================================

    /**
     * Get dictionary links for a term.
     *
     * @param int    $langId Language ID
     * @param string $term   Term text
     *
     * @return array Dictionary URLs
     */
    public function getDictionaryLinks(int $langId, string $term): array
    {
        $dicts = $this->dictionaryAdapter->getLanguageDictionaries($langId);

        return [
            'dict1' => $dicts['dict1'] !== ''
                ? DictionaryAdapter::createDictLink($dicts['dict1'], $term)
                : '',
            'dict2' => $dicts['dict2'] !== ''
                ? DictionaryAdapter::createDictLink($dicts['dict2'], $term)
                : '',
            'translator' => $dicts['translator'] !== ''
                ? DictionaryAdapter::createDictLink($dicts['translator'], $term)
                : '',
        ];
    }

    /**
     * Format response for dictionary links.
     *
     * @param int    $langId Language ID
     * @param string $term   Term text
     *
     * @return array
     */
    public function formatDictionaryLinks(int $langId, string $term): array
    {
        return $this->getDictionaryLinks($langId, $term);
    }

    // =========================================================================
    // Tags
    // =========================================================================

    /**
     * Get tags for a term.
     *
     * @param int $termId Term ID
     *
     * @return array{tags: string[]}
     */
    public function getTermTags(int $termId): array
    {
        $tagsResult = QueryBuilder::table('word_tag_map')
            ->select(['tags.TgText'])
            ->join('tags', 'tags.TgID', '=', 'word_tag_map.WtTgID')
            ->where('word_tag_map.WtWoID', '=', $termId)
            ->orderBy('tags.TgText')
            ->getPrepared();

        $tags = array_map(fn($row) => (string)$row['TgText'], $tagsResult);
        return ['tags' => $tags];
    }

    /**
     * Set tags for a term.
     *
     * @param int      $termId Term ID
     * @param string[] $tags   Tag names
     *
     * @return array{success: bool}
     */
    public function setTermTags(int $termId, array $tags): array
    {
        TagsFacade::saveWordTagsFromArray($termId, $tags);
        return ['success' => true];
    }

    // =========================================================================
    // Translation Management
    // =========================================================================

    /**
     * Add the translation for a new term.
     *
     * @param string $text Associated text
     * @param int    $lang Language ID
     * @param string $data Translation
     *
     * @return array{success: bool, wordId?: int, textLc?: string, error?: string, affected?: int}
     *         Result with wordId and textLc on success, or error details on failure
     */
    public function addNewTermTranslation(string $text, int $lang, string $data): array
    {
        $textlc = mb_strtolower($text, 'UTF-8');

        // Insert new word using prepared statement

        // Use raw SQL for complex INSERT with dynamic columns.
        // INSERTs can't use forTablePrepared; inject WoUsID into the
        // column/value list via getUserIdForInsert instead.
        $bindings = [$lang, $textlc, $text, $data, '', ''];
        $userScopeColumn = '';
        $userScopeValue = '';
        $userIdForInsert = UserScopedQuery::getUserIdForInsert('words');
        if ($userIdForInsert !== null) {
            $userScopeColumn = ', WoUsID';
            $userScopeValue = ', ?';
            $bindings[] = $userIdForInsert;
        }
        $sql = "INSERT INTO words (
                WoLgID, WoTextLC, WoText, WoStatus, WoTranslation,
                WoSentence, WoRomanization, WoStatusChanged{$userScopeColumn}
            ) VALUES(?, ?, ?, 1, ?, ?, ?, NOW(){$userScopeValue})";

        $stmt = Connection::prepare($sql);
        $stmt->bindValues($bindings);
        $affected = $stmt->execute();

        if ($affected != 1) {
            return ['success' => false, 'error' => 'unexpected_affected_rows', 'affected' => $affected];
        }

        $wid = $stmt->insertId();

        // Delegate rather than repeat the UPDATE: $lang arrives straight from
        // the request (POST /terms/new), and word_occurrences has no owner
        // column, so an unguarded statement here re-points another user's
        // occurrences at this word. linkToTextItems() holds the ownership
        // check; this used to be a copy of it without one.
        $this->linkingService->linkToTextItems((int) $wid, $lang, $textlc);

        return ['success' => true, 'wordId' => (int) $wid, 'textLc' => $textlc];
    }

    /**
     * Edit the translation for an existing term.
     *
     * @param int    $wid      Word ID
     * @param string $newTrans New translation
     *
     * @return string WoTextLC, lowercase version of the word
     */
    public function editTermTranslation(int $wid, string $newTrans): string
    {
        $oldtrans = (string) QueryBuilder::table('words')
            ->select(['WoTranslation'])
            ->where('WoID', '=', $wid)
            ->valuePrepared('WoTranslation');

        $oldtransarr = preg_split('/[' . StringUtils::getSeparators() . ']/u', $oldtrans);
        if ($oldtransarr === false) {
            return (string) QueryBuilder::table('words')
                ->select(['WoTextLC'])
                ->where('WoID', '=', $wid)
                ->valuePrepared('WoTextLC');
        }
        $oldtransarr = array_map('trim', $oldtransarr);

        if (!in_array($newTrans, $oldtransarr)) {
            if (trim($oldtrans) == '' || trim($oldtrans) == '*') {
                $oldtrans = $newTrans;
            } else {
                $oldtrans .= ' ' . StringUtils::getFirstSeparator() . ' ' . $newTrans;
            }
            QueryBuilder::table('words')
                ->where('WoID', '=', $wid)
                ->updatePrepared(['WoTranslation' => $oldtrans]);
        }

        return (string) QueryBuilder::table('words')
            ->select(['WoTextLC'])
            ->where('WoID', '=', $wid)
            ->valuePrepared('WoTextLC');
    }

    /**
     * Edit term translation if it exists.
     *
     * @param int    $wid      Word ID
     * @param string $newTrans New translation
     *
     * @return array{success: bool, textLc?: string, error?: string, count?: int}
     *         Result with textLc on success, or error details on failure
     */
    public function checkUpdateTranslation(int $wid, string $newTrans): array
    {
        $cntWords = QueryBuilder::table('words')
            ->where('WoID', '=', $wid)
            ->countPrepared();

        if ($cntWords == 1) {
            $textLc = $this->editTermTranslation($wid, $newTrans);
            return ['success' => true, 'textLc' => $textLc];
        }
        return ['success' => false, 'error' => 'word_not_found', 'count' => $cntWords];
    }

    /**
     * Format response for updating translation.
     *
     * @param int    $termId      Term ID
     * @param string $translation New translation
     *
     * @return array{update?: string, error?: string}
     */
    public function formatUpdateTranslation(int $termId, string $translation): array
    {
        $result = $this->checkUpdateTranslation($termId, trim($translation));
        if (!$result['success']) {
            $errorMsg = match ($result['error'] ?? '') {
                'word_not_found' => "Error: " . ($result['count'] ?? '?') . " word ID found!",
                default => 'Unknown error'
            };
            return ["error" => $errorMsg];
        }
        return ["update" => $result['textLc'] ?? ''];
    }

    /**
     * Format response for adding translation.
     *
     * @param string $termText    Term text
     * @param int    $lgId        Language ID
     * @param string $translation Translation
     *
     * @return array{error?: string, add?: string, term_id?: int|string, term_lc?: string}
     */
    public function formatAddTranslation(string $termText, int $lgId, string $translation): array
    {
        $text = trim($termText);
        $result = $this->addNewTermTranslation($text, $lgId, trim($translation));

        if ($result['success']) {
            return [
                "term_id" => $result['wordId'] ?? 0,
                "term_lc" => $result['textLc'] ?? ''
            ];
        }

        $errorMsg = match ($result['error'] ?? '') {
            'unexpected_affected_rows' => "Error: " . ($result['affected'] ?? '?') . " rows affected, expected 1!",
            default => 'Unknown error'
        };
        return ["error" => $errorMsg];
    }
}
