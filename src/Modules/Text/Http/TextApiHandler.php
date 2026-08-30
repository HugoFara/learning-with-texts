<?php

/**
 * Text API Handler
 *
 * Thin facade delegating to focused sub-handlers:
 * - TextPositionApiHandler: position, audio, display mode, bulk status
 * - TextAnnotationApiHandler: annotation CRUD, print items, edit term form
 * - TextTermApiHandler: words, translations, scoring, text listing
 *
 * PHP version 8.1
 *
 * @category Lwt
 * @package  Lwt\Modules\Text\Http
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Text\Http;

use Lwt\Modules\Text\Application\Services\DifficultyEstimationService;
use Lwt\Modules\Text\Application\Services\GdlImportService;
use Lwt\Modules\Text\Application\Services\GutenbergSuggestionService;
use Lwt\Modules\Book\Application\BookFacade;
use Lwt\Modules\Tags\Application\TagsFacade;
use Lwt\Modules\Text\Application\TextFacade;
use Lwt\Modules\Text\Application\UseCases\UpdateText;
use Lwt\Shared\Infrastructure\Container\Container;
use Lwt\Shared\Infrastructure\Database\Settings;
use Lwt\Shared\Infrastructure\Http\GdlClient;
use Lwt\Modules\Vocabulary\Application\Services\WordDiscoveryService;
use Lwt\Shared\Http\ApiRoutableInterface;
use Lwt\Shared\Http\ApiRoutableTrait;
use Lwt\Shared\Infrastructure\Database\QueryBuilder;
use Lwt\Shared\Infrastructure\Http\GutenbergClient;
use Lwt\Shared\Infrastructure\Http\JsonResponse;
use Lwt\Shared\Infrastructure\Utilities\StringUtils;
use Lwt\Api\V1\Response;

/**
 * Handler for text-related API operations.
 *
 * Delegates to TextPositionApiHandler, TextAnnotationApiHandler,
 * and TextTermApiHandler for actual logic.
 */
class TextApiHandler implements ApiRoutableInterface
{
    use ApiRoutableTrait;

    private TextPositionApiHandler $positionHandler;
    private TextAnnotationApiHandler $annotationHandler;
    private TextTermApiHandler $termHandler;

    public function __construct(?WordDiscoveryService $discoveryService = null)
    {
        $this->positionHandler = new TextPositionApiHandler($discoveryService);
        $this->annotationHandler = new TextAnnotationApiHandler();
        $this->termHandler = new TextTermApiHandler();
    }

    // =========================================================================
    // Position & Display Mode (delegates to TextPositionApiHandler)
    // =========================================================================

    public function saveTextPosition(int $textid, int $position): void
    {
        $this->positionHandler->saveTextPosition($textid, $position);
    }

    public function saveAudioPosition(int $textid, float $audioposition): void
    {
        $this->positionHandler->saveAudioPosition($textid, $audioposition);
    }

    public function formatSetTextPosition(int $textId, int $position): array
    {
        return $this->positionHandler->formatSetTextPosition($textId, $position);
    }

    public function formatSetAudioPosition(int $textId, float $position): array
    {
        return $this->positionHandler->formatSetAudioPosition($textId, $position);
    }

    public function setDisplayMode(int $textId, ?int $annotations, ?bool $romanization, ?bool $translation): array
    {
        return $this->positionHandler->setDisplayMode($textId, $annotations, $romanization, $translation);
    }

    public function formatSetDisplayMode(int $textId, array $params): array
    {
        return $this->positionHandler->formatSetDisplayMode($textId, $params);
    }

    public function markAllWellKnown(int $textId): array
    {
        return $this->positionHandler->markAllWellKnown($textId);
    }

    public function markAllIgnored(int $textId): array
    {
        return $this->positionHandler->markAllIgnored($textId);
    }

    public function formatMarkAllWellKnown(int $textId): array
    {
        return $this->positionHandler->formatMarkAllWellKnown($textId);
    }

