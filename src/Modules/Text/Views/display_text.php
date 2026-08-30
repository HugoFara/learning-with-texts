<?php

declare(strict_types=1);

/**
 * Text Display Content View
 *
 * Variables expected:
 * - $annotations: array - Parsed annotation items
 * - $textSize: int - Text size percentage
 * - $rtlScript: bool - Whether text is right-to-left
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
 * @var array<int, array{type: int, text: string, trans?: string, rom?: string}> $annotations
 * @var int $textSize
 * @var bool $rtlScript
 */

namespace Lwt\Views\Text;

// Type-safe variable extraction from controller context
assert(is_array($annotations));
/**
 * @var array<int, array{type: int, text: string, trans?: string, rom?: string}>
*/
$annotationsTyped = $annotations;
/**
 * @var int
*/
$textSizeTyped = $textSize;
/**
 * @var bool
*/
$rtlScriptTyped = $rtlScript;
?>
<div id="print"<?php echo ($rtlScriptTyped ? ' dir="rtl"' : ''); ?>>
<p style="font-size:<?php echo $textSizeTyped; ?>%;line-height: 1.35; margin-bottom: 10px; ">
<?php
foreach ($annotationsTyped as $item) {
    if ($item['type'] > -1) {
        // Regular word with annotation
        $romTitle = ($item['rom'] ?? '') === ''
            ? ''
            : ' title="' . \htmlspecialchars($item['rom'] ?? '', ENT_QUOTES, 'UTF-8') . '"';
        $textEscaped = \htmlspecialchars($item['text'] ?? '', ENT_QUOTES, 'UTF-8');
        $transEscaped = \htmlspecialchars($item['trans'] ?? '', ENT_QUOTES, 'UTF-8');
        echo ' <ruby>
            <rb>
                <span class="click anntermruby" style="color:black;"' . $romTitle . '>' .
                    $textEscaped .
                '</span>
            </rb>
            <rt>
                <span class="click anntransruby2">' . $transEscaped . '</span>
            </rt>
        </ruby> ';
    } else {
        // Punctuation or paragraph marker
        echo str_replace(
            "¶",
            '</p>
            <p style="font-size:' . $textSizeTyped .
            '%;line-height: 1.3; margin-bottom: 10px;">',
            " " . \htmlspecialchars($item['text'] ?? '', ENT_QUOTES, 'UTF-8')
        );
    }
}
?>
</p>
</div>
