<?php

/**
 * Admin API Handler
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Admin\Http
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Admin\Http;

use Lwt\Shared\Infrastructure\Utilities\StringUtils;
use Lwt\Shared\Infrastructure\Database\QueryBuilder;
use Lwt\Shared\Infrastructure\Database\Settings;
use Lwt\Shared\Infrastructure\Globals;
use Lwt\Modules\Admin\Domain\SettingDefinitions;
use Lwt\Modules\Vocabulary\Domain\ValueObject\TermStatus;
use Lwt\Shared\Http\ApiRoutableInterface;
use Lwt\Shared\Http\ApiRoutableTrait;
use Lwt\Shared\Infrastructure\Http\JsonResponse;
use Lwt\Api\V1\Response;
use Lwt\Modules\Admin\Application\AdminFacade;
use Lwt\Modules\Text\Application\Services\TextStatisticsService;
use Lwt\Modules\Admin\Application\Services\MediaService;

/**
 * API handler for admin-related operations.
 *
 * Merges functionality from SettingsHandler and StatisticsHandler.
 *
 * @since 3.0.0
 */
class AdminApiHandler implements ApiRoutableInterface
{
    use ApiRoutableTrait;

    public function routeGet(array $fragments, array $params): JsonResponse
    {
        $frag1 = $this->frag($fragments, 1);

        if ($frag1 === 'theme-path') {
            return Response::success($this->formatThemePath((string) ($params['path'] ?? '')));
        }
        if ($frag1 === 'status-definitions') {
            // Single source of truth for the word-status model, consumed by
            // the frontend status store. See TermStatus::definitions().
            return Response::success(['statuses' => TermStatus::definitions()]);
        }
        return Response::error('Endpoint Not Found: ' . $frag1, 404);
    }

    public function routePost(array $fragments, array $params): JsonResponse
    {
        $key = (string) ($params['key'] ?? '');

        // Admin-scoped keys persist at StUsID=0 and are read back by every
        // user. Non-admins writing them would clobber a global default for
        // everyone, so the boundary must be enforced at the API edge.
        if (
            SettingDefinitions::getScope($key) === SettingDefinitions::SCOPE_ADMIN
            && Globals::isMultiUserEnabled()
            && !Globals::isCurrentUserAdmin()
        ) {
            return Response::error('Permission denied: admin-scoped setting', 403);
        }

        return Response::success($this->formatSaveSetting(
            $key,
            (string) ($params['value'] ?? '')
        ));
    }

    /**
     * Constructor.
     *
     * @param AdminFacade $adminFacade Admin facade
     */
    public function __construct(
        private AdminFacade $adminFacade
    ) {
    }

    // =========================================================================
    // Settings Operations
    // =========================================================================

    /**
     * Save a setting to the database.
     *
     * @param string $key   Setting name
     * @param string $value Setting value
     *
     * @return array{error?: string, message?: string, text_count?: int, last_text?: array<array-key, mixed>|null}
     */
    public function saveSetting(string $key, string $value): array
    {
        try {
            // USER-scoped settings must persist under the current user's
            // StUsID so Settings::getWithDefault() can read them back —
            // it deliberately skips the StUsID=0 row for USER keys to
            // avoid leaking another user's choice into a fresh session.
            $userId = Globals::getCurrentUserId();
            $isUserScope = SettingDefinitions::getScope($key) === SettingDefinitions::SCOPE_USER;
            if ($userId !== null && $isUserScope) {
                Settings::saveForUser($key, $value, $userId);
            } else {
                Settings::save($key, $value);
            }
            $result = ["message" => "Setting saved"];

            // For language changes, include the text count and last text info for that language
            if ($key === 'currentlanguage' && $value !== '') {
                $languageId = (int)$value;
                $result['text_count'] = $this->getTextCountForLanguage($languageId);
                $result['last_text'] = $this->getLastTextForLanguage($languageId);
            }

            return $result;
        } catch (\InvalidArgumentException $e) {
            return ["error" => $e->getMessage()];
        }
    }

    /**
     * Get the last text information for a specific language.
     *
     * @param int $languageId Language ID
     *
     * @return array<string, mixed>|null Last text data or null if none exists
     */
    private function getLastTextForLanguage(int $languageId): ?array
    {
        // Get the current text ID
        $currentTextId = Settings::get('currenttext');

        if ($currentTextId === '') {
            return null;
        }

        $textId = (int)$currentTextId;

        // Check if the current text belongs to this language
        $textData = QueryBuilder::table('texts')
            ->selectRaw('TxID, TxTitle, TxLgID, LENGTH(TxAnnotatedText) > 0 AS annotated')
            ->where('TxID', '=', $textId)
            ->where('TxLgID', '=', $languageId)
            ->firstPrepared();

        if ($textData === null) {
            // Current text doesn't belong to this language, find the most recent one
            $textData = QueryBuilder::table('texts')
                ->selectRaw('TxID, TxTitle, TxLgID, LENGTH(TxAnnotatedText) > 0 AS annotated')
                ->where('TxLgID', '=', $languageId)
                ->orderBy('TxID', 'DESC')
                ->limit(1)
                ->firstPrepared();
        }

        if ($textData === null) {
            return null;
        }

        // Get language name
        /** @var string|null $languageName */
        $languageName = QueryBuilder::table('languages')
            ->where('LgID', '=', $languageId)
            ->valuePrepared('LgName');

        $textId = (int)$textData['TxID'];

        // Get text statistics
        $textStatsService = new TextStatisticsService();
        $textStats = $textStatsService->getTextWordCount([$textId]);
        $todoCount = $textStatsService->getTodoWordsCount($textId);

        $stats = [
            'unknown' => $todoCount,
            's1' => $textStats['statu'][$textId][1] ?? 0,
            's2' => $textStats['statu'][$textId][2] ?? 0,
            's3' => $textStats['statu'][$textId][3] ?? 0,
            's4' => $textStats['statu'][$textId][4] ?? 0,
            's5' => $textStats['statu'][$textId][5] ?? 0,
            's98' => $textStats['statu'][$textId][98] ?? 0,
            's99' => $textStats['statu'][$textId][99] ?? 0,
        ];
        $stats['total'] = $stats['unknown'] + $stats['s1'] + $stats['s2'] + $stats['s3']
            + $stats['s4'] + $stats['s5'] + $stats['s98'] + $stats['s99'];

        return [
            'id' => $textId,
            'title' => $textData['TxTitle'],
            'language_id' => (int)$textData['TxLgID'],
            'language_name' => (string)$languageName,
            'annotated' => (bool)$textData['annotated'],
            'stats' => $stats
        ];
    }

