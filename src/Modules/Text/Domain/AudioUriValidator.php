<?php

/**
 * Audio URI Validator
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Text\Domain
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Text\Domain;

use Lwt\Shared\Infrastructure\Globals;

/**
 * Validates user-supplied TxAudioURI values before persisting them.
 *
 * Three layers of defense:
 *
 * 1. **Always reject:** path traversal (`..`), null bytes, control
 *    characters, absolute filesystem paths (`/foo`), and dangerous
 *    URI schemes (`javascript:`, `data:`, `file:`, `vbscript:`).
 *    These never have a legitimate use in TxAudioURI and they enable
 *    stored XSS or unbounded filesystem reads when echoed into the
 *    media player.
 *
 * 2. **Multi-user mode:** any *new* relative `media/...` path must
 *    live under the caller's per-user subdirectory (`media/u{id}/...`).
 *    Apache serves `media/` directly with no ownership check, so
 *    without this guard user A can save `media/userB.mp3` into their
 *    own text and stream user B's file.
 *
 * 3. **Grandfather rule:** if the new value matches the previously
 *    stored value byte-for-byte, the multi-user-subdir requirement is
 *    skipped. Pre-existing TxAudioURIs from before this validator
 *    existed (or from before per-user subdirs are enforced) continue
 *    to load when their owner re-saves the text without touching the
 *    audio field. The "always reject" layer still runs.
 *
 * @since 3.0.0
 */
final class AudioUriValidator
{
    /**
     * Validate a TxAudioURI value. Returns the (unchanged) value on
     * success; throws \InvalidArgumentException with a user-facing
     * message on failure.
     *
     * @param string      $audioUri     New value being persisted
     * @param string|null $previousUri  Previously stored value, or null
     *                                  when inserting a new row
     *
     * @return string The validated URI (same as input when valid)
     */
    public static function validate(string $audioUri, ?string $previousUri = null): string
    {
        if ($audioUri === '') {
            return '';
        }

        if (self::containsControlCharacters($audioUri)) {
            throw new \InvalidArgumentException(
                'Audio URI contains invalid characters (control / null bytes).'
            );
        }

        // Block dangerous URI schemes regardless of grandfathering.
        if (self::hasDangerousScheme($audioUri)) {
            throw new \InvalidArgumentException(
                'Audio URI uses a disallowed scheme. Use http:// or https:// or a relative media/ path.'
            );
        }

        // http(s) URLs are always allowed (no ownership concern; Apache
        // serves them from the remote host). filter_var handles syntax.
        if (preg_match('#^https?://#i', $audioUri) === 1) {
            if (filter_var($audioUri, FILTER_VALIDATE_URL) === false) {
                throw new \InvalidArgumentException('Audio URI is not a valid URL.');
            }
            return $audioUri;
        }

        // Anything else must be a relative path inside media/.
        if (str_starts_with($audioUri, '/')) {
            throw new \InvalidArgumentException(
                'Audio URI must be a relative path under media/ or a http(s) URL.'
            );
        }
        if (self::containsTraversal($audioUri)) {
            throw new \InvalidArgumentException('Audio URI must not contain path traversal (..).');
        }
        if (!str_starts_with($audioUri, 'media/')) {
            throw new \InvalidArgumentException(
                'Audio URI must start with "media/" or be a http(s) URL.'
            );
        }

        // Multi-user mode: require the per-user subdirectory unless we
        // are grandfathering an unchanged pre-existing value.
        if (Globals::isMultiUserEnabled() && $audioUri !== $previousUri) {
            $userId = Globals::getCurrentUserId();
            if ($userId !== null) {
                $expectedPrefix = sprintf('media/u%d/', $userId);
                if (!str_starts_with($audioUri, $expectedPrefix)) {
                    throw new \InvalidArgumentException(
                        'In multi-user mode, audio files must live under ' . $expectedPrefix
                    );
                }
            }
        }

        return $audioUri;
    }

    private static function containsControlCharacters(string $value): bool
    {
        // \x00-\x1F (C0 control) and \x7F (DEL) and \x80-\x9F (C1).
        return preg_match('/[\x00-\x1F\x7F\x80-\x9F]/', $value) === 1;
    }

    private static function hasDangerousScheme(string $value): bool
    {
        // Match `scheme:` at the very start (case-insensitive). We
        // don't need to enumerate every dangerous scheme — the
        // allowlist for non-http(s) is "no scheme at all".
        if (preg_match('#^([a-z][a-z0-9+.\-]*):#i', $value, $m) !== 1) {
            return false;
        }
        $scheme = strtolower($m[1]);
        return $scheme !== 'http' && $scheme !== 'https';
    }

    private static function containsTraversal(string $value): bool
    {
        // Match `..` as a standalone segment in the URL-style path.
        // Plain `..` literal is fine inside a filename (e.g. `foo..mp3`)
        // but `../`, `/..`, or a leading `..` followed by `/` is not.
        return preg_match('#(^|/)\.\.(/|$)#', $value) === 1;
    }
}
