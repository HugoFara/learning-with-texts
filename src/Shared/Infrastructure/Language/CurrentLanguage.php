<?php

/**
 * Current Language - Resolves the effective language for the current request
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Shared\Infrastructure\Language
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.2.2
 */

declare(strict_types=1);

namespace Lwt\Shared\Infrastructure\Language;

use Lwt\Shared\Infrastructure\Database\QueryBuilder;
use Lwt\Shared\Infrastructure\Database\Settings;

/**
 * Resolves the language the user is currently working in.
 *
 * The `currentlanguage` setting is only written when the user actively picks a
 * language from the navbar dropdown. Two situations leave it unset or stale, and
 * both used to surface as "no language selected" even though the navbar showed
 * one:
 *
 * - A user who has just created their first language has never fired the
 *   dropdown's `change` event. With exactly one language there is no other
 *   option to switch to, so the event can never fire and the setting can never
 *   be written — the install is stuck in that state permanently.
 * - The setting can point at a language that has since been deleted.
 *
 * In both cases the navbar still *looked* right: it renders a `<select>` and
 * marks an option `selected` only when the id matches, so with no match the
 * browser falls back to displaying the first option. The display and the stored
 * state disagreed, and every server-side consumer read 0.
 *
 * This resolver is the single answer to "which language is active?". It is
 * read-only on purpose: it never writes the setting, so a plain GET has no side
 * effects and the same request always resolves the same way.
 *
 * Callers that need the id to agree with what the navbar displays must use this
 * rather than reading the setting directly — the fallback ordering here matches
 * the navbar's (`LgName`, skipping unnamed rows).
 */
class CurrentLanguage
{
    /**
     * Resolve the effective current language ID.
     *
     * Returns the stored setting when it still points at an existing language,
     * otherwise the first language the navbar would list. Returns 0 only when
     * the user genuinely has no languages yet.
     *
     * @return int Language ID, or 0 if none is available
     */
    public static function resolveId(): int
    {
        try {
            $stored = (int) Settings::getWithDefault('currentlanguage');
            if ($stored > 0 && self::exists($stored)) {
                return $stored;
            }

            return self::firstAvailableId();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Check whether a language ID still exists.
     *
     * @param int $languageId Language ID to check
     *
     * @return bool True if the language exists
     */
    private static function exists(int $languageId): bool
    {
        return QueryBuilder::table('languages')
            ->where('LgID', '=', $languageId)
            ->countPrepared() > 0;
    }

    /**
     * Get the first language the navbar would list.
     *
     * Mirrors the navbar's own filter and ordering so the resolved fallback is
     * the language the user already sees displayed.
     *
     * @return int Language ID, or 0 if the user has no languages
     */
    private static function firstAvailableId(): int
    {
        $row = QueryBuilder::table('languages')
            ->select(['LgID'])
            ->where('LgName', '<>', '')
            ->orderBy('LgName')
            ->firstPrepared();

        return $row !== null ? (int) $row['LgID'] : 0;
    }
}
