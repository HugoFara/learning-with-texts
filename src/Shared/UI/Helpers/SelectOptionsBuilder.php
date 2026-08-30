<?php

/**
 * \file
 * \brief Builder for HTML select option elements.
 *
 * PHP version 8.2
 *
 * @category View
 * @package  Lwt
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Shared\UI\Helpers;

use Lwt\Modules\Vocabulary\Application\Helpers\StatusHelper;

/**
 * Builder class for generating HTML select options.
 *
 * Provides methods for building various types of select option lists
 * used throughout the application.
 *
 * @since 3.0.0
 */
class SelectOptionsBuilder
{
    /**
     * Build seconds selection options (1-10 seconds).
     *
     * @param int|string|null $selected Currently selected value (default: 5)
     *
     * @return string HTML options string
     */
    public static function forSeconds(int|string|null $selected = null): string
    {
        $selected = $selected ?? 5;
        $result = '';
        for ($i = 1; $i <= 10; $i++) {
            $result .= FormHelper::buildOption($i, $i . ' sec', $selected);
        }
        return $result;
    }

    /**
     * Build playback rate selection options (0.5x to 1.5x).
     *
     * @param int|string|null $selected Currently selected value (default: '10' = 1.0x)
     *
     * @return string HTML options string
     */
    public static function forPlaybackRate(int|string|null $selected = null): string
    {
        $selected = $selected ?? '10';
        $result = '';
        for ($i = 5; $i <= 15; $i++) {
            $text = $i < 10 ? ' 0.' . $i . ' x ' : ' 1.' . ($i - 10) . ' x ';
            $result .= '<option value="' . $i . '"' . FormHelper::getSelected($selected, $i);
            $result .= '>&nbsp;' . $text . '&nbsp;</option>';
        }
        return $result;
    }

    /**
     * Build sentence count selection options.
     *
     * @param int|string|null $selected Currently selected value (default: 1)
     *
     * @return string HTML options string
     */
    public static function forSentenceCount(int|string|null $selected = null): string
    {
        $selected = $selected ?? 1;
        $options = [
            1 => 'Just ONE',
            2 => 'TWO (+previous)',
            3 => 'THREE (+previous,+next)'
        ];
        return self::buildFromArray($options, $selected);
    }

    /**
     * Build "words to do" button options.
     *
     * @param int|string|null $selected Currently selected value (default: "1")
     *
     * @return string HTML options string
     */
    public static function forWordsToDoButtons(int|string|null $selected = null): string
    {
        $selected = $selected ?? '1';
        $options = [
            '0' => 'I Know All & Ignore All',
            '1' => 'I Know All',
            '2' => 'Ignore All'
        ];
        return self::buildFromArray($options, $selected);
    }

    /**
     * Build regex mode selection options.
     *
     * @param string|null $selected Currently selected value
     *
     * @return string HTML options string
     */
    public static function forRegexMode(?string $selected = null): string
    {
        $selected = $selected ?? '';
        $options = [
            '' => 'Default',
            'r' => 'RegEx',
            "COLLATE 'utf8_bin' r" => 'RegEx CaseSensitive'
        ];
        return self::buildFromArray($options, $selected);
    }

    /**
     * Build tooltip type selection options.
     *
     * Note: JqueryUI option was removed when jQuery was removed from the codebase.
     * Only native tooltips are now supported.
     *
     * @param int|string|null $selected Currently selected value (default: 1)
     *
     * @return string HTML options string
     */
    public static function forTooltipType(int|string|null $selected = null): string
    {
        $selected = $selected ?? 1;
        $options = [
            1 => 'Native'
        ];
        return self::buildFromArray($options, $selected);
    }

