<?php

/**
 * Feed Article API Handler
 *
 * Handles article-related API operations: listing, deleting, importing,
 * and resetting error articles.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Feed\Http
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Feed\Http;

use Lwt\Shared\Infrastructure\Database\Connection;
use Lwt\Shared\Infrastructure\Database\QueryBuilder;
use Lwt\Shared\Infrastructure\Database\Settings;
use Lwt\Modules\Feed\Application\FeedFacade;

/**
 * Sub-handler for feed article API operations.
 *
 * @since 3.0.0
 */
class FeedArticleApiHandler
{
    private FeedFacade $feedFacade;

    public function __construct(FeedFacade $feedFacade)
    {
        $this->feedFacade = $feedFacade;
    }

    /**
     * Get articles for a feed.
     *
     * @param array $params Parameters:
     *                      - feed_id: int (required)
     *                      - query: string (search)
     *                      - page: int
     *                      - per_page: int
     *                      - sort: int (1=date desc, 2=date asc, 3=title)
     *
     * @return array{articles?: array, pagination?: array, feed?: array, error?: string}
     */
    public function getArticles(array $params): array
    {
        $feedId = (int)($params['feed_id'] ?? 0);
        if ($feedId <= 0) {
            return ['error' => 'Feed ID is required'];
        }

        $page = max(1, (int)($params['page'] ?? 1));
        $perPage = max(1, min(100, (int)($params['per_page'] ?? 50)));
        $query = (string)($params['query'] ?? '');
        $sort = max(1, min(3, (int)($params['sort'] ?? 1)));

        // Get feed info
        $feed = $this->feedFacade->getFeedById($feedId);
        if ($feed === null) {
            return ['error' => 'Feed not found'];
        }

        // Build WHERE clause with parameters
        $whereConditions = ["FlNfID = ?"];
        $queryParams = [$feedId];

        if (is_string($query) && $query !== '') {
            $pattern = '%' . str_replace('*', '%', $query) . '%';
            $whereConditions[] = "(FlTitle LIKE ? OR FlDescription LIKE ?)";
            $queryParams[] = $pattern;
            $queryParams[] = $pattern;
        }

        $where = implode(' AND ', $whereConditions);

        // Count total using raw SQL with fixed table name
        $total = (int)Connection::preparedFetchValue(
            "SELECT COUNT(*) AS cnt FROM feed_links WHERE $where",
            $queryParams,
            'cnt'
        );

        // Calculate pagination
        $totalPages = (int)ceil($total / $perPage);
        if ($page > $totalPages && $totalPages > 0) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $perPage;

        // Sort order
        $sorts = ['FlDate DESC', 'FlDate ASC', 'FlTitle ASC'];
        $orderBy = $sorts[$sort - 1] ?? 'FlDate DESC';

        // Get articles with import status (archived texts are in texts table with TxArchivedAt)
        $sql = "SELECT fl.*, tx.TxID, tx.TxArchivedAt
                FROM feed_links fl
                LEFT JOIN texts tx ON tx.TxSourceURI = TRIM(fl.FlLink)
                WHERE $where
                ORDER BY $orderBy
                LIMIT ?, ?";

        // Add pagination parameters
        $queryParams[] = $offset;
        $queryParams[] = $perPage;

        $articles = [];
        $rows = Connection::preparedFetchAll($sql, $queryParams);
        foreach ($rows as $row) {
            $articles[] = $this->formatArticleRecord($row);
        }

        return [
            'articles' => $articles,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $totalPages
            ],
            'feed' => [
                'id' => (int)$feed['NfID'],
                'name' => $feed['NfName'],
                'langId' => $feed['NfLgID']
            ]
        ];
    }

    /**
     * Format an article record for API response.
     *
     * @param array $row Database record
     *
     * @return array Formatted article data
     */
    public function formatArticleRecord(array $row): array
    {
        $textId = isset($row['TxID']) && $row['TxID'] !== null && $row['TxID'] !== ''
            ? (int)$row['TxID'] : null;
        $isArchived = $textId !== null && !empty($row['TxArchivedAt']);

        $status = 'new';
        if ($textId !== null && !$isArchived) {
            $status = 'imported';
        } elseif ($isArchived) {
            $status = 'archived';
        } elseif (str_starts_with((string)$row['FlLink'], ' ')) {
            $status = 'error';
        }

        // For archived texts, report the same TxID as archivedTextId
        $archivedTextId = $isArchived ? $textId : null;
        $activeTextId = ($textId !== null && !$isArchived) ? $textId : null;

        return [
            'id' => (int)$row['FlID'],
            'title' => (string)$row['FlTitle'],
            'link' => trim((string)$row['FlLink']),
            'description' => (string)$row['FlDescription'],
            'date' => (string)$row['FlDate'],
            'audio' => (string)$row['FlAudio'],
            'hasText' => !empty($row['FlText']),
            'status' => $status,
            'textId' => $activeTextId,
            'archivedTextId' => $archivedTextId
        ];
    }

    /**
     * Delete articles.
     *
     * @param int   $feedId     Feed ID
     * @param array $articleIds Article IDs to delete (empty = all)
     *
     * @return array{success: bool, deleted: int, error?: string}
     */
    public function deleteArticles(int $feedId, array $articleIds = []): array
    {
        // Verify the caller owns the target feed before doing any deletes.
        // getFeedById is user-scoped (news_feeds is in USER_SCOPED_TABLES),
        // so a foreign feedId returns null and we bail out before touching
        // feed_links — which has no UsID column of its own and would
        // otherwise let any logged-in user wipe any other user's articles.
        $feed = $this->feedFacade->getFeedById($feedId);
        if ($feed === null) {
            return ['success' => false, 'deleted' => 0, 'error' => 'Feed not found'];
        }

        if (empty($articleIds)) {
            // Delete all articles for feed (article repo also re-checks
            // feed ownership, so this stays safe even if the upstream
            // gate above is removed).
            $deleted = $this->feedFacade->deleteArticles((string)$feedId);
        } else {
            // Delete specific articles. The whereIn on FlNfID alone
            // doesn't gate ownership because feed_links has no UsID
            // column; the getFeedById check above is what makes it safe.
            $ids = array_map('intval', $articleIds);
            $deleted = QueryBuilder::table('feed_links')
                ->whereIn('FlID', $ids)
                ->whereIn('FlNfID', [$feedId])
                ->delete();
        }

        return [
            'success' => true,
            'deleted' => $deleted
        ];
    }

    /**
     * Extract selected articles into editable texts without importing them.
     *
     * This is the read half of {@see importArticles()}: it runs the same
     * extraction, but returns the texts for the caller to edit and submit
     * rather than writing them. It performs no writes except marking links
     * whose extraction failed, which `importArticles()` does too — leaving
     * them unmarked would make the same article fail again on every attempt.
     *
     * @param array<string, mixed> $data Request payload carrying `article_ids`
     *
     * @return array{success: bool, texts: list<array<string, mixed>>,
     *               language_id: int, errors: list<string>}
     */
    public function extractArticles(array $data): array
    {
        $articleIds = $data['article_ids'] ?? [];
        if (!is_array($articleIds) || count($articleIds) === 0) {
            return ['success' => false, 'texts' => [], 'language_id' => 0, 'errors' => ['No articles selected']];
        }

        $ids = implode(',', array_map('intval', $articleIds));
        $feedLinks = $this->feedFacade->getMarkedFeedLinks($ids);

        $collected = [];
        $errors = [];
        $languageId = 0;

        foreach ($feedLinks as $row) {
            /** @var array<string, mixed> $row */
            $feedOptions = (string)($row['NfOptions'] ?? '');
            $languageId = (int)($row['NfLgID'] ?? 0);

            $flLink = (string)($row['FlLink'] ?? '');
            $flId = (string)($row['FlID'] ?? '');
            $doc = [[
                'link' => empty($flLink) ? ('#' . $flId) : $flLink,
                'title' => (string)($row['FlTitle'] ?? ''),
                'audio' => (string)($row['FlAudio'] ?? ''),
                'text' => (string)($row['FlText'] ?? '')
            ]];

            $charsetRaw = $this->feedFacade->getFeedOption($feedOptions, 'charset');
            $charset = is_string($charsetRaw) ? $charsetRaw : null;
            $texts = $this->feedFacade->extractTextFromArticle(
                $doc,
                (string)($row['NfArticleSectionTags'] ?? ''),
                (string)($row['NfFilterTags'] ?? ''),
                $charset
            );

            if (isset($texts['error'])) {
                /** @var array{message?: string, link?: string[]} $errorData */
                $errorData = $texts['error'];
                $errors[] = $errorData['message'] ?? 'Unknown error';
                foreach ($errorData['link'] ?? [] as $errLink) {
                    $this->feedFacade->markLinkAsError($errLink);
                }
                unset($texts['error']);
            }

            if (is_array($texts)) {
                foreach ($texts as $text) {
                    /** @var array{TxTitle?: mixed, TxText?: mixed, TxAudioURI?: mixed, TxSourceURI?: mixed} $text */
                    $collected[] = [
                        'TxTitle' => (string)($text['TxTitle'] ?? ''),
                        'TxText' => (string)($text['TxText'] ?? ''),
                        'TxAudioURI' => (string)($text['TxAudioURI'] ?? ''),
                        'TxSourceURI' => (string)($text['TxSourceURI'] ?? ''),
                    ];
                }
            }
        }

        return [
            'success' => true,
            'texts' => $collected,
            'language_id' => $languageId,
            'errors' => $errors,
        ];
    }

    /**
     * Create texts from articles the caller has already edited.
     *
     * The write half of {@see extractArticles()}. `importArticles()` extracts
     * and writes in a single step, so it has no way to accept a title or body
     * the user changed in between; this takes the edited rows instead.
     *
     * The tag and the max-texts limit come from the feed's own options, never
     * from the payload. The retired `edit_text_form.php` posted `Nf_Max_Texts`
     * as a hidden field, so a caller could opt out of archiving by editing it;
     * it also never posted a tag at all, which is why texts created through
     * that form came out untagged.
     *
     * @param array<string, mixed> $data Payload carrying `feed_id` and `texts`
     *
     * @return array{success: bool, created: int, archived: int,
     *               errors: list<string>, error?: string}
     */
    public function createTextsFromEdited(array $data): array
    {
        $feedId = (int)($data['feed_id'] ?? 0);
        $rows = $data['texts'] ?? [];

        if ($feedId <= 0 || !is_array($rows) || count($rows) === 0) {
            return [
                'success' => false,
                'created' => 0,
                'archived' => 0,
                'errors' => [],
                'error' => 'feed_id and a non-empty texts list are required',
            ];
        }

        // getFeedById is user-scoped, so a feed owned by somebody else comes
        // back null and nothing is written. Every text created below is
        // tagged and counted against *this* feed, so skipping the check
        // would let any authenticated caller write into another user's feed.
        $feed = $this->feedFacade->getFeedById($feedId);
        if ($feed === null) {
            return [
                'success' => false,
                'created' => 0,
                'archived' => 0,
                'errors' => [],
                'error' => 'Feed not found',
            ];
        }

        [$tagName, $maxTexts] = $this->resolveFeedTagAndLimit($feed);
        $feedLangId = $feed['NfLgID'];

        // The row may name a different language than the feed's, because the
        // retired form offered a per-row picker. Only the caller's own
        // languages are accepted; anything else falls back to the feed's.
        /** @var list<int> $ownLanguageIds */
        $ownLanguageIds = [];
        /** @var array<array-key, array<string, mixed>> $languages */
        $languages = $this->feedFacade->getLanguages();
        foreach ($languages as $language) {
            $ownLanguageIds[] = (int)$language['LgID'];
        }

        $created = 0;
        /** @var list<string> $errors */
        $errors = [];

        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            $title = trim((string)($row['title'] ?? ''));
            $text = (string)($row['text'] ?? '');
            if ($title === '' || trim($text) === '') {
                $errors[] = sprintf('Row %d skipped: title and text are both required', (int)$index + 1);
                continue;
            }

            $langId = (int)($row['language_id'] ?? 0);
            if ($langId <= 0 || !in_array($langId, $ownLanguageIds, true)) {
                $langId = $feedLangId;
            }

            $this->feedFacade->createTextFromFeed([
                'TxLgID' => $langId,
                'TxTitle' => $title,
                'TxText' => $text,
                'TxAudioURI' => (string)($row['audio_uri'] ?? ''),
                'TxSourceURI' => (string)($row['source_uri'] ?? ''),
            ], $tagName);
            $created++;
        }

        $archived = 0;
        if ($created > 0) {
            $stats = $this->feedFacade->archiveOldTexts($tagName, $maxTexts);
            $archived = $stats['archived'];
        }

        return [
            'success' => true,
            'created' => $created,
            'archived' => $archived,
            'errors' => $errors,
        ];
    }

    /**
     * Resolve the tag name and max-texts limit a feed's options imply.
     *
     * Both fall back the same way the import path has always done: an unset
     * tag becomes the feed name truncated to 20 characters, and an unset
     * limit becomes the global `set-max-texts-per-feed` setting.
     *
     * @param array<string, mixed> $row Feed record carrying NfOptions/NfName
     *
     * @return array{0: string, 1: int} Tag name and max texts
     */
    private function resolveFeedTagAndLimit(array $row): array
    {
        $feedOptions = (string)($row['NfOptions'] ?? '');
        $feedName = (string)($row['NfName'] ?? '');

        $tagNameRaw = $this->feedFacade->getFeedOption($feedOptions, 'tag');
        $tagName = is_string($tagNameRaw) && $tagNameRaw !== ''
            ? $tagNameRaw
            : mb_substr($feedName, 0, 20, 'utf-8');

        $maxTextsRaw = $this->feedFacade->getFeedOption($feedOptions, 'max_texts');
        $maxTexts = is_string($maxTextsRaw) ? (int)$maxTextsRaw : 0;
        if (!$maxTexts) {
            $maxTexts = (int)Settings::getWithDefault('set-max-texts-per-feed');
        }

        return [$tagName, $maxTexts];
    }

    /**
     * Import articles as texts.
     *
     * @param array $data Import data:
     *                    - article_ids: array of article IDs
     *
     * @return array{success: bool, imported: int, errors: array}
     */
    public function importArticles(array $data): array
    {
        $articleIds = $data['article_ids'] ?? [];
        if (!is_array($articleIds) || count($articleIds) === 0) {
            return ['success' => false, 'imported' => 0, 'errors' => ['No articles selected']];
        }

        $ids = implode(',', array_map('intval', $articleIds));
        $feedLinks = $this->feedFacade->getMarkedFeedLinks($ids);

        $imported = 0;
        $errors = [];

        foreach ($feedLinks as $row) {
            /** @var array<string, mixed> $row */
            $feedOptions = (string)($row['NfOptions'] ?? '');
            [$tagName, $maxTexts] = $this->resolveFeedTagAndLimit($row);

            $flLink = (string)($row['FlLink'] ?? '');
            $flId = (string)($row['FlID'] ?? '');
            $doc = [[
                'link' => empty($flLink) ? ('#' . $flId) : $flLink,
                'title' => (string)($row['FlTitle'] ?? ''),
                'audio' => (string)($row['FlAudio'] ?? ''),
                'text' => (string)($row['FlText'] ?? '')
            ]];

            $charsetRaw = $this->feedFacade->getFeedOption($feedOptions, 'charset');
            $charset = is_string($charsetRaw) ? $charsetRaw : null;
            $texts = $this->feedFacade->extractTextFromArticle(
                $doc,
                (string)($row['NfArticleSectionTags'] ?? ''),
                (string)($row['NfFilterTags'] ?? ''),
                $charset
            );

            if (isset($texts['error'])) {
                /** @var array{message?: string, link?: string[]} $errorData */
                $errorData = $texts['error'];
                $errors[] = $errorData['message'] ?? 'Unknown error';
                foreach ($errorData['link'] ?? [] as $errLink) {
                    $this->feedFacade->markLinkAsError($errLink);
                }
                unset($texts['error']);
            }

            if (is_array($texts)) {
                foreach ($texts as $text) {
                    /** @var array{TxTitle?: mixed, TxText?: mixed, TxAudioURI?: mixed, TxSourceURI?: mixed} $text */
                    $this->feedFacade->createTextFromFeed([
                        'TxLgID' => (int)($row['NfLgID'] ?? 0),
                        'TxTitle' => (string)($text['TxTitle'] ?? ''),
                        'TxText' => (string)($text['TxText'] ?? ''),
                        'TxAudioURI' => (string)($text['TxAudioURI'] ?? ''),
                        'TxSourceURI' => (string)($text['TxSourceURI'] ?? '')
                    ], $tagName);
                    $imported++;
                }
            }

            $this->feedFacade->archiveOldTexts($tagName, $maxTexts);
        }

        return [
            'success' => true,
            'imported' => $imported,
            'errors' => $errors
        ];
    }

    /**
     * Reset error articles (remove leading space from links).
     *
     * @param int $feedId Feed ID
     *
     * @return array{success: bool, reset: int}
     */
    public function resetErrorArticles(int $feedId): array
    {
        $reset = $this->feedFacade->resetUnloadableArticles((string)$feedId);
        return [
            'success' => true,
            'reset' => $reset
        ];
    }

    // =========================================================================
    // Format Wrappers
    // =========================================================================

    /**
     * Format response for getting articles.
     *
     * @param array $params Filter parameters
     *
     * @return array Articles with pagination
     */
    public function formatGetArticles(array $params): array
    {
        return $this->getArticles($params);
    }

    /**
     * Format response for deleting articles.
     *
     * @param int   $feedId     Feed ID
     * @param array $articleIds Article IDs (empty = all)
     *
     * @return array Deletion result
     */
    public function formatDeleteArticles(int $feedId, array $articleIds = []): array
    {
        return $this->deleteArticles($feedId, $articleIds);
    }

    /**
     * Format response for importing articles.
     *
     * @param array $data Import data
     *
     * @return array Import result
     */
    public function formatImportArticles(array $data): array
    {
        return $this->importArticles($data);
    }

    /**
     * Format response for resetting error articles.
     *
     * @param int $feedId Feed ID
     *
     * @return array Reset result
     */
    public function formatResetErrorArticles(int $feedId): array
    {
        return $this->resetErrorArticles($feedId);
    }
}
