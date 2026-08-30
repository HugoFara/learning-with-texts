<?php

/**
 * Language API Handler
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Language\Http
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Language\Http;

use Lwt\Shared\Infrastructure\Database\QueryBuilder;
use Lwt\Shared\Infrastructure\Database\Settings;
use Lwt\Shared\Http\ApiRoutableInterface;
use Lwt\Shared\Http\ApiRoutableTrait;
use Lwt\Shared\Infrastructure\Http\JsonResponse;
use Lwt\Api\V1\Response;
use Lwt\Modules\Language\Application\LanguageFacade;
use Lwt\Shared\Infrastructure\Language\LanguagePresets;
use Lwt\Modules\Text\Application\Services\SentenceService;
use Lwt\Modules\Vocabulary\Application\UseCases\FindSimilarTerms;

/**
 * Handler for language-related API operations.
 *
 * @since 3.0.0
 */
class LanguageApiHandler implements ApiRoutableInterface
{
    use ApiRoutableTrait;

    /**
     * @var LanguageFacade Language facade instance
     */
    private LanguageFacade $languageFacade;

    /**
     * @var SentenceService Sentence service instance
     */
    private SentenceService $sentenceService;

    /**
     * @var FindSimilarTerms Similar terms use case instance
     */
    private FindSimilarTerms $similarTermsService;

    /**
     * Constructor - initialize services.
     *
     * @param LanguageFacade|null $languageFacade Language facade instance
     */
    public function __construct(?LanguageFacade $languageFacade = null)
    {
        $this->languageFacade = $languageFacade ?? new LanguageFacade();
        $this->sentenceService = new SentenceService();
        $this->similarTermsService = new FindSimilarTerms();
    }

    /**
     * Get the reading configuration for a language.
     *
     * @param int $langId Language ID
     *
     * @return array{name: string, voiceapi: string, word_parsing: string,
     *               abbreviation: mixed, reading_mode: string, piper_voice_id?: string}
     */
    public function getReadingConfiguration(int $langId): array
    {
        $record = QueryBuilder::table('languages')
            ->select(['LgName', 'LgTTSVoiceAPI', 'LgRegexpWordCharacters', 'LgPiperVoiceId'])
            ->where('LgID', '=', $langId)
            ->firstPrepared();

        if ($record === null) {
            return [
                "name" => "",
                "voiceapi" => "",
                "word_parsing" => "",
                "abbreviation" => "",
                "reading_mode" => "direct"
            ];
        }

        $abbr = $this->languageFacade->getLanguageCode($langId, LanguagePresets::getAll());
        $piperVoiceId = isset($record["LgPiperVoiceId"]) && is_string($record["LgPiperVoiceId"])
            ? $record["LgPiperVoiceId"]
            : null;
        $ttsVoiceApi = is_string($record["LgTTSVoiceAPI"]) ? $record["LgTTSVoiceAPI"] : '';
        $regexpWordChars = is_string($record["LgRegexpWordCharacters"]) ? $record["LgRegexpWordCharacters"] : '';
        $lgName = is_string($record["LgName"]) ? $record["LgName"] : '';

        // Priority: Piper > External API > Internal (MeCab) > Direct
        if ($piperVoiceId !== null && $piperVoiceId !== '') {
            $readingMode = "piper";
        } elseif ($ttsVoiceApi !== '') {
            $readingMode = "external";
        } elseif ($regexpWordChars === "mecab") {
            $readingMode = "internal";
        } else {
            $readingMode = "direct";
        }

        $result = [
            "name" => $lgName,
            "voiceapi" => $ttsVoiceApi,
            "word_parsing" => $regexpWordChars,
            "abbreviation" => $abbr,
            "reading_mode" => $readingMode
        ];

        if ($piperVoiceId !== null && $piperVoiceId !== '') {
            $result["piper_voice_id"] = $piperVoiceId;
        }

        return $result;
    }

    /**
     * Get the phonetic reading of a word based on its language.
     *
     * @param string   $text   Text to get phonetic reading for
     * @param int|null $langId Language ID (optional, use lang code if null)
     * @param string|null $langCode Short language name (optional)
     *
     * @return array{phonetic_reading: string}
     */
    public function getPhoneticReading(string $text, ?int $langId = null, ?string $langCode = null): array
    {
        if ($langId !== null) {
            $data = $this->languageFacade->getPhoneticReadingById($text, $langId);
        } else {
            $data = $this->languageFacade->getPhoneticReadingByCode($text, $langCode ?? '');
        }
        return ["phonetic_reading" => $data];
    }

