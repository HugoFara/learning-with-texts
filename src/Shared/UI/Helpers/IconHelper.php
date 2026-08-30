<?php

/**
 * \file
 * \brief Helper for rendering Lucide icons.
 *
 * This file provides methods to render modern SVG icons using the Lucide
 * icon library, replacing legacy PNG icons from the Fugue icon set.
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
 * Helper class for rendering Lucide SVG icons.
 *
 * Provides a centralized way to render icons with consistent styling
 * and an easy migration path from legacy PNG icons.
 *
 * @category View
 * @package  Lwt
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */
class IconHelper
{
    /**
     * Mapping from legacy icon names to Lucide icon names.
     *
     * Keys are the old PNG filenames (without extension),
     * values are the Lucide icon names.
     *
     * @var array<string, string>
     */
    private const ICON_MAP = [
        // Navigation & Controls
        'arrow-000-medium' => 'arrow-right',
        'arrow-180-medium' => 'arrow-left',
        'arrow-circle-135' => 'refresh-cw',
        'arrow-circle-225-left' => 'rewind',
        'arrow-circle-315' => 'fast-forward',
        'arrow-repeat' => 'repeat',
        'arrow-norepeat' => 'repeat',  // Use CSS to indicate "off" state
        'arrow-stop' => 'square',
        'control' => 'chevron-right',
        'control-180' => 'chevron-left',
        'control-stop' => 'chevrons-right',
        'control-stop-180' => 'chevrons-left',
        'navigation-000-button' => 'circle-chevron-right',
        'navigation-000-button-light' => 'circle-chevron-right',
        'navigation-180-button' => 'circle-chevron-left',
        'navigation-180-button-light' => 'circle-chevron-left',

        // Actions - CRUD
        'edit' => 'pencil',
        'plus' => 'plus',
        'plus-button' => 'circle-plus',
        'minus' => 'minus',
        'minus-button' => 'circle-minus',
        'cross' => 'x',
        'cross-button' => 'x-circle',
        'cross-big' => 'x',
        'tick' => 'check',
        'tick-button' => 'circle-check',
        'tick-button-small' => 'circle-check',
        'pencil' => 'pencil',
        'document--pencil' => 'file-pen',
        'eraser' => 'eraser',
        'broom' => 'brush',

        // Documents & Text
        'book-open-bookmark' => 'book-open',
        'book-open-text' => 'book-open-text',
        'book--pencil' => 'book-open-check',
        'notebook' => 'notebook',
        'notebook--plus' => 'notebook-pen',
        'notebook--minus' => 'notebook',
        'notebook--pencil' => 'notebook-pen',
        'sticky-note' => 'sticky-note',
        'sticky-note--plus' => 'notepad-text-dashed',
        'sticky-note--minus' => 'sticky-note',
        'sticky-note--pencil' => 'file-pen-line',
        'sticky-note-text' => 'notepad-text',
        'sticky-notes' => 'layers',
        'sticky-notes-stack' => 'layers',
        'sticky-notes-text' => 'file-stack',
        'new_line' => 'wrap-text',
        'script-import' => 'file-down',

        // Cards & Flashcards
        'card--plus' => 'square-plus',
        'card--minus' => 'square-minus',
        'card--pencil' => 'square-pen',
        'cards-stack' => 'layers',

        // Feeds & RSS
        'feed--plus' => 'rss',
        'feed--pencil' => 'rss',

        // Storage & Archive
        'drawer--plus' => 'archive',
        'drawer--minus' => 'archive-x',
        'inbox-download' => 'download',
        'inbox-upload' => 'upload',

        // Status Indicators
        'status' => 'circle-check',
        'status-busy' => 'circle-x',
        'status-away' => 'circle-dot',
        'exclamation-red' => 'circle-alert',
        'exclamation-button' => 'alert-circle',

        // Form Validation
        'circle-x' => 'circle-x',
        'asterisk' => 'asterisk',
        'required' => 'asterisk',

        // Test Results
        'test_correct' => 'circle-check',
        'test_wrong' => 'circle-x',
        'test_notyet' => 'circle-help',
        'smiley' => 'smile',
        'smiley-sad' => 'frown',
        'thumb' => 'thumbs-up',
        'thumb-up' => 'thumbs-up',

        // UI Elements
        'funnel' => 'filter',
        'funnel--minus' => 'filter-x',
        'lightning' => 'zap',
        'wizard' => 'wand-2',
        'wrench-screwdriver' => 'settings',
        'calculator' => 'calculator',
        'clock' => 'clock',
        'chain' => 'link',
        'external' => 'external-link',
        'printer' => 'printer',
        'eye' => 'eye',
        'star' => 'star',
        'photo-album' => 'image',

        // Light Bulb (Show/Hide Translations)
        'light-bulb' => 'lightbulb',
        'light-bulb-off' => 'lightbulb-off',
        'light-bulb-A' => 'lightbulb',
        'light-bulb-off-A' => 'lightbulb-off',
        'light-bulb-T' => 'lightbulb',
        'light-bulb-off-T' => 'lightbulb-off',

        // Audio & Media
        'speaker-volume' => 'volume-2',
        'speaker-volume-none' => 'volume-x',

        // Help & Info
        'question-balloon' => 'circle-help',
        'question-frame' => 'help-circle',

        // Loading/Animated
        'waiting' => 'loader-2',
        'waiting2' => 'loader-2',
        'indicator' => 'loader',

        // Placeholder
        'placeholder' => 'circle',
        'empty' => '',

        // Renamed icons (Lucide v0.x → v1.x renames)
        'alert-triangle' => 'triangle-alert',
        'check-circle' => 'circle-check',
        'check-square' => 'square-check-big',
        'more-horizontal' => 'ellipsis',
        'package-import' => 'package-open',
    ];