    /**
     * Get the count of active (non-archived) texts for a language.
     *
     * @param int $languageId Language ID
     *
     * @return int Number of texts
     */
    private function getTextCountForLanguage(int $languageId): int
    {
        return QueryBuilder::table('texts')
            ->where('TxLgID', '=', $languageId)
            ->whereNull('TxArchivedAt')
            ->count();
    }

    /**
     * Get the file path using the current theme.
     *
     * @param string $path Relative filepath using theme
     *
     * @return array{theme_path: string}
     */
    public function getThemePath(string $path): array
    {
        return ["theme_path" => StringUtils::getFilePath($path)];
    }

    /**
     * Format response for saving a setting.
     *
     * @param string $key   Setting key
     * @param string $value Setting value
     *
     * @return array{error?: string, message?: string, text_count?: int, last_text?: array<array-key, mixed>|null}
     */
    public function formatSaveSetting(string $key, string $value): array
    {
        return $this->saveSetting($key, $value);
    }

    /**
     * Format response for getting theme path.
     *
     * @param string $path Relative path
     *
     * @return array{theme_path: string}
     */
    public function formatThemePath(string $path): array
    {
        return $this->getThemePath($path);
    }

    // =========================================================================
    // Statistics Operations
    // =========================================================================

    /**
     * Return statistics about a group of texts.
     *
     * @param string $textsId Comma-separated text IDs
     *
     * @return array Text word count statistics
     */
    public function getTextsStatistics(string $textsId): array
    {
        $service = new TextStatisticsService();
        $textIds = array_map('intval', array_filter(explode(',', $textsId), 'strlen'));
        return $service->getTextWordCount($textIds);
    }

    /**
     * Format response for texts statistics.
     *
     * Transforms the raw statistics data into a format expected by the frontend:
     * - total: unique word count
     * - saved: count of words with any status (1-5, 98, 99)
     * - unknown: count of words without a saved status
     * - unknownPercent: percentage of unknown words
     * - statusCounts: word counts by status
     *
     * @param string $textsId Comma-separated text IDs
     *
     * @return array<string, array{
     *     total: int, saved: int, unknown: int, unknownPercent: int, statusCounts: array<string, int>
     * }>
     */
    public function formatTextsStatistics(string $textsId): array
    {
        $raw = $this->getTextsStatistics($textsId);
        $result = [];

        // Get all text IDs from the request
        $textIds = array_map('intval', explode(',', $textsId));

        foreach ($textIds as $textId) {
            $textIdStr = (string) $textId;

            // Get unique word count (totalu)
            $total = isset($raw['totalu'][$textIdStr])
                ? (int) $raw['totalu'][$textIdStr]
                : 0;

            // Sum saved words from status counts (statu)
            $saved = 0;
            /** @var array<string, int> $statusCounts */
            $statusCounts = [];
            if (isset($raw['statu'][$textIdStr]) && is_array($raw['statu'][$textIdStr])) {
                /**
                 * @var int|string $status
                 * @var int|string $count
                 */
                foreach ($raw['statu'][$textIdStr] as $status => $count) {
                    $countInt = is_int($count) ? $count : (int) $count;
                    $saved += $countInt;
                    $statusCounts[(string) $status] = $countInt;
                }
            }

            // Unknown = total unique - saved unique
            $unknown = $total - $saved;

            // Calculate unknown percentage
            $unknownPercent = $total > 0
                ? (int) round(($unknown / $total) * 100)
                : 0;

            $result[$textIdStr] = [
                'total' => $total,
                'saved' => $saved,
                'unknown' => $unknown,
                'unknownPercent' => $unknownPercent,
                'statusCounts' => $statusCounts
            ];
        }

        return $result;
    }

    // =========================================================================
    // Server Data
    // =========================================================================

    /**
     * Get server data.
     *
     * @return array Server information
     */
    public function getServerData(): array
    {
        return $this->adminFacade->getServerData();
    }

    // =========================================================================
    // Media Operations
    // =========================================================================

    /**
     * List the audio and video files in the media folder.
     *
     * @return array{base_path: string, paths?: string[], folders?: string[], error?: string}
     */
    public function getMediaFiles(): array
    {
        $mediaService = new MediaService();
        return $mediaService->getMediaPaths();
    }

    /**
     * Format response for media files list.
     *
     * @return array{base_path: string, paths?: string[], folders?: string[], error?: string}
     */
    public function formatMediaFiles(): array
    {
        return $this->getMediaFiles();
    }
}
