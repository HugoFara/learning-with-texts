<?php

declare(strict_types=1);

/**
 * Annotated Text Display View
 *
 * A shell only. The annotated text itself is fetched from
 * GET /api/v1/texts/{id}/annotation and rendered by the `textDisplay`
 * Alpine component; see modules/text/pages/text_display_app.ts.
 *
 * Prev/next navigation stays server-rendered: it depends on the request's
 * language filter, search query and tag selection, which live in the session
 * and have no endpoint of their own.
 *
 * Variables expected:
 * - $textId: int - Text ID
 * - $title: string - Text title
 * - $sourceUri: string|null - Source URI
 * - $textLinks: string - Previous/next text navigation links (pre-rendered)
 * - $mediaPlayerHtml: string - Pre-rendered media player HTML
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Views
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.6.1
 *
 * @psalm-suppress UndefinedGlobalVariable Variables are injected by including file
 *
 * @var int $textId
 * @var string $title
 * @var string|null $sourceUri
 * @var string $textLinks
 * @var string $mediaPlayerHtml
 */

namespace Lwt\Views\Text;

use Lwt\Shared\UI\Helpers\ConfigIsland;
use Lwt\Shared\UI\Helpers\IconHelper;

// Type-safe variable extraction from controller context
assert(is_int($textId));
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
assert(is_string($mediaPlayerHtml));

?>
<div style="width: 95%; height: 100%;" x-data="textDisplay" x-cloak>
    <div id="frame-h">
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
                    echo ' <a href="' . \htmlspecialchars($sourceUriTyped, ENT_QUOTES, 'UTF-8')
                        . '" target="_blank" rel="noopener noreferrer">';
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
    </div>
    <hr />
    <div id="frame-l">
        <p x-show="loading"><?= __e('text.read.loading') ?></p>
        <p x-show="error" x-cloak class="has-text-danger" x-text="error"></p>
        <!-- Content rendered by JavaScript via textDisplay.render() -->
        <div id="print" x-show="isReady()" x-cloak></div>
    </div>
</div>

<?php ConfigIsland::render('text-display-config', ['textId' => $textId]); ?>