    public function formatMarkAllIgnored(int $textId): array
    {
        return $this->positionHandler->formatMarkAllIgnored($textId);
    }

    // =========================================================================
    // Annotation & Print (delegates to TextAnnotationApiHandler)
    // =========================================================================

    public function saveImprTextData(int $textid, int $line, string $val): array
    {
        return $this->annotationHandler->saveImprTextData($textid, $line, $val);
    }

    public function saveImprText(int $textid, string $elem, object $data): array
    {
        return $this->annotationHandler->saveImprText($textid, $elem, $data);
    }

    public function formatSetAnnotation(int $textId, string $elem, string $data): array
    {
        return $this->annotationHandler->formatSetAnnotation($textId, $elem, $data);
    }

    public function getPrintItems(int $textId): array
    {
        return $this->annotationHandler->getPrintItems($textId);
    }

    public function formatGetPrintItems(int $textId): array
    {
        return $this->annotationHandler->formatGetPrintItems($textId);
    }

    public function getAnnotation(int $textId): array
    {
        return $this->annotationHandler->getAnnotation($textId);
    }

    public function formatGetAnnotation(int $textId): array
    {
        return $this->annotationHandler->formatGetAnnotation($textId);
    }

    public function makeTrans(int $i, ?int $wid, string $trans, string $word, int $lang): string
    {
        return $this->annotationHandler->makeTrans($i, $wid, $trans, $word, $lang);
    }

    public function editTermForm(int $textid): string
    {
        return $this->annotationHandler->editTermForm($textid);
    }

    public function formatEditTermForm(int $textId): array
    {
        return $this->annotationHandler->formatEditTermForm($textId);
    }

    // =========================================================================
    // Terms & Scoring (delegates to TextTermApiHandler)
    // =========================================================================

    public function getWords(int $textId): array
    {
        return $this->termHandler->getWords($textId);
    }

    public function formatGetWords(int $textId): array
    {
        return $this->termHandler->formatGetWords($textId);
    }

    public function formatTextsByLanguage(int $langId, array $params): array
    {
        return $this->termHandler->formatTextsByLanguage($langId, $params);
    }

    public function formatArchivedTextsByLanguage(int $langId, array $params): array
    {
        return $this->termHandler->formatArchivedTextsByLanguage($langId, $params);
    }

    public function getTranslations(int $wordId): array
    {
        return $this->termHandler->getTranslations($wordId);
    }

    public function getTermTranslations(string $wordlc, int $textid): array
    {
        return $this->termHandler->getTermTranslations($wordlc, $textid);
    }

    public function formatTermTranslations(string $termLc, int $textId): array
    {
        return $this->termHandler->formatTermTranslations($termLc, $textId);
    }

    public function formatGetTextScore(int $textId): array
    {
        return $this->termHandler->formatGetTextScore($textId);
    }

    public function formatGetTextScores(array $textIds): array
    {
        return $this->termHandler->formatGetTextScores($textIds);
    }

    public function formatGetRecommendedTexts(int $languageId, array $params): array
    {
        return $this->termHandler->formatGetRecommendedTexts($languageId, $params);
    }

    // =========================================================================
    // Routing Methods (ApiRoutableInterface)
    // =========================================================================