    /**
     * Get terms similar to a given term.
     *
     * @param int    $langId Language ID
     * @param string $term   Term to find similar terms for
     *
     * @return array{similar_terms: string}
     */
    public function getSimilarTerms(int $langId, string $term): array
    {
        return ["similar_terms" => $this->similarTermsService->getFormattedTerms($langId, $term)];
    }

    /**
     * Get sentences containing a word.
     *
     * @param int         $langId   Language ID
     * @param string      $wordLc   Word in lowercase
     * @param int|null    $wordId   Word ID (null for new terms, -1 for advanced search)
     *
     * @return array Sentences with the word
     */
    public function getSentencesWithTerm(int $langId, string $wordLc, ?int $wordId): array
    {
        return $this->sentenceService->getSentencesWithWord($langId, $wordLc, $wordId);
    }

    // =========================================================================
    // API Response Formatters
    // =========================================================================

    /**
     * Format response for reading configuration.
     *
     * @param int $langId Language ID
     *
     * @return (mixed|string)[]
     *
     * @psalm-return array{name: string, voiceapi: string, word_parsing: string,
     *               abbreviation: mixed, reading_mode: string, piper_voice_id?: string}
     */
    public function formatReadingConfiguration(int $langId): array
    {
        return $this->getReadingConfiguration($langId);
    }

    /**
     * Format response for phonetic reading.
     *
     * @param array{text?: string, language_id?: int|string, lang?: string} $params Request parameters
     *
     * @return array{phonetic_reading: string}
     */
    public function formatPhoneticReading(array $params): array
    {
        $text = $params['text'] ?? '';
        $languageId = $params['language_id'] ?? null;
        if ($languageId !== null && is_numeric($languageId)) {
            return $this->getPhoneticReading($text, (int)$languageId);
        }
        $lang = $params['lang'] ?? null;
        return $this->getPhoneticReading($text, null, $lang);
    }

    /**
     * Format response for similar terms.
     *
     * @param int    $langId Language ID
     * @param string $term   Term text
     *
     * @return array{similar_terms: string}
     */
    public function formatSimilarTerms(int $langId, string $term): array
    {
        return $this->getSimilarTerms($langId, $term);
    }

    /**
     * Format response for sentences with registered term.
     *
     * @param int    $langId Language ID
     * @param string $wordLc Word in lowercase
     * @param int    $wordId Word ID
     *
     * @return array Sentences with the word
     */
    public function formatSentencesWithRegisteredTerm(int $langId, string $wordLc, int $wordId): array
    {
        return $this->getSentencesWithTerm($langId, $wordLc, $wordId);
    }

    /**
     * Format response for sentences with new term.
     *
     * @param int    $langId         Language ID
     * @param string $wordLc         Word in lowercase
     * @param bool   $advancedSearch Whether to use advanced search
     *
     * @return array Sentences with the word
     */
    public function formatSentencesWithNewTerm(int $langId, string $wordLc, bool $advancedSearch = false): array
    {
        $advanced = $advancedSearch ? -1 : null;
        return $this->getSentencesWithTerm($langId, $wordLc, $advanced);
    }

    /**
     * Format response for languages with text counts.
     *
     * Returns languages that have at least one text, for grouped texts page.
     *
     * @return array{languages: array<int, array{id: int, name: string, text_count: int}>}
     */
    public function formatLanguagesWithTexts(): array
    {
        return [
            'languages' => $this->languageFacade->getLanguagesWithTextCounts()
        ];
    }

    /**
     * Format response for languages with archived text counts.
     *
     * Returns languages that have at least one archived text, for grouped archived texts page.
     *
     * @return array{languages: array<int, array{id: int, name: string, text_count: int}>}
     */
    public function formatLanguagesWithArchivedTexts(): array
    {
        return [
            'languages' => $this->languageFacade->getLanguagesWithArchivedTextCounts()
        ];
    }

    // =========================================================================
    // Language CRUD Operations
    // =========================================================================

    /**
     * Format response for getting all languages with stats.
     *
     * @return array{languages: array, currentLanguageId: int}
     */
    public function formatGetAll(): array
    {
        $currentLangId = (int)Settings::get('currentlanguage');
        return [
            'languages' => $this->languageFacade->getLanguagesWithStats(),
            'currentLanguageId' => $currentLangId
        ];
    }

