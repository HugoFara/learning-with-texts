<?php

/**
 * OPDS Catalog Parser Service
 *
 * Parses OPDS (Open Publication Distribution System) feeds — the Atom-based
 * catalog format used by the Global Digital Library and Bloom Library. Two
 * feed flavours are handled:
 *
 * - Navigation feeds: entries point at sub-feeds (languages, reading levels).
 * - Acquisition feeds: entries describe downloadable books (ePUB/PDF).
 *
 * Pure parsing, no network or database access; the SSRF-guarded fetch lives
 * in the client (GdlClient), mirroring RssParser's split.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Feed\Application\Services
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.1.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Feed\Application\Services;

/**
 * Parser for OPDS navigation and acquisition feeds.
 *
 * @since 3.1.0
 */
class OpdsParser
{
    /**
     * OPDS acquisition link relation prefix.
     *
     * GDL uses the "open-access" sub-rel; matching on the substring keeps us
     * tolerant of the related "acquisition" and "acquisition/open-access"
     * spellings different OPDS producers emit.
     */
    private const REL_ACQUISITION = 'acquisition';

    /**
     * OPDS cover-image link relation.
     */
    private const REL_IMAGE = 'opds-spec.org/image';

    /**
     * MIME type marking the downloadable ePUB acquisition link.
     */
    private const TYPE_EPUB = 'application/epub+zip';

    /**
     * Parse an acquisition feed into book rows plus a pagination cursor.
     *
     * Entries without a downloadable ePUB link are skipped, so calling this
     * on a navigation feed (or an empty/malformed document) safely yields an
     * empty result set rather than throwing.
     *
     * @param string $xml Raw OPDS feed XML
     *
     * @return array{results: list<array{
     *     id: string, title: string, authors: list<string>, language: string,
     *     level: string, summary: string, epubUrl: string, coverUrl: ?string
     * }>, next: ?string}
     */
    public function acquisitionEntries(string $xml): array
    {
        $doc = $this->loadDocument($xml);
        if ($doc === null) {
            return ['results' => [], 'next' => null];
        }

        $results = [];
        foreach ($doc->getElementsByTagName('entry') as $entry) {
            if (!$entry instanceof \DOMElement) {
                continue;
            }

            $epubUrl = $this->linkHref(
                $entry,
                fn (\DOMElement $l): bool =>
                    str_contains($l->getAttribute('rel'), self::REL_ACQUISITION)
                    && $l->getAttribute('type') === self::TYPE_EPUB
            );
            if ($epubUrl === null) {
                continue; // Not a downloadable book entry.
            }

            $results[] = [
                'id' => $this->directChildText($entry, 'id'),
                'title' => $this->directChildText($entry, 'title'),
                'authors' => $this->authors($entry),
                'language' => $this->directChildText($entry, 'language'),
                'level' => $this->readingLevel($entry),
                'summary' => $this->summary($entry),
                'epubUrl' => $epubUrl,
                'coverUrl' => $this->coverUrl($entry),
            ];
        }

        return ['results' => $results, 'next' => $this->feedNextHref($doc)];
    }

    /**
     * Parse a navigation feed into its sub-feed links.
     *
     * @param string $xml Raw OPDS navigation feed XML
     *
     * @return list<array{title: string, href: string, lang: string}>
     */
    public function navigationLinks(string $xml): array
    {
        $doc = $this->loadDocument($xml);
        if ($doc === null) {
            return [];
        }

        $links = [];
        foreach ($doc->getElementsByTagName('entry') as $entry) {
            if (!$entry instanceof \DOMElement) {
                continue;
            }

            $lang = '';
            $href = $this->navHref($entry, $lang);
            if ($href === null) {
                continue;
            }

            $links[] = [
                'title' => $this->directChildText($entry, 'title'),
                'href' => $href,
                'lang' => $lang !== '' ? $lang : $this->directChildText($entry, 'language'),
            ];
        }

        return $links;
    }

    /**
     * Find the sub-feed href for a language or section, matched leniently.
     *
     * Tries an exact (case-insensitive) language-code match first, then falls
     * back to a title substring match — so both "en" and "English", or "3"
     * and "Level 3", resolve to the right sub-feed.
     *
     * @param string $xml    Raw OPDS navigation feed XML
     * @param string $needle Language code or section label to look for
     *
     * @return string|null Matching href, or null if none found
     */
    public function findNavHref(string $xml, string $needle): ?string
    {
        $needle = strtolower(trim($needle));
        if ($needle === '') {
            return null;
        }

        $entries = $this->navigationLinks($xml);

        foreach ($entries as $entry) {
            if (strtolower($entry['lang']) === $needle) {
                return $entry['href'];
            }
        }

        foreach ($entries as $entry) {
            if (str_contains(strtolower($entry['title']), $needle)) {
                return $entry['href'];
            }
        }

        return null;
    }

    /**
     * Load OPDS XML into a DOM with the same hardening RssParser uses.
     *
     * LIBXML_NONET blocks libxml's external-entity (XXE/SSRF) fetches and we
     * deliberately omit LIBXML_NOENT to keep billion-laughs expansion off.
     *
     * @param string $xml Raw feed XML
     *
     * @return \DOMDocument|null Parsed document, or null on malformed input
     */
    private function loadDocument(string $xml): ?\DOMDocument
    {
        if (trim($xml) === '') {
            return null;
        }

        $doc = new \DOMDocument('1.0', 'utf-8');
        if (!@$doc->loadXML($xml, LIBXML_NOCDATA | LIBXML_NONET)) {
            return null;
        }

        return $doc;
    }

