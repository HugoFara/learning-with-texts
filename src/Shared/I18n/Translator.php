<?php

/**
 * Translator Service
 *
 * Provides internationalization (i18n) support by loading translations
 * from JSON locale files with dot-notation key resolution.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Shared\I18n
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Shared\I18n;

/**
 * Loads translations from per-namespace JSON files and resolves
 * dot-notated keys with parameter interpolation.
 *
 * Keys follow the format "namespace.key", e.g. "common.save".
 * The namespace maps to a JSON file: locale/{lang}/common.json.
 * Namespaces are loaded lazily on first access.
 *
 * @since 3.0.0
 */
class Translator
{
    /**
     * Base path to the locale directory.
     *
     * @var string
     */
    private string $localePath;

    /**
     * Active locale code (e.g. "en", "es").
     *
     * @var string
     */
    private string $locale;

    /**
     * Cache of loaded namespace translations.
     *
     * Keyed by "{locale}.{namespace}" => [key => translation].
     *
     * @var array<string, array<string, string>>
     */
    private array $loaded = [];

    /**
     * @param string $localePath Absolute path to the locale/ directory
     * @param string $locale     Active locale code (default: "en")
     */
    public function __construct(string $localePath, string $locale = 'en')
    {
        $this->localePath = $localePath;
        $this->locale = $locale;
    }

    /**
     * Set the active locale.
     *
     * @param string $locale Locale code (e.g. "es", "zh")
     *
     * @return void
     */
    public function setLocale(string $locale): void
    {
        $this->locale = $locale;
    }

    /**
     * Get the active locale.
     *
     * @return string
     */
    public function getLocale(): string
    {
        return $this->locale;
    }

    /**
     * Translate a dot-notated key with optional parameter interpolation.
     *
     * @param string                    $key    Dot-notated key (e.g. "common.save")
     * @param array<string, string|int> $params Interpolation parameters
     *
     * @return string Translated string, or the raw key if not found
     */
    public function translate(string $key, array $params = []): string
    {
        [$namespace, $subKey] = $this->resolveKey($key);

        // Try active locale first
        $text = $this->lookup($this->locale, $namespace, $subKey);

        // Fall back to English
        if ($text === null && $this->locale !== 'en') {
            $text = $this->lookup('en', $namespace, $subKey);
        }

        // If still not found, return the raw key
        if ($text === null) {
            return $key;
        }

        // Interpolate parameters
        if ($params !== []) {
            foreach ($params as $paramKey => $value) {
                $text = str_replace('{' . $paramKey . '}', (string)$value, $text);
            }
        }

        return $text;
    }

    /**
     * Get all translations for a namespace in the active locale.
     *
     * Used to inject translations into the frontend.
     *
     * @param string $namespace Namespace name (e.g. "common")
     *
     * @return array<string, string> Key-value translation pairs
     */
    public function getNamespaceTranslations(string $namespace): array
    {
        $translations = $this->loadNamespace($this->locale, $namespace);

        // Merge English fallback for missing keys
        if ($this->locale !== 'en') {
            $english = $this->loadNamespace('en', $namespace);
            $translations = array_merge($english, $translations);
        }

        return $translations;
    }

