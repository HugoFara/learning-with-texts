<?php

/**
 * Word Show View - Displays term details
 *
 * Variables expected:
 * - $word: array - Word details (text, translation, sentence, romanization, status, langId)
 * - $tags: string - Word tags
 * - $scrdir: string - Script direction tag
 * - $ann: string - Annotation to highlight
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Views
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Views\Word;

use Lwt\Shared\Infrastructure\Utilities\StringUtils;
use Lwt\Modules\Vocabulary\Application\Helpers\StatusHelper;

// Type assertions for variables passed from controller
/** @var array{text: string, translation: string, notes?: string, romanization: string, sentence: string, status: int, langId: int} $word */
assert(is_array($word));
assert(is_string($tags));
assert(is_string($scrdir));
assert(is_string($ann));

?>
<table class="table is-bordered is-fullwidth">
<tr>
    <td class="has-text-right word-show-label"><?= __('vocabulary.show.term') ?></td>
    <td class="word-show-term" <?php echo $scrdir; ?>>
        <b><?php echo htmlspecialchars($word['text'] ?? '', ENT_QUOTES, 'UTF-8'); ?></b>
    </td>
</tr>
<tr>
    <td class="has-text-right"><?= __('vocabulary.show.translation') ?></td>
    <td class="word-show-value"><b><?php
    $translationHtml = StringUtils::parseInlineMarkdown($word['translation'] ?? '');
    if (!empty($ann)) {
        // Highlight annotation in the rendered HTML
        echo StringUtils::replaceFirst(
            htmlspecialchars($ann, ENT_QUOTES, 'UTF-8'),
            '<span class="word-show-highlight">' . htmlspecialchars($ann, ENT_QUOTES, 'UTF-8') . '</span>',
            $translationHtml
        );
    } else {
        echo $translationHtml;
    }
    ?></b></td>
</tr>
<?php if (isset($word['notes']) && $word['notes'] !== '') : ?>
<tr>
    <td class="has-text-right"><?= __('vocabulary.show.notes') ?></td>
    <td class="word-show-value"><?php echo StringUtils::parseInlineMarkdown($word['notes']); ?></td>
</tr>
<?php endif; ?>
<?php if ($tags !== '') : ?>
<tr>
    <td class="has-text-right"><?= __('vocabulary.show.tags') ?></td>
    <td class="word-show-value"><?php echo \Lwt\Shared\UI\Helpers\TagHelper::render($tags); ?></td>
</tr>
<?php endif; ?>
<?php if ($word['romanization'] !== '') : ?>
<tr>
    <td class="has-text-right"><?= __('vocabulary.show.romaniz') ?></td>
    <td class="word-show-value">
        <b><?php echo htmlspecialchars($word['romanization'] ?? '', ENT_QUOTES, 'UTF-8'); ?></b>
    </td>
</tr>
<?php endif; ?>
<tr>
    <td class="has-text-right"><?= __('vocabulary.show.sentence_term_in_braces') ?></td>
    <td class="" <?php echo $scrdir; ?>>
        <?php echo htmlspecialchars($word['sentence'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
    </td>
</tr>
<tr>
    <td class="has-text-right"><?= __('vocabulary.show.status') ?></td>
    <td class=""><?php echo StatusHelper::getColoredMessage($word['status']); ?></td>
</tr>
</table>