    public function routeGet(array $fragments, array $params): JsonResponse
    {
        $frag1 = $this->frag($fragments, 1);
        $frag2 = $this->frag($fragments, 2);

        if ($frag1 === 'gutenberg-suggestions') {
            return $this->handleGutenbergSuggestions($params);
        } elseif ($frag1 === 'library-search') {
            return $this->handleLibrarySearch($params);
        } elseif ($frag1 === 'library-preview') {
            return $this->handleLibraryPreview($params);
        } elseif ($frag1 === 'gdl-search') {
            return $this->handleGdlSearch($params);
        } elseif ($frag1 === 'reader-level') {
            return $this->handleReaderLevel($params);
        } elseif ($frag1 === 'scoring') {
            if ($frag2 === 'recommended') {
                $langId = (int) ($params['language_id'] ?? 0);
                if ($langId <= 0) {
                    return Response::error('language_id is required', 400);
                }
                return Response::success($this->formatGetRecommendedTexts($langId, $params));
            }
            $textId = isset($params['text_id']) ? (int) $params['text_id'] : 0;
            $textIds = (string) ($params['text_ids'] ?? '');

            if ($textId > 0) {
                return Response::success($this->formatGetTextScore($textId));
            } elseif ($textIds !== '') {
                $ids = array_map('intval', explode(',', $textIds));
                $ids = array_filter($ids, fn($id) => $id > 0);
                if (empty($ids)) {
                    return Response::error('No valid text IDs provided', 400);
                }
                return Response::success($this->formatGetTextScores($ids));
            }
            return Response::error('text_id or text_ids parameter is required', 400);
        } elseif ($frag1 === 'by-language') {
            if ($frag2 === '' || !ctype_digit($frag2)) {
                return Response::error('Expected Language ID after "by-language"', 404);
            }
            return Response::success($this->formatTextsByLanguage((int) $frag2, $params));
        } elseif ($frag1 === 'archived-by-language') {
            if ($frag2 === '' || !ctype_digit($frag2)) {
                return Response::error('Expected Language ID after "archived-by-language"', 404);
            }
            return Response::success($this->formatArchivedTextsByLanguage((int) $frag2, $params));
        } elseif ($frag1 !== '' && ctype_digit($frag1)) {
            $textId = (int) $frag1;
            if ($frag2 === 'words') {
                return Response::success($this->formatGetWords($textId));
            } elseif ($frag2 === 'print-items') {
                return Response::success($this->formatGetPrintItems($textId));
            } elseif ($frag2 === 'annotation') {
                return Response::success($this->formatGetAnnotation($textId));
            } elseif ($frag2 === 'book-context') {
                return $this->formatGetBookContext($textId);
            } elseif ($frag2 === 'audio') {
                return $this->formatGetAudio($textId);
            }
            return Response::error(
                'Expected "words", "print-items", "annotation", "book-context", or "audio"',
                404
            );
        }
        return Response::error(
            'Expected "gutenberg-suggestions", "library-search", "library-preview", '
            . '"gdl-search", "scoring", "by-language", "archived-by-language", or text ID',
            404
        );
    }

    public function routePost(array $fragments, array $params): JsonResponse
    {
        $frag1 = $this->frag($fragments, 1);
        $frag2 = $this->frag($fragments, 2);

        if ($frag1 === 'extract-url') {
            $url = (string) ($params['url'] ?? '');
            if ($url === '') {
                return Response::error('url parameter is required', 400);
            }
            $titleHint = (string) ($params['titleHint'] ?? '');
            $extractor = new \Lwt\Shared\Infrastructure\Http\WebPageExtractor();
            $result = $extractor->extractFromUrl($url, $titleHint);
            if (isset($result['error'])) {
                return Response::error($result['error'], 422);
            }
            return Response::success($result);
        }

        if ($frag1 === 'extract-epub-url') {
            return $this->handleExtractEpubUrl($params);
        }

        if ($frag1 === 'check') {
            return $this->handleCheckText($params);
        }

        if ($frag1 === '') {
            return $this->handleSaveText(0, $params);
        }

        if (!ctype_digit($frag1)) {
            return Response::error('Text ID (Integer) Expected', 404);
        }

        $textId = (int) $frag1;

        switch ($frag2) {
            case 'annotation':
                return Response::success($this->formatSetAnnotation(
                    $textId,
                    (string) ($params['elem'] ?? ''),
                    (string) ($params['data'] ?? '')
                ));
            case 'audio-position':
                return Response::success($this->formatSetAudioPosition(
                    $textId,
                    (float) ($params['position'] ?? 0)
                ));
            case 'reading-position':
                return Response::success($this->formatSetTextPosition(
                    $textId,
                    (int) ($params['position'] ?? 0)
                ));
            default:
                return Response::error('Endpoint Not Found: ' . $frag2, 404);
        }
    }