    /**
     * Build language size selection options (100% to 250%).
     *
     * @param int|string|null $selected Currently selected value (default: 100)
     *
     * @return string HTML options string
     */
    public static function forLanguageSize(int|string|null $selected = null): string
    {
        $selected = $selected ?? 100;
        $options = [
            100 => '100 %',
            150 => '150 %',
            200 => '200 %',
            250 => '250 %'
        ];
        return self::buildFromArray($options, $selected);
    }

    /**
     * Build annotation position selection options.
     *
     * @param int|string|null $selected Currently selected value (default: 1)
     *
     * @return string HTML options string
     */
    public static function forAnnotationPosition(int|string|null $selected = null): string
    {
        $selected = $selected ?? 1;
        $options = [
            1 => 'Behind',
            3 => 'In Front Of',
            2 => 'Below',
            4 => 'Above'
        ];
        return self::buildFromArray($options, $selected);
    }

    /**
     * Build hover/click translation settings options.
     *
     * @param int|string|null $selected Currently selected value (default: 1)
     *
     * @return string HTML options string
     */
    public static function forHoverTranslation(int|string|null $selected = null): string
    {
        $selected = $selected ?? 1;
        $options = [
            1 => 'Never',
            2 => 'On Click',
            3 => 'On Hover'
        ];
        return self::buildFromArray($options, $selected);
    }

    /**
     * Build pagination options.
     *
     * @param int $currentPage Current page number
     * @param int $totalPages  Total number of pages
     *
     * @return string HTML options string
     */
    public static function forPagination(int $currentPage, int $totalPages): string
    {
        $result = '';
        for ($i = 1; $i <= $totalPages; $i++) {
            $result .= FormHelper::buildOption($i, (string)$i, $currentPage);
        }
        return $result;
    }

    /**
     * Build word sorting options.
     *
     * Note: The original code has duplicate option values (4 and 7 both map to
     * "Oldest first" in different contexts). This method preserves that behavior.
     *
     * @param int|string|null $selected Currently selected value (default: 1)
     *
     * @return string HTML options string
     */
    public static function forWordSort(int|string|null $selected = null): string
    {
        $selected = $selected ?? 1;
        // Using manual string building to preserve original order with duplicate-looking values
        $result = '';
        $result .= FormHelper::buildOption(1, 'Term A-Z', $selected);
        $result .= FormHelper::buildOption(2, 'Translation A-Z', $selected);
        $result .= FormHelper::buildOption(3, 'Newest first', $selected);
        $result .= FormHelper::buildOption(7, 'Oldest first', $selected);
        $result .= FormHelper::buildOption(4, 'Oldest first', $selected);
        $result .= FormHelper::buildOption(5, 'Status', $selected);
        $result .= FormHelper::buildOption(6, 'Score Value (%)', $selected);
        $result .= FormHelper::buildOption(7, 'Word Count Active Texts', $selected);
        return $result;
    }

    /**
     * Build tag sorting options.
     *
     * @param int|string|null $selected Currently selected value (default: 1)
     *
     * @return string HTML options string
     */
    public static function forTagSort(int|string|null $selected = null): string
    {
        $selected = $selected ?? 1;
        $options = [
            1 => 'Tag Text A-Z',
            2 => 'Tag Comment A-Z',
            3 => 'Newest first',
            4 => 'Oldest first'
        ];
        return self::buildFromArray($options, $selected);
    }

    /**
     * Build text sorting options.
     *
     * @param int|string|null $selected Currently selected value (default: 1)
     *
     * @return string HTML options string
     */
    public static function forTextSort(int|string|null $selected = null): string
    {
        $selected = $selected ?? 1;
        $options = [
            1 => 'Title A-Z',
            2 => 'Newest first',
            3 => 'Oldest first'
        ];
        return self::buildFromArray($options, $selected);
    }

    /**
     * Build AND/OR logical operator options.
     *
     * @param int|string|null $selected Currently selected value (default: 0)
     *
     * @return string HTML options string
     */
    public static function forAndOr(int|string|null $selected = null): string
    {
        $selected = $selected ?? 0;
        $options = [
            0 => '... OR ...',
            1 => '... AND ...'
        ];
        return self::buildFromArray($options, $selected);
    }

