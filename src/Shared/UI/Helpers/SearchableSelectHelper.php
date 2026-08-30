<?php

/**
 * \file
 * \brief Helper for rendering searchable select components.
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

/**
 * Helper class for rendering searchable select components.
 *
 * Provides methods for building Alpine.js searchable select dropdowns
 * that can filter options as the user types.
 *
 * @since 3.0.0
 */
class SearchableSelectHelper
{
    /**
     * Render a searchable select component for languages.
     *
     * @param array<int, array{id: int|string, name: string}> $languages Language options
     * @param int|string|null                                 $selected  Selected language ID
     * @param array{
     *     name: string,
     *     id: string,
     *     placeholder?: string,
     *     required?: bool,
     *     dataAction?: string,
     *     dataAjax?: bool,
     *     dataRedirect?: string,
     *     size?: string,
     *     class?: string
     * } $options Configuration options
     *
     * @return string HTML for the searchable select component
     */
    public static function forLanguages(
        array $languages,
        int|string|null $selected,
        array $options
    ): string {
        $items = [];

        // Add empty option for placeholder
        $items[] = ['value' => '', 'label' => $options['placeholder'] ?? '[Choose...]'];

        foreach ($languages as $lang) {
            $items[] = [
                'value' => (string)$lang['id'],
                'label' => $lang['name']
            ];
        }

        return self::render($items, (string)($selected ?? ''), $options);
    }