    public function routePut(array $fragments, array $params): JsonResponse
    {
        $frag1 = $this->frag($fragments, 1);
        $frag2 = $this->frag($fragments, 2);

        if ($frag1 === 'bulk-action') {
            return $this->handleBulkAction($params);
        }

        if ($frag1 === 'archived') {
            if ($frag2 === '' || !ctype_digit($frag2)) {
                return Response::error('Archived Text ID (Integer) Expected', 404);
            }
            return $this->handleSaveArchivedText((int) $frag2, $params);
        }

        if ($frag1 === '' || !ctype_digit($frag1)) {
            return Response::error('Text ID (Integer) Expected', 404);
        }

        $textId = (int) $frag1;

        if ($frag2 === '') {
            return $this->handleSaveText($textId, $params);
        }

        switch ($frag2) {
            case 'display-mode':
                return Response::success($this->formatSetDisplayMode($textId, $params));
            case 'mark-all-wellknown':
                return Response::success($this->formatMarkAllWellKnown($textId));
            case 'mark-all-ignored':
                return Response::success($this->formatMarkAllIgnored($textId));
            default:
                return Response::error('Expected "display-mode", "mark-all-wellknown", or "mark-all-ignored"', 404);
        }
    }

    /**
     * Handle POST /texts (create) and PUT /texts/{id} (update).
     *
     * The JSON counterpart of the `op=Save`/`op=Change` form POST the text
     * editor used to make, so the editor works against a configurable API base
     * URL rather than the page origin (#262).
     *
     * Two things the form path left implicit are explicit here:
     *
     * The language is checked for ownership. `texts.TxLgID` has a foreign key
     * to `languages`, but a foreign key proves the row exists, not that the
     * caller owns it — the same gap `createTermForLanguage()` closes, and the
     * form path never closed because `$this->param('TxLgID')` went straight
     * into the INSERT. On update the text itself is user-scoped by
     * QueryBuilder, so a foreign id simply matches no row.
     *
     * Tags arrive as names in the payload instead of being read back out of
     * `$_REQUEST` by TagsFacade, which a JSON request does not populate.
     *
     * @param int                  $textId Text id, or 0 to create
     * @param array<string, mixed> $params Request body
     */
    private function handleSaveText(int $textId, array $params): JsonResponse
    {
        $title = trim((string) ($params['title'] ?? ''));
        if ($title === '') {
            return Response::error('title is required', 400);
        }

        $text = (string) ($params['text'] ?? '');
        if (trim($text) === '') {
            return Response::error('text is required', 400);
        }

        $languageId = (int) ($params['language_id'] ?? 0);
        if ($languageId <= 0) {
            return Response::error('language_id is required', 400);
        }
        // languages is user-scoped, so somebody else's language finds nothing.
        $ownsLanguage = QueryBuilder::table('languages')
            ->where('LgID', '=', $languageId)
            ->existsPrepared();
        if (!$ownsLanguage) {
            return Response::error('Language not found', 404);
        }

        $audioUri = (string) ($params['audio_uri'] ?? '');
        $sourceUri = (string) ($params['source_uri'] ?? '');
        $tags = $this->tagNames($params);

        $facade = Container::getInstance()->getTyped(TextFacade::class);

        // A text long enough to need splitting becomes a book of chapters
        // rather than one oversized text — but only on create. Splitting an
        // existing text would orphan its id, so an update that grew too long
        // is refused instead.
        if ($textId === 0 && $this->needsSplit($text)) {
            return $this->createBookFromText($languageId, $title, $text, $audioUri, $sourceUri, $tags);
        }

        if (!$facade->validateTextLength($text)) {
            return Response::error(__('text.flash.text_too_long'), 422);
        }

        $result = $facade->saveTextAndReparse(
            $textId,
            $languageId,
            $title,
            $text,
            $audioUri,
            $sourceUri,
            $tags
        );

        return Response::success([
            'textId' => $result['textId'],
            'bookId' => null,
            'message' => $result['message'],
        ]);
    }