    /**
     * Collect author names from an entry's <author><name> children.
     *
     * @param \DOMElement $entry Acquisition entry node
     *
     * @return list<string>
     */
    private function authors(\DOMElement $entry): array
    {
        $authors = [];
        foreach ($entry->getElementsByTagName('author') as $author) {
            if (!$author instanceof \DOMElement) {
                continue;
            }
            $name = $this->directChildText($author, 'name');
            if ($name !== '') {
                $authors[] = $name;
            }
        }

        return $authors;
    }

    /**
     * Extract the human-readable reading level from an entry's categories.
     *
     * GDL tags books with a <category> whose label reads "Level N",
     * "Read aloud", or "Decodable"; other (genre) categories are ignored.
     *
     * @param \DOMElement $entry Acquisition entry node
     *
     * @return string Reading-level label, or '' when none is present
     */
    private function readingLevel(\DOMElement $entry): string
    {
        foreach ($entry->getElementsByTagName('category') as $cat) {
            if (!$cat instanceof \DOMElement) {
                continue;
            }
            $label = trim($cat->getAttribute('label'));
            $candidate = $label !== '' ? $label : trim($cat->getAttribute('term'));
            if (preg_match('/^(Level\s*\d|Read[\s-]?aloud|Decodable)/i', $candidate) === 1) {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * Extract the entry summary, preferring <summary> over <content>.
     *
     * @param \DOMElement $entry Acquisition entry node
     *
     * @return string
     */
    private function summary(\DOMElement $entry): string
    {
        $summary = $this->directChildText($entry, 'summary');
        return $summary !== '' ? $summary : $this->directChildText($entry, 'content');
    }

    /**
     * Resolve the best cover image, preferring full image over thumbnail.
     *
     * @param \DOMElement $entry Acquisition entry node
     *
     * @return string|null Cover URL, or null when no image link is present
     */
    private function coverUrl(\DOMElement $entry): ?string
    {
        $full = $this->linkHref(
            $entry,
            fn (\DOMElement $l): bool =>
                str_contains($l->getAttribute('rel'), self::REL_IMAGE)
                && !str_contains($l->getAttribute('rel'), 'thumbnail')
        );
        if ($full !== null) {
            return $full;
        }

        return $this->linkHref(
            $entry,
            fn (\DOMElement $l): bool => str_contains($l->getAttribute('rel'), self::REL_IMAGE)
        );
    }

    /**
     * Return the href of the first navigation sub-feed link on an entry.
     *
     * Cover-image links are skipped; the language code (if advertised via
     * hreflang) is returned through $lang by reference.
     *
     * @param \DOMElement $entry Navigation entry node
     * @param string      $lang  Out-param: hreflang of the matched link
     *
     * @return string|null Sub-feed href, or null if the entry has no nav link
     */
    private function navHref(\DOMElement $entry, string &$lang): ?string
    {
        $lang = '';
        foreach ($entry->getElementsByTagName('link') as $link) {
            if (!$link instanceof \DOMElement) {
                continue;
            }
            if (str_contains($link->getAttribute('rel'), self::REL_IMAGE)) {
                continue; // Skip cover images; we want the catalog sub-feed.
            }
            $href = trim($link->getAttribute('href'));
            if ($href !== '') {
                $lang = trim($link->getAttribute('hreflang'));
                return $href;
            }
        }

        return null;
    }

    /**
     * Find the feed-level pagination "next" link.
     *
     * Only direct children of the root <feed> are inspected so that an
     * entry-level link with rel="next" cannot be mistaken for the cursor.
     *
     * @param \DOMDocument $doc Parsed feed document
     *
     * @return string|null Next-page URL, or null when this is the last page
     */
    private function feedNextHref(\DOMDocument $doc): ?string
    {
        $root = $doc->documentElement;
        if ($root === null) {
            return null;
        }

        foreach ($root->childNodes as $child) {
            if (
                $child instanceof \DOMElement
                && $child->localName === 'link'
                && strtolower(trim($child->getAttribute('rel'))) === 'next'
            ) {
                $href = trim($child->getAttribute('href'));
                return $href !== '' ? $href : null;
            }
        }

        return null;
    }

    /**
     * Return the first acquisition/cover link href matching a predicate.
     *
     * @param \DOMElement                  $entry Entry node
     * @param callable(\DOMElement): bool $match Predicate over each link
     *
     * @return string|null Matching href, or null if none matched
     */
    private function linkHref(\DOMElement $entry, callable $match): ?string
    {
        foreach ($entry->getElementsByTagName('link') as $link) {
            if (!$link instanceof \DOMElement) {
                continue;
            }
            if ($match($link)) {
                $href = trim($link->getAttribute('href'));
                if ($href !== '') {
                    return $href;
                }
            }
        }

        return null;
    }

    /**
     * Read the trimmed text of an element's first direct child of a name.
     *
     * Uses local-name matching (namespace-agnostic) and looks only at direct
     * children, so a nested <author><name> never leaks into a <title> read.
     *
     * @param \DOMElement $el    Parent element
     * @param string      $local Local element name to look for
     *
     * @return string Trimmed text, or '' when the child is absent
     */
    private function directChildText(\DOMElement $el, string $local): string
    {
        foreach ($el->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->localName === $local) {
                return trim((string) $child->nodeValue);
            }
        }

        return '';
    }
}