    /**
     * Build interface language (app locale) options.
     *
     * @param string[]    $locales  Available locale codes (e.g. ["en", "es"])
     * @param string|null $selected Currently selected locale
     *
     * @return string HTML options string
     */
    public static function forAppLanguages(array $locales, ?string $selected = null): string
    {
        $names = [
            'en' => 'English',
            'es' => 'Español',
            'fr' => 'Français',
            'de' => 'Deutsch',
            'it' => 'Italiano',
            'pt' => 'Português',
            'zh' => '中文',
            'ja' => '日本語',
            'ko' => '한국어',
            'ru' => 'Русский',
            'ar' => 'العربية',
        ];
        $options = [];
        foreach ($locales as $code) {
            $options[$code] = $names[$code] ?? $code;
        }
        if ($options === []) {
            $options['en'] = 'English';
        }
        return self::buildFromArray($options, $selected);
    }

    /**
     * Build options from an associative array.
     *
     * @param array<int|string, string> $options  Array of value => label pairs
     * @param int|string|null           $selected Currently selected value
     *
     * @return string HTML options string
     */
    public static function buildFromArray(array $options, int|string|null $selected = null): string
    {
        $result = '';
        foreach ($options as $value => $label) {
            $result .= FormHelper::buildOption($value, $label, $selected);
        }
        return $result;
    }

    /**
     * Build a filter-off option for select elements.
     *
     * @param int|string|null $selected Currently selected value
     *
     * @return string HTML option element
     */
    public static function buildFilterOffOption(int|string|null $selected = null): string
    {
        return FormHelper::buildOption('', '[Filter off]', $selected);
    }

    /**
     * Build a choose prompt option for select elements.
     *
     * @return string HTML option element
     */
    public static function buildChooseOption(): string
    {
        return '<option value="" selected="selected">[Choose...]</option>';
    }

    // =========================================================================
    // Methods migrated from Core/UI/ui_helpers.php
    // =========================================================================

    /**
     * Build language select options from data array.
     *
     * @param array<int, array{id: int, name: string}> $languages   Language data from LanguageFacade
     * @param int|string|null                          $selected    Selected language ID
     * @param string                                   $defaultText Default option text
     *
     * @return string HTML options string
     */
    public static function forLanguages(
        array $languages,
        int|string|null $selected,
        string $defaultText = '[Filter off]'
    ): string {
        $isSelected = !isset($selected) || trim((string)$selected) === '';
        $result = '<option value=""' . ($isSelected ? ' selected="selected"' : '') . '>'
                . htmlspecialchars($defaultText, ENT_QUOTES, 'UTF-8') . '</option>';
        foreach ($languages as $lang) {
            $result .= FormHelper::buildOption(
                $lang['id'],
                htmlspecialchars($lang['name'], ENT_QUOTES, 'UTF-8'),
                $selected
            );
        }
        return $result;
    }

    /**
     * Build text select options from data array.
     *
     * @param array<int, array{id: int, title: string, language: string}> $texts        Text data from TextService
     * @param int|string|null                                              $selected     Selected text ID
     * @param bool                                                         $showLanguage
     *        Whether to prefix with language name
     *
     * @return string HTML options string
     */
    public static function forTexts(
        array $texts,
        int|string|null $selected,
        bool $showLanguage = true
    ): string {
        $result = self::buildFilterOffOption($selected);
        foreach ($texts as $text) {
            $label = $showLanguage
                ? ($text['language'] . ': ' . $text['title'])
                : $text['title'];
            $result .= FormHelper::buildOption(
                $text['id'],
                htmlspecialchars($label, ENT_QUOTES, 'UTF-8'),
                $selected
            );
        }
        return $result;
    }

