<?php

/**
 * \file
 * \brief Vite asset helper for development and production modes.
 *
 * This file provides static methods to load Vite-built assets in PHP,
 * supporting both development mode (with HMR) and production mode
 * (with manifest-based asset loading).
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

namespace Lwt\Shared\UI\Assets;

use Lwt\Shared\Infrastructure\Http\UrlUtilities;

/**
 * Helper class for Vite asset loading.
 *
 * Provides methods for loading Vite-bundled assets in both
 * development mode (with HMR) and production mode (manifest-based).
 *
 * @since 3.0.0
 */
class ViteHelper
{
    /**
     * Cached manifest data.
     *
     * @var array<string, mixed>|null
     */
    private static ?array $manifest = null;

    /**
     * Check if Vite development server is running.
     *
     * @return bool True if dev server is detected and responding
     */
    public static function isDevServerRunning(): bool
    {
        if (getenv('VITE_DEV_MODE') === false || getenv('VITE_DEV_MODE') === '') {
            return false;
        }

        $ch = curl_init('http://localhost:5173/@vite/client');
        if ($ch === false) {
            return false;
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 1);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_exec($ch);
        /** @var int $httpCode */
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode === 200;
    }

    /**
     * Get the Vite manifest file contents.
     *
     * @return array<string, mixed>|null Manifest array or null if not found
     */
    public static function getManifest(): ?array
    {
        if (self::$manifest === null) {
            $path = __DIR__ . '/../../../../dist/.vite/manifest.json';
            if (file_exists($path)) {
                $content = file_get_contents($path);
                if ($content !== false) {
                    /** @var array<string, mixed>|null $decoded */
                    $decoded = json_decode($content, true);
                    self::$manifest = is_array($decoded) ? $decoded : null;
                }
            }
        }

        return self::$manifest;
    }

    /**
     * Generate HTML tags for Vite assets.
     *
     * In development mode, loads assets from Vite dev server with HMR.
     * In production mode, loads assets from the manifest file.
     *
     * @param string $entry    The entry point path (e.g., 'js/main.ts')
     * @param bool   $asyncCss Whether to load CSS asynchronously (non-render-blocking)
     *
     * @return string HTML script and link tags
     */
    public static function assets(string $entry = 'js/main.ts', bool $asyncCss = true): string
    {
        if (self::isDevServerRunning()) {
            return <<<HTML
<script type="module" src="http://localhost:5173/@vite/client"></script>
<script type="module" src="http://localhost:5173/{$entry}"></script>
HTML;
        }

        $manifest = self::getManifest();
        if ($manifest === null || !isset($manifest[$entry])) {
            return '<!-- Vite manifest not found or entry missing -->';
        }

        /** @var array{file?: string, css?: array<string>}|mixed $entryData */
        $entryData = $manifest[$entry];
        if (!is_array($entryData)) {
            return '<!-- Invalid manifest entry -->';
        }
        $html = '';

        // Load CSS files
        // Note: Async CSS loading via onload requires inline JS which violates strict CSP.
        // We use standard stylesheet loading with media="print" switching handled by
        // external JS in main.ts for CSP compliance. For browsers without JS,
        // the noscript fallback ensures styles load.
        if (isset($entryData['css']) && is_array($entryData['css'])) {
            /** @var mixed $cssFile */
            foreach ($entryData['css'] as $cssFile) {
                $cssPath = UrlUtilities::url('/dist/' . htmlspecialchars((string) $cssFile));
                if ($asyncCss) {
                    // CSP-compliant async CSS: use media="print" initially,
                    // JS in main.ts will switch to media="all" on load
                    $html .= '<link rel="stylesheet" href="' . $cssPath .
                        '" media="print" data-async-css>' . "\n";
                    $html .= '<noscript><link rel="stylesheet" href="' . $cssPath . '"></noscript>' . "\n";
                } else {
                    $html .= '<link rel="stylesheet" href="' . $cssPath . '">' . "\n";
                }
            }
        }

        // Load JS module
        if (isset($entryData['file']) && is_string($entryData['file'])) {
            $jsPath = UrlUtilities::url('/dist/' . htmlspecialchars($entryData['file']));
            $html .= '<script type="module" src="' . $jsPath . '"></script>' . "\n";
        }

        return $html;
    }

    /**
     * Determine whether to use Vite assets or legacy assets.
     *
     * Checks the LWT_ASSET_MODE environment variable:
     * - 'vite': Always use Vite assets
     * - 'legacy': Always use legacy PHP-minified assets
     * - 'auto' or unset: Use Vite if manifest exists, otherwise legacy
     *
     * @return bool True if Vite assets should be used
     */
    public static function shouldUse(): bool
    {
        $envMode = getenv('LWT_ASSET_MODE');
        $mode = ($envMode === false || $envMode === '') ? 'auto' : $envMode;

        if ($mode === 'legacy') {
            return false;
        }
        if ($mode === 'vite') {
            return true;
        }

        // Auto mode: use Vite if manifest exists
        return self::getManifest() !== null;
    }

    /**
     * Get critical CSS for initial render.
     *
     * This returns inline CSS that should be included in the <head> to
     * prevent Flash of Unstyled Content (FOUC) while the main CSS loads async.
     *
     * @return string Inline critical CSS wrapped in <style> tags
     */
    public static function criticalCss(): string
    {
        $criticalPath = __DIR__ . '/../../../../src/frontend/css/critical.css';
        if (!file_exists($criticalPath)) {
            return '';
        }

        $css = file_get_contents($criticalPath);
        if ($css === false) {
            return '';
        }

        // Minify: remove comments, extra whitespace
        $css = preg_replace('/\/\*[\s\S]*?\*\//', '', $css) ?? $css;
        $css = preg_replace('/\s+/', ' ', $css) ?? $css;
        $patterns = [' {', '{ ', ' }', '} ', ': ', ' :', '; ', ' ;'];
        $replacements = ['{', '{', '}', '}', ':', ':', ';', ';'];
        $css = str_replace($patterns, $replacements, $css);
        $css = trim($css);

        return '<style>' . $css . '</style>';
    }

    /**
     * Reset the cached manifest (useful for testing).
     *
     * @return void
     */
    public static function resetCache(): void
    {
        self::$manifest = null;
    }
}
