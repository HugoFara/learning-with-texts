<?php

/**
 * Update Language Use Case
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Language\Application\UseCases
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Language\Application\UseCases;

use Lwt\Shared\Infrastructure\Http\InputValidator;
use Lwt\Shared\Infrastructure\Database\QueryBuilder;

/**
 * Use case for updating an existing language.
 *
 * @since 3.0.0
 */
class UpdateLanguage
{
    private ReparseLanguageTexts $reparseUseCase;

    /**
     * @param ReparseLanguageTexts|null $reparseUseCase Reparse use case
     */
    public function __construct(?ReparseLanguageTexts $reparseUseCase = null)
    {
        $this->reparseUseCase = $reparseUseCase ?? new ReparseLanguageTexts();
    }

    /**
     * Update an existing language from request data.
     *
     * @param int $id Language ID
     *
     * @return array{success: bool, reparsed: ?int, error: ?string}
     */
    public function execute(int $id): array
    {
        $data = $this->getLanguageDataFromRequest();

        // Get old values for comparison
        $records = QueryBuilder::table('languages')
            ->where('LgID', '=', $id)
            ->getPrepared();
        if (empty($records)) {
            return ['success' => false, 'reparsed' => null, 'error' => 'Cannot access language data'];
        }
        $record = $records[0];

        // Check if reparsing is needed
        $needReParse = $this->needsReparsing($data, $record);

        // Update language
        $this->buildLanguageSql($data, $id);

        $reparseCount = null;
        if ($needReParse) {
            $reparseCount = $this->reparseUseCase->reparseTexts($id);
        }

        return ['success' => true, 'reparsed' => $reparseCount, 'error' => null];
    }

    /**
     * Update an existing language from data array (API-friendly version).
     *
     * @param int   $id   Language ID
     * @param array<string, mixed> $data Language data (camelCase keys)
     *
     * @return array{success: bool, reparsed: int, message: string}
     */
    public function updateFromData(int $id, array $data): array
    {
        $normalizedData = $this->normalizeLanguageData($data);

        // Get old values for comparison
        $records = QueryBuilder::table('languages')
            ->where('LgID', '=', $id)
            ->getPrepared();

        if (empty($records)) {
            return ['success' => false, 'reparsed' => 0, 'message' => __('language.errors.language_not_found')];
        }

        $record = $records[0];

        // Check if reparsing is needed
        $needReParse = $this->needsReparsing($normalizedData, $record);

        // Update language
        $this->buildLanguageSql($normalizedData, $id);

        $reparsedCount = 0;
        if ($needReParse) {
            $reparsedCount = $this->reparseUseCase->reparseTexts($id);
        }

        return [
            'success' => true,
            'reparsed' => $reparsedCount,
            'message' => $needReParse ? 'Updated and reparsed' : 'Updated'
        ];
    }

    /**
     * Get language data from request using InputValidator.
     *
     * @return array<string, string|int|bool|null>
     */
    private function getLanguageDataFromRequest(): array
    {
        return [
            'LgName' => InputValidator::getString('LgName'),
            'LgDict1URI' => InputValidator::getString('LgDict1URI'),
            'LgDict2URI' => InputValidator::getString('LgDict2URI'),
            'LgGoogleTranslateURI' => InputValidator::getString('LgGoogleTranslateURI'),
            'LgDict1PopUp' => InputValidator::has('LgDict1PopUp'),
            'LgDict2PopUp' => InputValidator::has('LgDict2PopUp'),
            'LgGoogleTranslatePopUp' => InputValidator::has('LgGoogleTranslatePopUp'),
            'LgSourceLang' => InputValidator::getString('LgSourceLang') ?: null,
            'LgTargetLang' => InputValidator::getString('LgTargetLang') ?: null,
            'LgExportTemplate' => InputValidator::getString('LgExportTemplate'),
            'LgTextSize' => InputValidator::getString('LgTextSize', '100'),
            'LgCharacterSubstitutions' => InputValidator::getString('LgCharacterSubstitutions', '', false),
            'LgRegexpSplitSentences' => InputValidator::getString('LgRegexpSplitSentences'),
            'LgExceptionsSplitSentences' => InputValidator::getString('LgExceptionsSplitSentences', '', false),
            'LgRegexpWordCharacters' => InputValidator::getString('LgRegexpWordCharacters'),
            'LgParserType' => InputValidator::getString('LgParserType') ?: null,
            'LgRemoveSpaces' => InputValidator::has('LgRemoveSpaces'),
            'LgSplitEachChar' => InputValidator::has('LgSplitEachChar'),
            'LgRightToLeft' => InputValidator::has('LgRightToLeft'),
            'LgTTSVoiceAPI' => InputValidator::getString('LgTTSVoiceAPI'),
            'LgShowRomanization' => InputValidator::has('LgShowRomanization'),
            'LgLocalDictMode' => (int) InputValidator::getString('LgLocalDictMode', '0'),
        ];
    }