    /**
     * Handle POST /texts/check.
     *
     * Parses a text and reports what the parser made of it — the sentence
     * split, the word/expression/non-word tallies and the coverage verdict —
     * without saving anything. The JSON counterpart of the `op=Check` form
     * POST, which answered with a server-rendered report page (#262, #266).
     *
     * @param array<string, mixed> $params Request body
     */
    private function handleCheckText(array $params): JsonResponse
    {
        $text = (string) ($params['text'] ?? '');
        if (trim($text) === '') {
            return Response::error('text is required', 400);
        }

        $languageId = (int) ($params['language_id'] ?? 0);
        if ($languageId <= 0) {
            return Response::error('language_id is required', 400);
        }
        // languages is user-scoped, so somebody else's language finds nothing.
        $ownsLanguage = QueryBuilder::table('languages')
            ->where('LgID', '=', $languageId)
            ->existsPrepared();
        if (!$ownsLanguage) {
            return Response::error('Language not found', 404);
        }

        $facade = Container::getInstance()->getTyped(TextFacade::class);

        if (!$facade->validateTextLength($text)) {
            return Response::error(__('text.flash.text_too_long'), 422);
        }

        return Response::success(
            $facade->checkTextReport(StringUtils::removeSoftHyphens($text), $languageId)
        );
    }

    /**
     * Handle PUT /texts/archived/{id}.
     *
     * The JSON counterpart of the `op=Change` form POST the archived text
     * editor used to make (#262).
     *
     * An archived text is a row in `texts` with `TxArchivedAt` set, and it
     * carries no sentences or word occurrences — so this updates the row and
     * stops, where {@see handleSaveText()} reparses. Reparsing an archived
     * text would build occurrence rows for a text that is not being read.
     *
     * @param int                  $textId Archived text id
     * @param array<string, mixed> $params Request body
     */
    private function handleSaveArchivedText(int $textId, array $params): JsonResponse
    {
        $title = trim((string) ($params['title'] ?? ''));
        if ($title === '') {
            return Response::error('title is required', 400);
        }

        $text = (string) ($params['text'] ?? '');
        if (trim($text) === '') {
            return Response::error('text is required', 400);
        }

        $languageId = (int) ($params['language_id'] ?? 0);
        if ($languageId <= 0) {
            return Response::error('language_id is required', 400);
        }
        // languages is user-scoped, so somebody else's language finds nothing.
        $ownsLanguage = QueryBuilder::table('languages')
            ->where('LgID', '=', $languageId)
            ->existsPrepared();
        if (!$ownsLanguage) {
            return Response::error('Language not found', 404);
        }

        $facade = Container::getInstance()->getTyped(TextFacade::class);

        if (!$facade->validateTextLength($text)) {
            return Response::error(__('text.flash.text_too_long'), 422);
        }

        // Establish the row exists and is the caller's before writing, rather
        // than reading it off the UPDATE's affected count: a save that changes
        // nothing affects no rows, which is not the same as "not found".
        if ($facade->getArchivedTextById($textId) === null) {
            return Response::error('Archived text not found', 404);
        }

        $affected = $facade->updateArchivedText(
            $textId,
            $languageId,
            $title,
            $text,
            (string) ($params['audio_uri'] ?? ''),
            (string) ($params['source_uri'] ?? '')
        );

        TagsFacade::saveArchivedTextTags($textId, $this->tagNames($params));

        return Response::success([
            'textId' => $textId,
            'message' => UpdateText::formatArchivedUpdateMessage($affected),
        ]);
    }