    /**
     * Build theme select options from data array.
     *
     * @param array<int, array{
     *     path: string,
     *     name: string,
     *     description?: string,
     *     mode?: string,
     *     highlighting?: string,
     *     wordBreaking?: string
     * }> $themes   Theme data from ThemeService
     * @param string|null $selected Selected theme path
     *
     * @return string HTML options string
     */
    public static function forThemes(array $themes, ?string $selected): string
    {
        $result = '';
        foreach ($themes as $theme) {
            $modeIndicator = '';
            if (isset($theme['mode'])) {
                $modeIndicator = $theme['mode'] === 'dark' ? ' [Dark]' : ' [Light]';
            }
            $result .= '<option value="' . htmlspecialchars($theme['path'], ENT_QUOTES, 'UTF-8') . '"'
                    . FormHelper::getSelected($selected, $theme['path'])
                    . ' data-description="' . htmlspecialchars($theme['description'] ?? '', ENT_QUOTES, 'UTF-8') . '"'
                    . ' data-mode="' . htmlspecialchars($theme['mode'] ?? 'light', ENT_QUOTES, 'UTF-8') . '"'
                    . ' data-highlighting="' . htmlspecialchars($theme['highlighting'] ?? '', ENT_QUOTES, 'UTF-8') . '"'
                    . ' data-word-breaking="'
                    . htmlspecialchars($theme['wordBreaking'] ?? '', ENT_QUOTES, 'UTF-8') . '"'
                    . '>' . htmlspecialchars($theme['name'], ENT_QUOTES, 'UTF-8')
                    . htmlspecialchars($modeIndicator, ENT_QUOTES, 'UTF-8') . '</option>';
        }
        return $result;
    }

    /**
     * Build word status radio options.
     *
     * @param int|string|null $selected      Currently selected status (default: 1)
     * @param bool            $useFullLabels Show full names instead of abbreviations
     *
     * @return string HTML radio options string
     */
    public static function forWordStatusRadio(
        int|string|null $selected = null,
        bool $useFullLabels = false
    ): string {
        if (!isset($selected)) {
            $selected = 1;
        }
        $result = '';
        $statuses = \Lwt\Modules\Vocabulary\Application\Services\TermStatusService::getStatuses();
        foreach ($statuses as $n => $status) {
            $escapedName = htmlspecialchars($status['name'], ENT_QUOTES, 'UTF-8');
            $escapedAbbr = htmlspecialchars($status['abbr'], ENT_QUOTES, 'UTF-8');
            if ($useFullLabels) {
                // For numeric statuses (1-5), show "1 - Learning" to distinguish levels
                $label = ($n <= 5) ? $escapedAbbr . ' - ' . $escapedName : $escapedName;
                $title = $escapedName;
            } elseif ($escapedAbbr === '') {
                // 98/99: no language-neutral abbreviation — show the full name
                $label = $escapedName;
                $title = $escapedName;
            } else {
                $label = $escapedAbbr;
                $title = $escapedName;
            }
            $result .= '<span class="status' . $n . '" title="' . $title . '">';
            $result .= '&nbsp;<input type="radio" name="WoStatus" value="' . $n . '"';
            if ($selected == $n) {
                $result .= ' checked="checked"';
            }
            $result .= ' />' . $label . '&nbsp;</span> ';
        }
        return $result;
    }