    /**
     * Render a generic searchable select component.
     *
     * @param array<int, array{value: string, label: string}> $items    Option items
     * @param string                                          $selected Selected value
     * @param array{
     *     name: string,
     *     id: string,
     *     placeholder?: string,
     *     required?: bool,
     *     dataAction?: string,
     *     dataAjax?: bool,
     *     dataRedirect?: string,
     *     size?: string,
     *     class?: string
     * } $options Configuration options
     *
     * @return string HTML for the searchable select component
     */
    public static function render(
        array $items,
        string $selected,
        array $options
    ): string {
        $config = self::buildConfig($items, $selected, $options);
        // Escape for HTML attribute context
        $configEscaped = htmlspecialchars($config, ENT_QUOTES, 'UTF-8');
        $sizeClass = isset($options['size']) ? ' is-' . $options['size'] : '';
        $extraClass = isset($options['class']) ? ' ' . $options['class'] : '';

        $html = '<div x-data="searchableSelect(' . $configEscaped . ')"' . "\n";
        $html .= '     x-init="init()"' . "\n";
        $html .= '     class="searchable-select' . $sizeClass . $extraClass . '"' . "\n";
        $html .= '     @click.outside="close()"' . "\n";
        $html .= '     @keydown.escape.window="close()">' . "\n";

        // Hidden input for form submission (use static attributes for JS compatibility)
        $html .= '    <input type="hidden"' . "\n";
        $html .= '           name="' . htmlspecialchars($options['name'], ENT_QUOTES, 'UTF-8') . '"' . "\n";
        $html .= '           id="' . htmlspecialchars($options['id'], ENT_QUOTES, 'UTF-8') . '"' . "\n";
        $html .= '           :value="selectedValue"' . "\n";
        $html .= '           ' . ($options['required'] ?? false ? 'required' : '') . '>' . "\n";

        // Trigger button
        $html .= '    <button type="button"' . "\n";
        $html .= '            class="searchable-select__trigger"' . "\n";
        $html .= '            @click="toggle()"' . "\n";
        $html .= '            @keydown="handleKeydown($event)"' . "\n";
        $html .= '            :aria-expanded="isOpen"' . "\n";
        $html .= '            aria-haspopup="listbox"' . "\n";
        $html .= '            x-ref="trigger">' . "\n";
        $html .= '        <span x-text="selectedLabel || placeholder"' . "\n";
        $html .= '              :class="{\'has-text-grey\': !selectedValue}"></span>' . "\n";
        $html .= '        <span class="icon is-small">' . "\n";
        $html .= '            ' . IconHelper::render('chevron-down', [
            'class' => 'transition-transform',
            'x-bind:class' => "{'rotate-180': isOpen}"
        ]) . "\n";
        $html .= '        </span>' . "\n";
        $html .= '    </button>' . "\n";

        // Dropdown panel
        $html .= '    <div x-show="isOpen"' . "\n";
        $html .= '         x-transition:enter="dropdown-enter"' . "\n";
        $html .= '         x-transition:enter-start="dropdown-enter-start"' . "\n";
        $html .= '         x-transition:enter-end="dropdown-enter-end"' . "\n";
        $html .= '         x-transition:leave="dropdown-leave"' . "\n";
        $html .= '         x-transition:leave-start="dropdown-leave-start"' . "\n";
        $html .= '         x-transition:leave-end="dropdown-leave-end"' . "\n";
        $html .= '         x-cloak' . "\n";
        $html .= '         class="searchable-select__dropdown"' . "\n";
        $html .= '         role="listbox">' . "\n";

        // Search input
        $html .= '        <div class="searchable-select__search">' . "\n";
        $html .= '            <input type="text"' . "\n";
        $html .= '                   class="input"' . "\n";
        $html .= '                   placeholder="Search..."' . "\n";
        $html .= '                   x-model="searchQuery"' . "\n";
        $html .= '                   x-ref="searchInput"' . "\n";
        $html .= '                   @keydown="handleKeydown($event)"' . "\n";
        $html .= '                   aria-label="Search options">' . "\n";
        $html .= '        </div>' . "\n";

        // Options list
        $html .= '        <ul class="searchable-select__options">' . "\n";
        $html .= '            <template x-for="(option, index) in filteredOptions" :key="option.value">' . "\n";
        $html .= '                <li @click="selectOption(option)"' . "\n";
        $html .= '                    @mouseenter="highlightedIndex = index"' . "\n";
        $html .= '                    :class="{' . "\n";
        $html .= '                        \'is-highlighted\': highlightedIndex === index,' . "\n";
        $html .= '                        \'is-selected\': selectedValue === option.value' . "\n";
        $html .= '                    }"' . "\n";
        $html .= '                    :aria-selected="selectedValue === option.value"' . "\n";
        $html .= '                    role="option">' . "\n";
        $html .= '                    <span x-text="option.label"></span>' . "\n";
        $html .= '                    <span x-show="selectedValue === option.value" class="icon is-small">' . "\n";
        $html .= '                        ' . IconHelper::render('check') . "\n";
        $html .= '                    </span>' . "\n";
        $html .= '                </li>' . "\n";
        $html .= '            </template>' . "\n";
        $html .= '            <li x-show="filteredOptions.length === 0" class="searchable-select__empty">' . "\n";
        $html .= '                No options found' . "\n";
        $html .= '            </li>' . "\n";
        $html .= '        </ul>' . "\n";

        $html .= '    </div>' . "\n";
        $html .= '</div>' . "\n";

        return $html;
    }

    /**
     * Build the Alpine.js x-data config JSON.
     *
     * @param array<int, array{value: string, label: string}> $items    Option items
     * @param string                                          $selected Selected value
     * @param array{
     *     name: string,
     *     id: string,
     *     placeholder?: string,
     *     required?: bool,
     *     dataAction?: string,
     *     dataAjax?: bool,
     *     dataRedirect?: string,
     *     size?: string,
     *     class?: string
     * } $options Configuration options
     *
     * @return string JSON config string
     */
    private static function buildConfig(
        array $items,
        string $selected,
        array $options
    ): string {
        $config = [
            'options' => $items,
            'selectedValue' => $selected,
            'placeholder' => $options['placeholder'] ?? '[Choose...]',
            'name' => $options['name'],
            'id' => $options['id'],
            'required' => $options['required'] ?? false,
        ];

        if (isset($options['dataAction'])) {
            $config['dataAction'] = $options['dataAction'];
        }

        if (isset($options['dataAjax'])) {
            $config['dataAjax'] = $options['dataAjax'];
        }

        if (isset($options['dataRedirect'])) {
            $config['dataRedirect'] = $options['dataRedirect'];
        }

        $json = json_encode($config, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
        // json_encode should not fail with simple arrays, but handle it anyway
        return $json !== false ? $json : '{}';
    }
}
