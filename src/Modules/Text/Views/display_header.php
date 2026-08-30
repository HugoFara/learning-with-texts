<?php

declare(strict_types=1);

/**
 * Text Display Header View
 *
 * Variables expected:
 * - $title: string - Text title
 * - $textId: int - Text ID
 * - $sourceUri: string|null - Source URI
 * - $textLinks: string - Previous/next text navigation links
 * - $mediaPlayerHtml: string - Pre-rendered media player HTML
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Views
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 *
 * @psalm-suppress UndefinedGlobalVariable Variables are injected by including file
 *
 * @var string $title
 * @var int $textId
 * @var string|null $sourceUri
 * @var string $textLinks
 * @var string $mediaPlayerHtml
 */

namespace Lwt\Views\Text;

// Variables injected from text_display_header.php:
// $title, $audio, $sourceUri, $textLinks

use Lwt\Shared\UI\Helpers\IconHelper;

// Type-safe variable extraction from controller context
/**
 * @var string $titleTyped
*/
$titleTyped = $title;
/**
 * @var string|null $sourceUriTyped
*/
$sourceUriTyped = $sourceUri;
/**
 * @var string $textLinksTyped
*/
$textLinksTyped = $textLinks;
/**
 * @var string $mediaPlayerHtml
*/
assert(is_string($mediaPlayerHtml));
?>
<h1><?php echo \htmlspecialchars($titleTyped, ENT_QUOTES, 'UTF-8'); ?></h1>
<div class="flex-spaced">
    <div>
        <span id="hidet" class="click" data-action="hide-translations">
            <?php
            echo IconHelper::render('lightbulb', [
                'title' => __('text.display.toggle_text_on'),
                'alt' => __('text.display.toggle_text_on'),
                'class' => 'click'
            ]);
            ?>
        </span>
        <span id="showt" style="display:none;" class="click" data-action="show-translations">
            <?php
            echo IconHelper::render('lightbulb-off', [
                'title' => __('text.display.toggle_text_off'),
                'alt' => __('text.display.toggle_text_off'),
                'class' => 'click'
            ]);
            ?>
        </span>
        <span id="hide" class="click" data-action="hide-annotations">
            <?php
            echo IconHelper::render('lightbulb', [
                'title' => __('text.display.toggle_annotation_on'),
                'alt' => __('text.display.toggle_annotation_on'),
                'class' => 'click'
            ]);
            ?>
        </span>
        <span id="show" style="display:none;" class="click" data-action="show-annotations">
            <?php
            echo IconHelper::render('lightbulb-off', [
                'title' => __('text.display.toggle_annotation_off'),
                'alt' => __('text.display.toggle_annotation_off'),
                'class' => 'click'
            ]);
            ?>
        </span>
    </div>
    <div>
        <?php
        if ($sourceUriTyped !== null && $sourceUriTyped !== '') {
            echo ' <a href="' . $sourceUriTyped . '" target="_blank">';
            $textSourceLabel = __('text.display.text_source');
            echo IconHelper::render('link', ['title' => $textSourceLabel, 'alt' => $textSourceLabel]);
            echo '</a>';
        }
        echo $textLinksTyped;
        ?>
    </div>
    <div>
        <span class="click" data-action="close-window">
            <?php
            $closeLabel = __('text.display.close_window');
            echo IconHelper::render(
                'x',
                ['title' => $closeLabel, 'alt' => $closeLabel, 'class' => 'click']
            );
            ?>
        </span>
    </div>
</div>
<?php echo $mediaPlayerHtml; ?>
