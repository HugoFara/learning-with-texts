<?php

/**
 * \file
 * \brief Simple Mardown to HTML utility file.
 *
 * PHP version 8.2
 *
 * @category Documentation
 * @package Lwt_Documentation
 * @author  HugoFara <git@hugofara.net>
 * @license Unlicense <http://unlicense.org/>
 * @link    https://hugofara.github.io/lwt/developer/api
 * @since   2.0.3-fork
 */

namespace Lwt\Tools;

require __DIR__ . '/../../vendor/autoload.php';

use League\CommonMark\GithubFlavoredMarkdownConverter;

/**
 * Convert a markdown file to HTML and return the result.
 *
 * @param string $file_path Full path for the file to use, including extension.
 *
 * @return string An HTML-formatted string
 */
function markdownConverter($file_path)
{
    $converter = new GithubFlavoredMarkdownConverter();
    $markdown = file_get_contents($file_path);
    return $converter->convert($markdown)->getContent();
}

/**
 * Prepapre the integration of a Markdown file into info.html.
 *
 * Headers are incremented by one level. File name become an ID.
 *
 * @param string $file_path Full path for the file to use, including extension (should be ".md")
 */
function markdownIntegration($file_path): void
{
    $id = basename($file_path, ".md");
    $html = markdownConverter($file_path);
    $html = mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8');
    $dom = new DOMDocument();
    $dom->loadHTML($html);
    for ($i = 7; $i > 0; $i--) {
        $headers = $dom->getElementsByTagName('h' . $i);
        for ($j = $headers->length; --$j >= 0;) {
            $old_header = $headers->item($j);
            if ($i == 1) {
                //'<h2 name="' . $id . '" id="' . $id . '">
                // ▶ $old_header->nodeValue - <a href="#">[↑]</a></h2>';
                $link = $dom->createElement('a', '[↑]');
                $link->setAttribute('href', '#');
                $new_header = $dom->createElement(
                    'h2',
                    '▶ ' . $old_header->nodeValue . ' - '
                );
                $new_header->setAttribute('id', $id);
                $new_header->setAttribute('name', $id);
                $new_header->appendChild($link);
            } else {
                $new_header = $dom->createElement(
                    'h' . ($i + 1),
                    $old_header->nodeValue
                );
            }
            $old_header->parentNode->replaceChild($new_header, $old_header);
        }
    }
    $output = $dom->saveHTML($dom->lastChild->firstChild);
    // Delete the <body></body> tags
    $output = substr(substr($output, 6), 0, -7);
    echo $output;
}