    /**
     * Normalize language data from API request to database fields.
     *
     * @param array<string, mixed> $data API request data (camelCase keys)
     *
     * @return array<string, bool|int|null|string> Normalized data (LgXxx keys)
     */
    private function normalizeLanguageData(array $data): array
    {
        $getStr = static fn(string $key, string $default = ''): string =>
            isset($data[$key]) && is_string($data[$key]) ? $data[$key] : $default;

        $getStrOrNull = static fn(string $key): ?string =>
            isset($data[$key]) && is_string($data[$key]) ? $data[$key] : null;

        return [
            'LgName' => $getStr('name'),
            'LgDict1URI' => $getStr('dict1Uri'),
            'LgDict2URI' => $getStr('dict2Uri'),
            'LgGoogleTranslateURI' => $getStr('translatorUri'),
            'LgDict1PopUp' => !empty($data['dict1PopUp']),
            'LgDict2PopUp' => !empty($data['dict2PopUp']),
            'LgGoogleTranslatePopUp' => !empty($data['translatorPopUp']),
            'LgSourceLang' => $getStrOrNull('sourceLang'),
            'LgTargetLang' => $getStrOrNull('targetLang'),
            'LgExportTemplate' => $getStr('exportTemplate'),
            'LgTextSize' => (string)($data['textSize'] ?? '100'),
            'LgCharacterSubstitutions' => $getStr('characterSubstitutions'),
            'LgRegexpSplitSentences' => $getStr('regexpSplitSentences', '.!?'),
            'LgExceptionsSplitSentences' => $getStr('exceptionsSplitSentences'),
            'LgRegexpWordCharacters' => $getStr('regexpWordCharacters', 'a-zA-Z'),
            'LgParserType' => $getStrOrNull('parserType'),
            'LgRemoveSpaces' => !empty($data['removeSpaces']),
            'LgSplitEachChar' => !empty($data['splitEachChar']),
            'LgRightToLeft' => !empty($data['rightToLeft']),
            'LgTTSVoiceAPI' => $getStr('ttsVoiceApi'),
            'LgShowRomanization' => !empty($data['showRomanization']),
            'LgLocalDictMode' => (int)($data['localDictMode'] ?? 0),
        ];
    }

    /**
     * Check if language changes require reparsing texts.
     *
     * @param array<string, string|int|bool|null> $newData   New language data
     * @param array<string, mixed> $oldRecord Old language data
     *
     * @return bool
     */
    private function needsReparsing(array $newData, array $oldRecord): bool
    {
        $getStr = static fn(array $arr, string $key): string =>
            isset($arr[$key]) && is_string($arr[$key]) ? $arr[$key] : '';

        return (
            $getStr($newData, "LgCharacterSubstitutions")
            != $getStr($oldRecord, 'LgCharacterSubstitutions')
        ) || (
            trim($getStr($newData, "LgRegexpSplitSentences")) !=
            trim($getStr($oldRecord, 'LgRegexpSplitSentences'))
        ) || (
            $getStr($newData, "LgExceptionsSplitSentences")
            != $getStr($oldRecord, 'LgExceptionsSplitSentences')
        ) || (
            trim($getStr($newData, "LgRegexpWordCharacters")) !=
            trim($getStr($oldRecord, 'LgRegexpWordCharacters'))
        ) || ((int)($newData["LgRemoveSpaces"] ?? 0) != (int)($oldRecord['LgRemoveSpaces'] ?? 0)) ||
        ((int)($newData["LgSplitEachChar"] ?? 0) != (int)($oldRecord['LgSplitEachChar'] ?? 0)) ||
        (($newData["LgParserType"] ?? null) != ($oldRecord['LgParserType'] ?? null));
    }