    /**
     * Get every translation for a locale as a flat dot-notation map.
     *
     * Used to deliver translations to the frontend over the API (so a
     * configurable client can fetch strings instead of relying on the
     * server-injected page blob). Keys are "{namespace}.{key}"; values are
     * the locale's string with English fallback for missing keys — the same
     * shape PageLayoutHelper::buildI18nScript() injects, so the frontend
     * translator consumes either source identically.
     *
     * Both inputs are validated against the on-disk locale set, which also
     * neutralises path traversal via crafted locale/namespace names.
     *
     * @param string        $locale     Locale code (e.g. "es")
     * @param string[]|null $namespaces Limit to these namespaces; null = all
     *
     * @return array<string, string> Flat map of "namespace.key" => translation
     */
    public function getAllTranslations(string $locale, ?array $namespaces = null): array
    {
        $available = $this->availableNamespaces();
        if ($namespaces === null) {
            $namespaces = $available;
        } else {
            // Drop anything that isn't a real namespace. Also stops a crafted
            // value like "../../etc/passwd" from reaching the filesystem.
            $namespaces = array_values(array_intersect($namespaces, $available));
        }

        // An unknown locale falls back to English (and can't traverse paths).
        if (!in_array($locale, $this->getAvailableLocales(), true)) {
            $locale = 'en';
        }

        $result = [];
        foreach ($namespaces as $namespace) {
            $strings = $this->loadNamespace($locale, $namespace);
            if ($locale !== 'en') {
                $strings = array_merge($this->loadNamespace('en', $namespace), $strings);
            }
            foreach ($strings as $key => $value) {
                $result[$namespace . '.' . $key] = $value;
            }
        }

        return $result;
    }

    /**
     * List available namespaces from the English locale directory.
     *
     * English is the canonical, complete locale, so its files define the
     * full namespace set.
     *
     * @return string[] Namespace names (e.g. ["common", "navbar", "text"])
     */
    private function availableNamespaces(): array
    {
        $files = glob($this->localePath . '/en/*.json');
        if ($files === false) {
            return [];
        }
        $namespaces = [];
        foreach ($files as $file) {
            $namespaces[] = basename($file, '.json');
        }
        sort($namespaces);
        return $namespaces;
    }

    /**
     * Get list of available locale codes.
     *
     * A locale is considered available if its directory contains a common.json file.
     *
     * @return string[] Locale codes (e.g. ["en", "es", "zh"])
     */
    public function getAvailableLocales(): array
    {
        $locales = [];
        $dirs = glob($this->localePath . '/*/common.json');
        if ($dirs === false) {
            return ['en'];
        }
        foreach ($dirs as $path) {
            $dir = basename(dirname($path));
            $locales[] = $dir;
        }
        sort($locales);
        return $locales;
    }

    /**
     * Look up a single key in a specific locale and namespace.
     *
     * @param string $locale    Locale code
     * @param string $namespace Namespace name
     * @param string $subKey    Key within the namespace
     *
     * @return string|null Translation or null if not found
     */
    private function lookup(string $locale, string $namespace, string $subKey): ?string
    {
        $data = $this->loadNamespace($locale, $namespace);
        return $data[$subKey] ?? null;
    }

    /**
     * Load a namespace file for a locale, caching the result.
     *
     * @param string $locale    Locale code
     * @param string $namespace Namespace name
     *
     * @return array<string, string> Key-value pairs from the JSON file
     */
    private function loadNamespace(string $locale, string $namespace): array
    {
        $cacheKey = $locale . '.' . $namespace;
        if (isset($this->loaded[$cacheKey])) {
            return $this->loaded[$cacheKey];
        }

        $file = $this->localePath . '/' . $locale . '/' . $namespace . '.json';
        if (!file_exists($file)) {
            $this->loaded[$cacheKey] = [];
            return [];
        }

        $content = file_get_contents($file);
        if ($content === false) {
            $this->loaded[$cacheKey] = [];
            return [];
        }

        /** @var array<string, string>|null $decoded */
        $decoded = json_decode($content, true);
        $data = is_array($decoded) ? $decoded : [];
        $this->loaded[$cacheKey] = $data;

        return $data;
    }

    /**
     * Split a dot-notated key into namespace and sub-key.
     *
     * "common.save" => ["common", "save"]
     * "admin.dashboard.title" => ["admin", "dashboard.title"]
     * "orphan" => ["common", "orphan"]
     *
     * @param string $key Dot-notated key
     *
     * @return array{0: string, 1: string} [namespace, subKey]
     */
    private function resolveKey(string $key): array
    {
        $dotPos = strpos($key, '.');
        if ($dotPos === false) {
            return ['common', $key];
        }
        return [substr($key, 0, $dotPos), substr($key, $dotPos + 1)];
    }
}