    /**
     * Icons that should have the "muted" style applied.
     *
     * @var array<string, bool>
     */
    private const MUTED_ICONS = [
        'navigation-000-button-light' => true,
        'navigation-180-button-light' => true,
        'placeholder' => true,
    ];

    /**
     * Icons that should have the spinning animation.
     *
     * @var array<string, bool>
     */
    private const ANIMATED_ICONS = [
        'waiting' => true,
        'waiting2' => true,
        'indicator' => true,
    ];

    /**
     * Render a Lucide icon.
     *
     * @param string               $name  Icon name (Lucide or legacy PNG name)
     * @param array<string, mixed> $attrs Optional HTML attributes
     *
     * @return string HTML for the icon
     */
    public static function render(string $name, array $attrs = []): string
    {
        // Convert legacy icon name to Lucide name if needed
        $legacyName = $name;
        $lucideName = self::ICON_MAP[$name] ?? $name;

        // Handle empty icon (spacer)
        if ($lucideName === '') {
            $width = (int) ($attrs['size'] ?? 16);
            $style = 'display:inline-block;width:' . $width . 'px';
            return '<span class="icon-spacer" style="' . $style . '"></span>';
        }

        // Build CSS classes
        $classes = ['icon'];
        if (isset($attrs['class'])) {
            $classes[] = (string) $attrs['class'];
        }
        if (isset(self::MUTED_ICONS[$legacyName])) {
            $classes[] = 'icon-muted';
        }
        if (isset(self::ANIMATED_ICONS[$legacyName])) {
            $classes[] = 'icon-spin';
        }

        // Extract known attributes
        $title = (string) ($attrs['title'] ?? '');
        $alt = (string) ($attrs['alt'] ?? $title);
        $size = (int) ($attrs['size'] ?? 16);
        $id = (string) ($attrs['id'] ?? '');

        // Build additional attributes
        $extraAttrs = '';
        $skipKeys = ['class', 'title', 'alt', 'size', 'id'];
        /** @var mixed $value */
        foreach ($attrs as $key => $value) {
            if (!in_array($key, $skipKeys, true)) {
                $extraAttrs .= ' ' . htmlspecialchars($key);
                $extraAttrs .= '="' . htmlspecialchars((string)$value) . '"';
            }
        }

        // Build the icon element
        // Using <i> with data-lucide attribute for Lucide.js to replace
        $html = '<i data-lucide="' . htmlspecialchars($lucideName) . '"';
        $html .= ' class="' . htmlspecialchars(implode(' ', $classes)) . '"';
        if ($id !== '') {
            $html .= ' id="' . htmlspecialchars($id) . '"';
        }
        if ($title !== '') {
            $html .= ' title="' . htmlspecialchars($title) . '"';
        }
        if ($alt !== '') {
            $html .= ' aria-label="' . htmlspecialchars($alt) . '"';
        }
        $html .= ' style="width:' . $size . 'px;height:' . $size . 'px"';
        $html .= $extraAttrs;
        $html .= '></i>';

        return $html;
    }

