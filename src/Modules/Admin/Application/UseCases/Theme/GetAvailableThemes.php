<?php

/**
 * Get Available Themes Use Case
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Admin\Application\UseCases\Theme
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Admin\Application\UseCases\Theme;

/**
 * Use case for getting available themes.
 *
 * Scans the filesystem for available theme directories.
 *
 * @since 3.0.0
 */
class GetAvailableThemes
{
    /**
     * Execute the use case.
     *
     * Scans the dist/themes/ directory for theme directories.
     * The Default theme is discovered via glob like all others
     * (it has a theme.json but no styles.css — the base CSS handles it).
     *
     * @return array<int, array{
     *     path: string,
     *     name: string,
     *     description: string,
     *     mode: string,
     *     counterpart: string,
     *     highlighting: string,
     *     wordBreaking: string
     * }> Array of theme data with metadata
     */
    public function execute(): array
    {
        $themes = [];
        // Psalm's glob() stub does not enumerate GLOB_ONLYDIR (1<<30) among
        // its accepted flag bitmask, so the call is suppressed here.
        /** @psalm-suppress InvalidArgument */
        $globResult = glob('dist/themes/*', GLOB_ONLYDIR);
        $themeDirs = $globResult === false ? [] : $globResult;

        // Ensure Default appears first by processing it separately
        $otherThemes = [];
        foreach ($themeDirs as $theme) {
            $metadata = $this->loadThemeMetadata($theme);
            $entry = array_merge(['path' => $theme . '/'], $metadata);
            if ($theme === 'dist/themes/Default') {
                array_unshift($themes, $entry);
            } else {
                $otherThemes[] = $entry;
            }
        }

        // If Default wasn't found via glob (shouldn't happen), add it manually
        if (empty($themes)) {
            $themes[] = [
                'path' => 'dist/themes/Default/',
                'name' => 'Default',
                'description' => 'Standard theme with background color highlighting. '
                    . 'Auto-detects dark mode from system preference.',
                'mode' => 'light',
                'counterpart' => 'dist/themes/Dark/',
                'highlighting' => 'Background color highlighting',
                'wordBreaking' => 'Standard',
            ];
        }

        return array_merge($themes, $otherThemes);
    }

    /**
     * Load theme metadata from theme.json file.
     *
     * @param string $themePath Path to the theme directory
     *
     * @return array{
     *     name: string,
     *     description: string,
     *     mode: string,
     *     counterpart: string,
     *     highlighting: string,
     *     wordBreaking: string
     * } Theme metadata
     */
    private function loadThemeMetadata(string $themePath): array
    {
        $jsonPath = $themePath . '/theme.json';
        $fallbackName = str_replace(['dist/themes/', '_'], ['', ' '], $themePath);

        $defaults = [
            'name' => $fallbackName,
            'description' => '',
            'mode' => 'light',
            'counterpart' => 'dist/themes/Dark/',
            'highlighting' => '',
            'wordBreaking' => ''
        ];

        if (!file_exists($jsonPath)) {
            return $defaults;
        }

        $content = file_get_contents($jsonPath);
        if ($content === false) {
            return $defaults;
        }

        $metadata = json_decode($content, true);
        if (!is_array($metadata)) {
            return $defaults;
        }

        return [
            'name' => isset($metadata['name']) && is_string($metadata['name'])
                ? $metadata['name'] : $defaults['name'],
            'description' => isset($metadata['description']) && is_string($metadata['description'])
                ? $metadata['description'] : $defaults['description'],
            'mode' => isset($metadata['mode']) && is_string($metadata['mode'])
                ? $metadata['mode'] : $defaults['mode'],
            'counterpart' => isset($metadata['counterpart']) && is_string($metadata['counterpart'])
                ? $metadata['counterpart'] : $defaults['counterpart'],
            'highlighting' => isset($metadata['highlighting']) && is_string($metadata['highlighting'])
                ? $metadata['highlighting'] : $defaults['highlighting'],
            'wordBreaking' => isset($metadata['wordBreaking']) && is_string($metadata['wordBreaking'])
                ? $metadata['wordBreaking'] : $defaults['wordBreaking'],
        ];
    }
}