    /**
     * Read the tag names out of a save payload.
     *
     * Accepts `tags: ["a", "b"]`. Anything that is not a non-empty string is
     * dropped rather than stored, so a malformed entry cannot create a tag.
     *
     * @param array<string, mixed> $params Request body
     *
     * @return string[]
     */
    private function tagNames(array $params): array
    {
        /** @var mixed $raw */
        $raw = $params['tags'] ?? [];
        if (!is_array($raw)) {
            return [];
        }

        $names = [];
        /** @var mixed $entry */
        foreach ($raw as $entry) {
            if (!is_string($entry)) {
                continue;
            }
            $name = trim($entry);
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * Whether a text is long enough that it should be split into a book.
     *
     * Returns false when the Book module is unavailable, which keeps a save
     * working on an install where the split feature cannot load.
     */
    private function needsSplit(string $text): bool
    {
        try {
            return Container::getInstance()->getTyped(BookFacade::class)->needsSplit($text);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Turn an over-long text into a book of chapters.
     *
     * @param string[] $tags Tag names to apply to every chapter
     */
    private function createBookFromText(
        int $languageId,
        string $title,
        string $text,
        string $audioUri,
        string $sourceUri,
        array $tags
    ): JsonResponse {
        try {
            $bookFacade = Container::getInstance()->getTyped(BookFacade::class);
            $result = $bookFacade->createBookFromText(
                $languageId,
                $title,
                $text,
                null,
                $audioUri,
                $sourceUri,
                [],
                \Lwt\Shared\Infrastructure\Globals::getCurrentUserId()
            );
        } catch (\Throwable $e) {
            return Response::error(
                __('text.flash.error_creating_book', ['error' => $e->getMessage()]),
                500
            );
        }

        if (!$result['success']) {
            return Response::error($result['message'], 422);
        }

        $textIds = $result['textIds'] ?? [];

        // createBookFromText() takes tag *ids*, which the editor does not have
        // — it works in names, like every other tag surface. Applying them
        // afterwards costs one statement per name rather than per chapter.
        foreach ($tags as $tag) {
            TagsFacade::addTagToTexts($tag, $textIds);
        }

        return Response::success([
            'textId' => $textIds[0] ?? null,
            'bookId' => $result['bookId'],
            'message' => $result['message'],
        ]);
    }

    /**
     * Handle PUT /texts/bulk-action — archive or delete multiple texts.
     *
     * The JSON counterpart of the legacy mark-action form POST: it calls the
     * same TextFacade methods, which run under QueryBuilder's automatic
     * per-user scope, so a caller can only affect their own texts regardless of
     * the IDs sent. Only the two destructive actions are exposed here; tag /
     * review / reparse stay on the form path. Delivered as JSON so it works
     * against a configurable API base URL (a form POST would hit the page
     * origin, not the chosen server).
     *
     * @param array<string, mixed> $params { action: "archive"|"delete", ids: int[] }
     */
    private function handleBulkAction(array $params): JsonResponse
    {
        $action = (string) ($params['action'] ?? '');
        if ($action !== 'archive' && $action !== 'delete') {
            return Response::error('Expected action "archive" or "delete"', 400);
        }

        $rawIds = $params['ids'] ?? [];
        if (!is_array($rawIds)) {
            return Response::error('ids must be an array', 400);
        }
        $ids = array_values(array_filter(
            array_map(static fn ($id): int => (int) $id, $rawIds),
            static fn (int $id): bool => $id > 0
        ));
        if ($ids === []) {
            return Response::error('No text IDs provided', 400);
        }

        $facade = Container::getInstance()->getTyped(TextFacade::class);

        return $action === 'archive'
            ? Response::success($facade->archiveTexts($ids))
            : Response::success($facade->deleteTexts($ids));
    }

    /**
     * Handle GET /texts/{id}/book-context — the reading screen's chapter nav.
     *
     * Returns the book/chapter context (titles, chapter index, prev/next text
     * ids) so a shell-free client can render the prev/next navigation that the
     * server otherwise bakes into read_desktop.php. The body is `{ "book": null }`
     * when the text is standalone (not part of a book) — a normal state, not an
     * error. The underlying query runs under QueryBuilder's per-user scope, so
     * the context of another user's text is never returned.
     */
    private function formatGetBookContext(int $textId): JsonResponse
    {
        $facade = Container::getInstance()->getTyped(BookFacade::class);

        return Response::success(['book' => $facade->getBookContextForText($textId)]);
    }

    /**
     * Handle GET /texts/{id}/audio — the reading screen's media-player config.
     *
     * Returns the per-text audio source and saved position plus the global
     * player settings, mirroring what MediaService bakes into the server-rendered
     * player, so a shell-free client can render the player itself. A 404 is
     * returned when the text does not exist or is not owned by the caller
     * (getTextForReading is per-user scoped); a present text with no audio yields
     * an empty `uri`.
     */
    private function formatGetAudio(int $textId): JsonResponse
    {
        $facade = Container::getInstance()->getTyped(TextFacade::class);
        $header = $facade->getTextForReading($textId);
        if ($header === null) {
            return Response::error('Text not found', 404);
        }

        // Defaults mirror MediaService::renderHtml5AudioPlayer (5s skip, 1.0x).
        $skip = Settings::get('currentplayerseconds');
        $rate = Settings::get('currentplaybackrate');

        return Response::success([
            'uri' => trim((string) ($header['TxAudioURI'] ?? '')),
            'position' => (float) ($header['TxAudioPosition'] ?? 0),
            'playerSettings' => [
                'repeatMode' => (bool) Settings::getZeroOrOne('currentplayerrepeatmode', 0),
                'skipSeconds' => $skip === '' ? 5 : (int) $skip,
                'playbackRate' => $rate === '' ? 10 : (int) $rate,
            ],
        ]);
    }

    // =========================================================================
    // Library Search (Project Gutenberg)
    // =========================================================================

    /**
     * Handle Gutenberg suggestion requests (popular books by language).
     *
     * @param array $params Request parameters (language_id, page)
     *
     * @return JsonResponse
     */
    private function handleGutenbergSuggestions(array $params): JsonResponse
    {
        $languageId = (int) ($params['language_id'] ?? 0);
        $page = max(1, (int) ($params['page'] ?? 1));

        if ($languageId <= 0) {
            return Response::error('language_id is required', 400);
        }

        $service = new GutenbergSuggestionService();
        $result = $service->getSuggestions($languageId, $page);

        if (isset($result['error'])) {
            return Response::error((string) $result['error'], 502);
        }

        return Response::success($result);
    }

    /**
     * Handle library search requests.
     *
     * @param array $params Request parameters (q, language_id, page)
     *
     * @return JsonResponse
     */
    private function handleLibrarySearch(array $params): JsonResponse
    {
        $query = trim((string) ($params['q'] ?? ''));
        $languageId = (int) ($params['language_id'] ?? 0);
        $page = max(1, (int) ($params['page'] ?? 1));

        $languageCode = null;
        if ($languageId > 0) {
            $languageCode = $this->resolveLanguageCode($languageId);
        }

        $client = new GutenbergClient();
        $result = $client->search($query, $languageCode, $page);

        if (isset($result['error'])) {
            return Response::error($result['error'], 502);
        }

        // Add quick difficulty tiers if a language is selected
        /** @var list<array{id: int, subjects: list<string>}> $resultBooks */
        $resultBooks = $result['results'] ?? [];
        if ($languageId > 0 && $resultBooks !== []) {
            /** @var array<int, list<string>> $subjectsMap */
            $subjectsMap = [];
            foreach ($resultBooks as $book) {
                $subjectsMap[$book['id']] = $book['subjects'] ?? [];
            }

            $service = new DifficultyEstimationService();
            $tiers = $service->estimateQuickTiers($languageId, $subjectsMap);

            $enriched = [];
            foreach ($resultBooks as $book) {
                $book['difficultyTier'] = $tiers[$book['id']] ?? 'medium';
                $enriched[] = $book;
            }
            $result['results'] = $enriched;
        }

        return Response::success($result);
    }

    /**
     * Handle library preview requests (accurate vocabulary coverage).
     *
     * @param array $params Request parameters (url, language_id)
     *
     * @return JsonResponse
     */
    private function handleLibraryPreview(array $params): JsonResponse
    {
        $url = trim((string) ($params['url'] ?? ''));
        $languageId = (int) ($params['language_id'] ?? 0);

        if ($url === '') {
            return Response::error('url parameter is required', 400);
        }

        if ($languageId <= 0) {
            return Response::error('language_id is required', 400);
        }

        $service = new DifficultyEstimationService();
        $result = $service->analyzeTextSample($url, $languageId);

        if (isset($result['error'])) {
            return Response::error($result['error'], 422);
        }

        return Response::success($result);
    }

    /**
     * Handle Global Digital Library search/browse requests.
     *
     * An empty query browses the catalog; difficulty tiers come from the
     * client (derived from each book's GDL reading level).
     *
     * @param array $params Request parameters (q, language_id, page)
     *
     * @return JsonResponse
     */
    private function handleGdlSearch(array $params): JsonResponse
    {
        $query = trim((string) ($params['q'] ?? ''));
        $languageId = (int) ($params['language_id'] ?? 0);
        $page = max(1, (int) ($params['page'] ?? 1));

        // GDL uses ISO 639-1 slugs for the common languages (en, fr, de, …),
        // so the same LgSourceLang resolution as Gutenberg applies.
        $languageCode = null;
        if ($languageId > 0) {
            $languageCode = $this->resolveLanguageCode($languageId);
        }

        $client = new GdlClient();
        $result = $client->search($query, $languageCode, $page);

        if (isset($result['error'])) {
            return Response::error($result['error'], 502);
        }

        return Response::success($result);
    }

    /**
     * Handle reader-level requests (vocabulary size + beginner flag).
     *
     * Drives beginner-aware ordering of home-page suggestions: beginners see
     * the Global Digital Library's easy readers first, advanced learners see
     * Project Gutenberg first.
     *
     * @param array $params Request parameters (language_id)
     *
     * @return JsonResponse
     */
    private function handleReaderLevel(array $params): JsonResponse
    {
        $languageId = (int) ($params['language_id'] ?? 0);
        if ($languageId <= 0) {
            return Response::error('language_id is required', 400);
        }

        $service = new DifficultyEstimationService();
        return Response::success($service->getReaderProfile($languageId));
    }

    /**
     * Handle Global Digital Library ePUB import requests.
     *
     * Downloads the ePUB, extracts its text, and rejects image-only picture
     * books with too little readable content.
     *
     * @param array $params Request parameters (url)
     *
     * @return JsonResponse
     */
    private function handleExtractEpubUrl(array $params): JsonResponse
    {
        $url = (string) ($params['url'] ?? '');
        if ($url === '') {
            return Response::error('url parameter is required', 400);
        }

        $service = new GdlImportService();
        $result = $service->extractText($url);

        if (isset($result['error'])) {
            return Response::error($result['error'], 422);
        }

        return Response::success($result);
    }

    /**
     * Resolve a language ID to an ISO 639-1 code for Gutenberg.
     *
     * Tries LgSourceLang first, then guesses from LgName.
     *
     * @param int $languageId Language ID
     *
     * @return string|null ISO code or null
     */
    private function resolveLanguageCode(int $languageId): ?string
    {
        $row = QueryBuilder::table('languages')
            ->select(['LgSourceLang', 'LgName'])
            ->where('LgID', '=', $languageId)
            ->firstPrepared();

        if ($row === null) {
            return null;
        }

        // Use explicit source language code if set.
        // Strip region/script subtags (e.g. "zh-CN" → "zh") because
        // Gutendex only accepts bare ISO 639-1 codes.
        $sourceLang = (string) ($row['LgSourceLang'] ?? '');
        if ($sourceLang !== '') {
            $parts = explode('-', $sourceLang, 2);
            return strtolower($parts[0]);
        }

        // Fall back to guessing from language name
        $name = (string) ($row['LgName'] ?? '');
        return GutenbergClient::guessLanguageCode($name);
    }
}