    /**
     * Render a clickable icon (adds 'click' class).
     *
     * @param string               $name  Icon name
     * @param array<string, mixed> $attrs Optional HTML attributes
     *
     * @return string HTML for the clickable icon
     */
    public static function clickable(string $name, array $attrs = []): string
    {
        $classes = isset($attrs['class']) ? (string) $attrs['class'] . ' click' : 'click';
        $attrs['class'] = $classes;
        return self::render($name, $attrs);
    }

    /**
     * Render an icon inside a link.
     *
     * @param string               $name      Icon name
     * @param string               $href      Link URL
     * @param array<string, mixed> $attrs     Optional attributes for the icon
     * @param array<string, mixed> $linkAttrs Optional attributes for the link
     *
     * @return string HTML for the linked icon
     */
    public static function link(
        string $name,
        string $href,
        array $attrs = [],
        array $linkAttrs = []
    ): string {
        $linkClass = (string) ($linkAttrs['class'] ?? '');
        $linkTitle = (string) ($linkAttrs['title'] ?? ($attrs['title'] ?? ''));

        $html = '<a href="' . htmlspecialchars($href) . '"';
        if ($linkClass !== '') {
            $html .= ' class="' . htmlspecialchars($linkClass) . '"';
        }
        if ($linkTitle !== '') {
            $html .= ' title="' . htmlspecialchars($linkTitle) . '"';
        }

        // Add any extra link attributes
        $skipKeys = ['class', 'title', 'href'];
        /** @var mixed $value */
        foreach ($linkAttrs as $key => $value) {
            if (!in_array($key, $skipKeys, true)) {
                $html .= ' ' . htmlspecialchars($key);
                $html .= '="' . htmlspecialchars((string)$value) . '"';
            }
        }

        $html .= '>';
        $html .= self::render($name, $attrs);
        $html .= '</a>';

        return $html;
    }

    /**
     * Get the Lucide icon name for a legacy icon.
     *
     * @param string $legacyName Legacy PNG icon name (without extension)
     *
     * @return string|null Lucide icon name, or null if not mapped
     */
    public static function getLucideName(string $legacyName): ?string
    {
        return self::ICON_MAP[$legacyName] ?? null;
    }

    /**
     * Check if a legacy icon name has a Lucide mapping.
     *
     * @param string $legacyName Legacy PNG icon name (without extension)
     *
     * @return bool True if mapping exists
     */
    public static function hasMapping(string $legacyName): bool
    {
        return isset(self::ICON_MAP[$legacyName]);
    }

    /**
     * Get all icon mappings.
     *
     * @return array<string, string> Array of legacy name => Lucide name
     */
    public static function getAllMappings(): array
    {
        return self::ICON_MAP;
    }

    /**
     * Render the CSS required for icon styling.
     *
     * This should be included once in the page head.
     *
     * @return string CSS style block
     */
    public static function getStyles(): string
    {
        return <<<'CSS'
<style>
.icon {
    display: inline-block;
    vertical-align: middle;
    stroke: currentColor;
    stroke-width: 2;
    stroke-linecap: round;
    stroke-linejoin: round;
    fill: none;
}

.icon.click {
    cursor: pointer;
}

.icon.click:hover {
    opacity: 0.7;
}

.icon-muted {
    opacity: 0.4;
}

.icon-spin {
    animation: icon-spin 1s linear infinite;
}

@keyframes icon-spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}

.icon-spacer {
    display: inline-block;
}
</style>
CSS;
    }

    /**
     * Render the JavaScript required to initialize Lucide icons.
     *
     * This should be included once, typically at the end of the body
     * or after all icons have been rendered.
     *
     * @return string JavaScript script block
     */
    public static function getInitScript(): string
    {
        return <<<'JS'
<script>
if (typeof lucide !== 'undefined') {
    lucide.createIcons();
}
</script>
JS;
    }
}