    /**
     * Build word status select options with optional ranges.
     *
     * @param int|string|null $selected  Currently selected status
     * @param bool            $all       Include "Filter off" and ranges
     * @param bool            $not9899   Exclude statuses 98 and 99
     * @param bool            $off       Include "Filter off" option (when $all is true)
     *
     * @return string HTML options string
     */
    public static function forWordStatus(
        int|string|null $selected,
        bool $all,
        bool $not9899,
        bool $off = true
    ): string {
        if (!isset($selected)) {
            $selected = $all ? '' : 1;
        }
        $result = '';
        if ($all && $off) {
            $result .= '<option value=""' . FormHelper::getSelected($selected, '')
                    . '>[Filter off]</option>';
        }
        $statuses = \Lwt\Modules\Vocabulary\Application\Services\TermStatusService::getStatuses();
        foreach ($statuses as $n => $status) {
            if ($not9899 && ($n == 98 || $n == 99)) {
                continue;
            }
            $escapedName = htmlspecialchars($status['name'], ENT_QUOTES, 'UTF-8');
            $bracket = '';
            if ($status['abbr'] !== '' && $status['abbr'] !== $status['name']) {
                $bracket = ' [' . htmlspecialchars($status['abbr'], ENT_QUOTES, 'UTF-8') . ']';
            }
            $result .= '<option value="' . $n . '"' . FormHelper::getSelected($selected, $n != 0 ? $n : '0')
                    . '>' . $escapedName . $bracket . '</option>';
        }
        if ($all) {
            $result .= '<option disabled="disabled">--------</option>';
            $s1name = htmlspecialchars($statuses[1]['name'], ENT_QUOTES, 'UTF-8');
            $s1abbr = htmlspecialchars($statuses[1]['abbr'], ENT_QUOTES, 'UTF-8');
            $result .= '<option value="12"' . FormHelper::getSelected($selected, 12)
                    . '>' . $s1name . ' [' . $s1abbr . '..'
                    . htmlspecialchars($statuses[2]['abbr'], ENT_QUOTES, 'UTF-8') . ']</option>';
            $result .= '<option value="13"' . FormHelper::getSelected($selected, 13)
                    . '>' . $s1name . ' [' . $s1abbr . '..'
                    . htmlspecialchars($statuses[3]['abbr'], ENT_QUOTES, 'UTF-8') . ']</option>';
            $result .= '<option value="14"' . FormHelper::getSelected($selected, 14)
                    . '>' . $s1name . ' [' . $s1abbr . '..'
                    . htmlspecialchars($statuses[4]['abbr'], ENT_QUOTES, 'UTF-8') . ']</option>';
            $result .= '<option value="15"' . FormHelper::getSelected($selected, 15)
                    . '>Learning/-ed [' . $s1abbr . '..'
                    . htmlspecialchars($statuses[5]['abbr'], ENT_QUOTES, 'UTF-8') . ']</option>';
            $result .= '<option disabled="disabled">--------</option>';
            $s2name = htmlspecialchars($statuses[2]['name'], ENT_QUOTES, 'UTF-8');
            $s2abbr = htmlspecialchars($statuses[2]['abbr'], ENT_QUOTES, 'UTF-8');
            $result .= '<option value="23"' . FormHelper::getSelected($selected, 23)
                    . '>' . $s2name . ' [' . $s2abbr . '..'
                    . htmlspecialchars($statuses[3]['abbr'], ENT_QUOTES, 'UTF-8') . ']</option>';
            $result .= '<option value="24"' . FormHelper::getSelected($selected, 24)
                    . '>' . $s2name . ' [' . $s2abbr . '..'
                    . htmlspecialchars($statuses[4]['abbr'], ENT_QUOTES, 'UTF-8') . ']</option>';
            $result .= '<option value="25"' . FormHelper::getSelected($selected, 25)
                    . '>Learning/-ed [' . $s2abbr . '..'
                    . htmlspecialchars($statuses[5]['abbr'], ENT_QUOTES, 'UTF-8') . ']</option>';
            $result .= '<option disabled="disabled">--------</option>';
            $s3name = htmlspecialchars($statuses[3]['name'], ENT_QUOTES, 'UTF-8');
            $s3abbr = htmlspecialchars($statuses[3]['abbr'], ENT_QUOTES, 'UTF-8');
            $result .= '<option value="34"' . FormHelper::getSelected($selected, 34)
                    . '>' . $s3name . ' [' . $s3abbr . '..'
                    . htmlspecialchars($statuses[4]['abbr'], ENT_QUOTES, 'UTF-8') . ']</option>';
            $result .= '<option value="35"' . FormHelper::getSelected($selected, 35)
                    . '>Learning/-ed [' . $s3abbr . '..'
                    . htmlspecialchars($statuses[5]['abbr'], ENT_QUOTES, 'UTF-8') . ']</option>';
            $result .= '<option disabled="disabled">--------</option>';
            $result .= '<option value="45"' . FormHelper::getSelected($selected, 45)
                    . '>Learning/-ed [' . htmlspecialchars($statuses[4]['abbr'], ENT_QUOTES, 'UTF-8') . '..'
                    . htmlspecialchars($statuses[5]['abbr'], ENT_QUOTES, 'UTF-8') . ']</option>';
            $result .= '<option disabled="disabled">--------</option>';
            $result .= '<option value="599"' . FormHelper::getSelected($selected, 599)
                    . '>All known [5+'
                    . htmlspecialchars($statuses[99]['name'], ENT_QUOTES, 'UTF-8') . ']</option>';
        }
        return $result;
    }

