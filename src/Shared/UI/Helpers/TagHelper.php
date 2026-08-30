<?php

/**
 * \file
 * \brief Helper for tag display.
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
 * Helper class for rendering tags with Bulma styling.
 *
 * @since 3.0.0
 */
class TagHelper
{
    /**
     * Render a comma-separated tag list as Bulma tag components.
     *
     * @param string $tagList    Comma-separated tag list (e.g., "tag1,tag2,tag3")
     * @param string $size       Bulma size class (e.g., 'is-small', 'is-normal')
     * @param string $color      Bulma color class (e.g., 'is-info', 'is-primary')
     * @param bool   $isLight    Whether to use light variant
     * @param string $wrapClass  Additional classes for the wrapper div
     *
     * @return string HTML for Bulma tags, or empty string if no tags
     */
    public static function render(
        string $tagList,
        string $size = 'is-small',
        string $color = 'is-info',
        bool $isLight = true,
        string $wrapClass = ''
    ): string {
        $tagList = trim($tagList);
        if ($tagList === '') {
            return '';
        }

        $tags = array_map('trim', explode(',', $tagList));
        $tags = array_filter($tags, fn($tag) => $tag !== '');

        if (empty($tags)) {
            return '';
        }

        $lightClass = $isLight ? ' is-light' : '';
        $sizeClass = $size !== '' ? ' ' . $size : '';
        $colorClass = $color !== '' ? ' ' . $color : '';

        $html = '<div class="tags' . ($wrapClass !== '' ? ' ' . $wrapClass : '') . '">';
        foreach ($tags as $tag) {
            $escapedTag = htmlspecialchars($tag, ENT_QUOTES, 'UTF-8');
            $html .= '<span class="tag' . $colorClass . $lightClass . $sizeClass . '">'
                . $escapedTag . '</span>';
        }
        $html .= '</div>';

        return $html;
    }

    /**
     * Render tags inline (without wrapper div).
     *
     * @param string $tagList    Comma-separated tag list
     * @param string $size       Bulma size class
     * @param string $color      Bulma color class
     * @param bool   $isLight    Whether to use light variant
     *
     * @return string HTML for Bulma tags without wrapper
     */
    public static function renderInline(
        string $tagList,
        string $size = 'is-small',
        string $color = 'is-info',
        bool $isLight = true
    ): string {
        $tagList = trim($tagList);
        if ($tagList === '') {
            return '';
        }

        $tags = array_map('trim', explode(',', $tagList));
        $tags = array_filter($tags, fn($tag) => $tag !== '');

        if (empty($tags)) {
            return '';
        }

        $lightClass = $isLight ? ' is-light' : '';
        $sizeClass = $size !== '' ? ' ' . $size : '';
        $colorClass = $color !== '' ? ' ' . $color : '';

        $html = '';
        foreach ($tags as $tag) {
            $escapedTag = htmlspecialchars($tag, ENT_QUOTES, 'UTF-8');
            $html .= '<span class="tag' . $colorClass . $lightClass . $sizeClass . '">'
                . $escapedTag . '</span>';
        }

        return $html;
    }
}