    /**
     * Convert empty strings to null.
     *
     * @param string|null $value Value to convert
     *
     * @return string|null Trimmed value or null if empty
     */
    /**
     * Trim a value without turning an empty one into null.
     *
     * Companion to {@see emptyToNull()} for columns declared NOT NULL, where
     * an empty string is a legitimate value and null is a fatal one.
     *
     * @param string $value Raw value
     *
     * @return string Trimmed value
     */
    private function trimmed(string $value): string
    {
        return trim($value);
    }

    private function emptyToNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Get a string value from data array, defaulting to empty string.
     *
     * @param array<string, string|int|bool|null> $data Data array
     * @param string $key Key to retrieve
     *
     * @return string Value as string
     */
    private function getString(array $data, string $key): string
    {
        $value = $data[$key] ?? '';
        return is_string($value) ? $value : (string)$value;
    }

    /**
     * Get a string or null value from data array.
     *
     * @param array<string, string|int|bool|null> $data Data array
     * @param string $key Key to retrieve
     *
     * @return string|null Value as string or null
     */
    private function getStringOrNull(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;
        if ($value === null) {
            return null;
        }
        return is_string($value) ? $value : (string)$value;
    }

    /**
     * Build SQL and execute update for a language.
     *
     * @param array<string, string|int|bool|null> $data Language data
     * @param int   $id   Language ID
     *
     * @return void
     */
    private function buildLanguageSql(array $data, int $id): void
    {
        $columns = [
            'LgName', 'LgDict1URI', 'LgDict2URI', 'LgGoogleTranslateURI',
            'LgDict1PopUp', 'LgDict2PopUp', 'LgGoogleTranslatePopUp',
            'LgSourceLang', 'LgTargetLang',
            'LgExportTemplate', 'LgTextSize', 'LgCharacterSubstitutions',
            'LgRegexpSplitSentences', 'LgExceptionsSplitSentences',
            'LgRegexpWordCharacters', 'LgParserType', 'LgRemoveSpaces', 'LgSplitEachChar',
            'LgRightToLeft', 'LgTTSVoiceAPI', 'LgShowRomanization', 'LgLocalDictMode'
        ];

        $params = [
            $this->emptyToNull($this->getString($data, "LgName")),
            // NOT NULL with no default: nulling an empty value here is what
            // made POST /languages {"name":"X"} — the minimal payload
            // formatCreate() advertises — fail with a database error.
            $this->trimmed($this->getString($data, "LgDict1URI")),
            $this->emptyToNull($this->getString($data, "LgDict2URI")),
            $this->emptyToNull($this->getString($data, "LgGoogleTranslateURI")),
            (int)($data["LgDict1PopUp"] ?? false),
            (int)($data["LgDict2PopUp"] ?? false),
            (int)($data["LgGoogleTranslatePopUp"] ?? false),
            $this->emptyToNull($this->getStringOrNull($data, "LgSourceLang")),
            $this->emptyToNull($this->getStringOrNull($data, "LgTargetLang")),
            $this->emptyToNull($this->getString($data, "LgExportTemplate")),
            $this->trimmed($this->getString($data, "LgTextSize")),
            $this->getString($data, "LgCharacterSubstitutions"),
            $this->trimmed($this->getString($data, "LgRegexpSplitSentences")),
            $this->getString($data, "LgExceptionsSplitSentences"),
            $this->trimmed($this->getString($data, "LgRegexpWordCharacters")),
            $this->getStringOrNull($data, "LgParserType"),
            (int)($data["LgRemoveSpaces"] ?? false),
            (int)($data["LgSplitEachChar"] ?? false),
            (int)($data["LgRightToLeft"] ?? false),
            $this->getString($data, "LgTTSVoiceAPI"),
            (int)($data["LgShowRomanization"] ?? false),
            (int)($data["LgLocalDictMode"] ?? 0),
        ];

        $updateData = array_combine($columns, $params);

        QueryBuilder::table('languages')
            ->where('LgID', '=', $id)
            ->updatePrepared($updateData);
    }
}