    /**
     * Build multiple words actions dropdown options.
     *
     * @return string HTML options string
     */
    public static function forMultipleWordsActions(): string
    {
        $result = self::buildChooseOption();
        $result .= '<option disabled="disabled">------------</option>';
        $result .= '<option value="review">Review Marked Terms</option>';
        $result .= '<option disabled="disabled">------------</option>';
        $result .= '<option value="spl1">Increase Status by 1 [+1]</option>';
        $result .= '<option value="smi1">Reduce Status by 1 [-1]</option>';
        $result .= '<option disabled="disabled">------------</option>';
        $result .= StatusHelper::buildSetStatusOption(1, StatusHelper::getName(1), StatusHelper::getAbbr(1));
        $result .= StatusHelper::buildSetStatusOption(5, StatusHelper::getName(5), StatusHelper::getAbbr(5));
        $result .= StatusHelper::buildSetStatusOption(99, StatusHelper::getName(99), StatusHelper::getAbbr(99));
        $result .= StatusHelper::buildSetStatusOption(98, StatusHelper::getName(98), StatusHelper::getAbbr(98));
        $result .= '<option disabled="disabled">------------</option>';
        $result .= '<option value="today">Set Status Date to Today</option>';
        $result .= '<option disabled="disabled">------------</option>';
        $result .= '<option value="lower">Set Marked Terms to Lowercase</option>';
        $result .= '<option value="cap">Capitalize Marked Terms</option>';
        $result .= '<option value="delsent">Delete Sentences of Marked Terms</option>';
        $result .= '<option disabled="disabled">------------</option>';
        $result .= '<option value="addtag">Add Tag</option>';
        $result .= '<option value="deltag">Remove Tag</option>';
        $result .= '<option disabled="disabled">------------</option>';
        $result .= '<option value="exp">Export Marked Terms (Anki)</option>';
        $result .= '<option value="exp2">Export Marked Terms (TSV)</option>';
        $result .= '<option value="exp3">Export Marked Terms (Flexible)</option>';
        $result .= '<option disabled="disabled">------------</option>';
        $result .= '<option value="del">Delete Marked Terms</option>';
        return $result;
    }

    /**
     * Build multiple tags actions dropdown options.
     *
     * @return string HTML options string
     */
    public static function forMultipleTagsActions(): string
    {
        $result = self::buildChooseOption();
        $result .= '<option value="del">Delete Marked Tags</option>';
        return $result;
    }

