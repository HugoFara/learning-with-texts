<?php

/**
 * \file
 * \brief Emitter for the JSON config blobs that seed client-side pages.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Shared\UI\Helpers
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.6.1
 */

declare(strict_types=1);

namespace Lwt\Shared\UI\Helpers;

/**
 * Renders a page's seed data as a JSON island for the frontend to pick up.
 *
 * Views are shells: they emit markup plus one of these blobs, and the Alpine
 * component reads it in init() via readPageConfig() from
 * `shared/utils/page_config`. That indirection exists because the CSP build
 * of Alpine cannot evaluate inline expressions, so PHP values cannot be
 * interpolated into x-data.
 *
 * Always go through this class rather than hand-rolling the script tag: the
 * escaping flags below are what stop a value containing `</script>` from
 * breaking out of the element, and hand-written islands have historically
 * omitted them.
 *
 * @category Lwt
 * @package  Lwt\Shared\UI\Helpers
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.6.1
 */
class ConfigIsland
{
    /**
     * Escaping flags applied to every island.
     *
     * HEX_TAG is the load-bearing one — it turns `<` and `>` into < /
     * > so no string value can close the script element. The other HEX
     * flags cost nothing and keep the blob safe if it is ever moved into an
     * attribute. Unicode is left as-is so the payload stays readable and
     * compact for non-Latin scripts.
     */
    private const FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS
        | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;

    /**
     * Build the script element for a config island.
     *
     * @param string $id   DOM id the frontend reads, e.g. 'book-list-config'
     * @param array  $data Seed data, JSON-encodable
     *
     * @return string The complete <script> element
     */
    public static function html(string $id, array $data): string
    {
        $json = json_encode($data, self::FLAGS);
        if ($json === false) {
            $json = '{}';
        }

        return '<script type="application/json" id="'
            . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '">'
            . $json . '</script>';
    }

    /**
     * Echo the script element for a config island.
     *
     * @param string $id   DOM id the frontend reads, e.g. 'book-list-config'
     * @param array  $data Seed data, JSON-encodable
     *
     * @return void
     */
    public static function render(string $id, array $data): void
    {
        echo self::html($id, $data);
    }
}