    /**
     * Format response for getting a single language.
     *
     * @param int $id Language ID
     *
     * @return array|null Language data or null if not found
     */
    public function formatGetOne(int $id): ?array
    {
        $language = $this->languageFacade->getById($id);
        if ($language === null) {
            return null;
        }

        // Every key here must be one PUT /languages/{id} accepts, and every
        // key it accepts must appear here. A field the writer takes but the
        // reader omits is silently reset the first time a client round-trips
        // the language through this endpoint — the seven below (parserType
        // through localDictMode) were exactly that until the form moved off
        // server-rendered values. LanguageFieldRoundTripTest guards the pair.
        return [
            'language' => [
                'id' => $language->id()->toInt(),
                'name' => $language->name(),
                'dict1Uri' => $language->dict1Uri(),
                'dict2Uri' => $language->dict2Uri(),
                'translatorUri' => $language->translatorUri(),
                'exportTemplate' => $language->exportTemplate(),
                'textSize' => $language->textSize(),
                'characterSubstitutions' => $language->characterSubstitutions(),
                'regexpSplitSentences' => $language->regexpSplitSentences(),
                'exceptionsSplitSentences' => $language->exceptionsSplitSentences(),
                'regexpWordCharacters' => $language->regexpWordCharacters(),
                'removeSpaces' => $language->removeSpaces(),
                'splitEachChar' => $language->splitEachChar(),
                'rightToLeft' => $language->rightToLeft(),
                'ttsVoiceApi' => $language->ttsVoiceApi(),
                'showRomanization' => $language->showRomanization(),
                'parserType' => $language->parserType(),
                'sourceLang' => $language->sourceLang(),
                'targetLang' => $language->targetLang(),
                'dict1PopUp' => $language->isDict1PopUp(),
                'dict2PopUp' => $language->isDict2PopUp(),
                'translatorPopUp' => $language->isTranslatorPopUp(),
                'localDictMode' => $language->localDictMode(),
            ],
            'allLanguages' => $this->languageFacade->getAllLanguages()
        ];
    }

    /**
     * Format response for creating a new language.
     *
     * @param array<string, mixed> $data Language data from request
     *
     * @return array{success: bool, id?: int, error?: string}
     */
    public function formatCreate(array $data): array
    {
        // Validate required fields
        $name = isset($data['name']) && is_string($data['name']) ? $data['name'] : '';
        if ($name === '') {
            return ['success' => false, 'error' => 'Language name is required'];
        }

        // Check for duplicate name
        if ($this->languageFacade->isDuplicateName($name)) {
            return ['success' => false, 'error' => 'A language with this name already exists'];
        }

        $id = $this->languageFacade->createFromData($data);
        if ($id > 0) {
            return ['success' => true, 'id' => $id];
        }

        return ['success' => false, 'error' => 'Failed to create language'];
    }

    /**
     * Format response for updating a language.
     *
     * @param int   $id   Language ID
     * @param array<string, mixed> $data Language data from request
     *
     * @return array{success: bool, reparsed?: int, error?: string, message?: string}
     */
    public function formatUpdate(int $id, array $data): array
    {
        // Check language exists
        $existing = $this->languageFacade->getById($id);
        if ($existing === null) {
            return ['success' => false, 'error' => 'Language not found'];
        }

        // Validate required fields
        $name = isset($data['name']) && is_string($data['name']) ? $data['name'] : '';
        if ($name === '') {
            return ['success' => false, 'error' => 'Language name is required'];
        }

        // Check for duplicate name (excluding current)
        if ($this->languageFacade->isDuplicateName($name, $id)) {
            return ['success' => false, 'error' => 'A language with this name already exists'];
        }

        $result = $this->languageFacade->updateFromData($id, $data);
        return [
            'success' => true,
            'reparsed' => $result['reparsed'] ?? 0,
            'message' => $result['message'] ?? ''
        ];
    }

    /**
     * Format response for deleting a language.
     *
     * @param int $id Language ID
     *
     * @return array{success: bool, error?: string,
     *               relatedData?: array{texts: int, archivedTexts: int, words: int, feeds: int}}
     */
    public function formatDelete(int $id): array
    {
        // Check if language can be deleted
        if (!$this->languageFacade->canDelete($id)) {
            $stats = $this->languageFacade->getRelatedDataCounts($id);
            return [
                'success' => false,
                'error' => 'Cannot delete language with existing data',
                'relatedData' => $stats
            ];
        }

        $result = $this->languageFacade->deleteById($id);
        return ['success' => $result];
    }

    /**
     * Format response for getting language stats.
     *
     * @param int $id Language ID
     *
     * @return array{texts: int, archivedTexts: int, words: int, feeds: int}
     */
    public function formatGetStats(int $id): array
    {
        return $this->languageFacade->getRelatedDataCounts($id);
    }