    /**
     * Build all words actions dropdown options.
     *
     * @return string HTML options string
     */
    public static function forAllWordsActions(): string
    {
        $result = self::buildChooseOption();
        $result .= '<option disabled="disabled">------------</option>';
        $result .= '<option value="reviewall">Review ALL Terms</option>';
        $result .= '<option disabled="disabled">------------</option>';
        $result .= '<option value="spl1all">Increase Status by 1 [+1]</option>';
        $result .= '<option value="smi1all">Reduce Status by 1 [-1]</option>';
        $result .= '<option disabled="disabled">------------</option>';
        $result .= StatusHelper::buildSetStatusOption(1, StatusHelper::getName(1), StatusHelper::getAbbr(1), 'all');
        $result .= StatusHelper::buildSetStatusOption(5, StatusHelper::getName(5), StatusHelper::getAbbr(5), 'all');
        $result .= StatusHelper::buildSetStatusOption(99, StatusHelper::getName(99), StatusHelper::getAbbr(99), 'all');
        $result .= StatusHelper::buildSetStatusOption(98, StatusHelper::getName(98), StatusHelper::getAbbr(98), 'all');
        $result .= '<option disabled="disabled">------------</option>';
        $result .= '<option value="todayall">Set Status Date to Today</option>';
        $result .= '<option disabled="disabled">------------</option>';
        $result .= '<option value="lowerall">Set ALL Terms to Lowercase</option>';
        $result .= '<option value="capall">Capitalize ALL Terms</option>';
        $result .= '<option value="delsentall">Delete Sentences of ALL Terms</option>';
        $result .= '<option disabled="disabled">------------</option>';
        $result .= '<option value="addtagall">Add Tag</option>';
        $result .= '<option value="deltagall">Remove Tag</option>';
        $result .= '<option disabled="disabled">------------</option>';
        $result .= '<option value="expall">Export ALL Terms (Anki)</option>';
        $result .= '<option value="expall2">Export ALL Terms (TSV)</option>';
        $result .= '<option value="expall3">Export ALL Terms (Flexible)</option>';
        $result .= '<option disabled="disabled">------------</option>';
        $result .= '<option value="delall">Delete ALL Terms</option>';
        return $result;
    }

    /**
     * Build all tags actions dropdown options.
     *
     * @return string HTML options string
     */
    public static function forAllTagsActions(): string
    {
        $result = self::buildChooseOption();
        $result .= '<option value="delall">Delete ALL Tags</option>';
        return $result;
    }

    /**
     * Build multiple texts actions dropdown options.
     *
     * @return string HTML options string
     */
    public static function forMultipleTextsActions(): string
    {
        $result = self::buildChooseOption();
        $result .= '<option disabled="disabled">------------</option>';
        $result .= '<option value="review">Review Marked Texts</option>';
        $result .= '<option disabled="disabled">------------</option>';
        $result .= '<option value="addtag">Add Tag</option>';
        $result .= '<option value="deltag">Remove Tag</option>';
        $result .= '<option disabled="disabled">------------</option>';
        $result .= '<option value="rebuild">Reparse Texts</option>';
        $result .= '<option value="setsent">Set Term Sentences</option>';
        $result .= '<option value="setactsent">Set Active Term Sentences</option>';
        $result .= '<option disabled="disabled">------------</option>';
        $result .= '<option value="arch">Archive Marked Texts</option>';
        $result .= '<option disabled="disabled">------------</option>';
        $result .= '<option value="del">Delete Marked Texts</option>';
        return $result;
    }

    /**
     * Build multiple archived texts actions dropdown options.
     *
     * @return string HTML options string
     */
    public static function forMultipleArchivedTextsActions(): string
    {
        $result = self::buildChooseOption();
        $result .= '<option disabled="disabled">------------</option>';
        $result .= '<option value="addtag">Add Tag</option>';
        $result .= '<option value="deltag">Remove Tag</option>';
        $result .= '<option disabled="disabled">------------</option>';
        $result .= '<option value="unarch">Unarchive Marked Texts</option>';
        $result .= '<option disabled="disabled">------------</option>';
        $result .= '<option value="del">Delete Marked Texts</option>';
        return $result;
    }
}
