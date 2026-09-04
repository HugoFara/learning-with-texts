<?php

/**
 * What importing a dictionary file did.
 *
 * A view rather than a helper call because this is the one notification on the
 * import page that carries markup -- the dictionary's own name, emphasised --
 * and so needs somewhere the escaping is visible.
 *
 * Expected variables:
 * - $dictName:     string What the dictionary was called
 * - $entryCount:   int    Entries added to it
 * - $vocabCreated: int    Vocabulary terms created from those entries
 * - $skipped:      int    Entries dropped for an over-long headword
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Vocabulary\Views
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.7.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Vocabulary\Views;

// Extracted from the controller's data array, so their types are asserted here
// rather than declared -- the same shape every view in this module uses.
assert(is_string($dictName));
assert(is_int($entryCount));
assert(is_int($vocabCreated));
assert(is_int($skipped));

// The name is the only untrusted part, so it is escaped before it reaches the
// translator -- which interpolates by plain string replacement and would not
// escape it. The <strong> is ours, which is why the key is suffixed _html.
$emphasisedName = '<strong>' . htmlspecialchars($dictName, ENT_QUOTES, 'UTF-8') . '</strong>';

// One whole sentence per case rather than " and N vocabulary terms" appended
// to another: a translator cannot reorder a sentence assembled by the code.
$summary = $vocabCreated > 0
    ? __('vocabulary.upload.dict.result_created_with_vocab_html', [
        'name' => $emphasisedName,
        'count' => number_format($entryCount),
        'vocab' => number_format($vocabCreated),
    ])
    : __('vocabulary.upload.dict.result_created_html', [
        'name' => $emphasisedName,
        'count' => number_format($entryCount),
    ]);

// Reported rather than left silent, so the count cannot differ from the source
// dictionary's own total with no explanation.
$skippedNotice = $skipped > 0
    ? __('vocabulary.upload.dict.result_skipped', ['count' => number_format($skipped)])
    : '';
?>
<div class="notification is-success" data-auto-hide="true">
    <button class="delete" aria-label="close"></button>
    <?= $summary ?>
    <?php if ($skippedNotice !== '') : ?>
        <?= htmlspecialchars($skippedNotice, ENT_QUOTES, 'UTF-8') ?>
    <?php endif; ?>
</div>