    /**
     * Format response for refreshing (reparsing) a language's texts.
     *
     * @param int $id Language ID
     *
     * @return array{success: bool, sentencesDeleted?: int, textItemsDeleted?: int,
     *               sentencesAdded?: int, textItemsAdded?: int}
     */
    public function formatRefresh(int $id): array
    {
        $result = $this->languageFacade->refreshTexts($id);
        return [
            'success' => true,
            'sentencesDeleted' => $result['sentencesDeleted'],
            'textItemsDeleted' => $result['textItemsDeleted'],
            'sentencesAdded' => $result['sentencesAdded'],
            'textItemsAdded' => $result['textItemsAdded']
        ];
    }

    /**
     * Format response for getting all language definitions (presets).
     *
     * @return array{definitions: array}
     */
    public function formatGetDefinitions(): array
    {
        $definitions = LanguagePresets::getAll();
        $formatted = [];

        foreach ($definitions as $name => $def) {
            $formatted[$name] = [
                'glosbeIso' => $def[0],
                'googleIso' => $def[1],
                'biggerFont' => $def[2],
                'wordCharRegExp' => $def[3],
                'sentSplRegExp' => $def[4],
                'makeCharacterWord' => $def[5],
                'removeSpaces' => $def[6],
                'rightToLeft' => $def[7],
                'parserType' => $def[8]
            ];
        }

        return ['definitions' => $formatted];
    }

    /**
     * Format response for setting a language as default/current.
     *
     * @param int $id Language ID
     *
     * @return array{success: bool}
     */
    public function formatSetDefault(int $id): array
    {
        Settings::save('currentlanguage', (string)$id);
        return ['success' => true];
    }

    // =========================================================================
    // API Routing Methods (ApiRoutableInterface)
    // =========================================================================

    public function routeGet(array $fragments, array $params): JsonResponse
    {
        $frag1 = $this->frag($fragments, 1);
        $frag2 = $this->frag($fragments, 2);

        if ($frag1 === '') {
            return Response::success($this->formatGetAll());
        }
        if ($frag1 === 'definitions') {
            return Response::success($this->formatGetDefinitions());
        }
        if ($frag1 === 'with-texts') {
            return Response::success($this->formatLanguagesWithTexts());
        }
        if ($frag1 === 'with-archived-texts') {
            return Response::success($this->formatLanguagesWithArchivedTexts());
        }
        if (!ctype_digit($frag1)) {
            return Response::error('Expected Language ID, "definitions", "with-texts", or "with-archived-texts"', 404);
        }

        $langId = (int) $frag1;

        if ($frag2 === 'stats') {
            return Response::success($this->formatGetStats($langId));
        }
        if ($frag2 === 'reading-configuration') {
            return Response::success($this->formatReadingConfiguration($langId));
        }
        if ($frag2 === '') {
            $result = $this->formatGetOne($langId);
            if ($result === null) {
                return Response::error('Language not found', 404);
            }
            return Response::success($result);
        }

        return Response::error('Expected "reading-configuration", "stats", or no sub-path', 404);
    }

    public function routePost(array $fragments, array $params): JsonResponse
    {
        if (!isset($fragments[1]) || $fragments[1] === '') {
            return Response::success($this->formatCreate($params));
        }

        if (ctype_digit($fragments[1])) {
            $langId = (int)$fragments[1];

            if (($fragments[2] ?? '') === 'refresh') {
                return Response::success($this->formatRefresh($langId));
            }
            if (($fragments[2] ?? '') === 'set-default') {
                return Response::success($this->formatSetDefault($langId));
            }

            return Response::error('Expected "refresh" or "set-default"', 404);
        }

        return Response::error('Language ID (Integer) Expected', 404);
    }

    public function routePut(array $fragments, array $params): JsonResponse
    {
        $frag1 = $this->frag($fragments, 1);
        $frag2 = $this->frag($fragments, 2);

        if ($frag1 === '' || !ctype_digit($frag1)) {
            return Response::error('Language ID (Integer) Expected', 404);
        }

        $langId = (int) $frag1;

        if ($frag2 === '') {
            return Response::success($this->formatUpdate($langId, $params));
        }

        return Response::error('Unexpected sub-path for PUT /languages/{id}', 404);
    }

    public function routeDelete(array $fragments, array $params): JsonResponse
    {
        $frag1 = $this->frag($fragments, 1);

        if ($frag1 === '' || !ctype_digit($frag1)) {
            return Response::error('Language ID (Integer) Expected', 404);
        }

        $langId = (int) $frag1;
        return Response::success($this->formatDelete($langId));
    }
}
