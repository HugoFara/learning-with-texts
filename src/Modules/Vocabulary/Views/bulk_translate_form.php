<?php

/**
 * Bulk Translate Form View - Form for bulk translating unknown words
 *
 * Variables expected:
 * - $tid: int - Text ID
 * - $sl: string|null - Source language code
 * - $tl: string|null - Target language code
 * - $pos: int - Current offset position
 * - $dictionaries: array - Dictionary URIs with keys: dict1, dict2, translate
 * - $limit: int - Page size the client should request
 *
 * The term rows themselves are fetched by the bulkTranslateApp component from
 * GET /api/v1/terms/unknown-for-translate.
 *
 * PHP version 8.1
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

use Lwt\Shared\UI\Helpers\IconHelper;

// Type assertions for variables passed from controller
assert(is_int($tid));
assert($sl === null || is_string($sl));
assert($tl === null || is_string($tl));
assert(is_array($dictionaries));
assert(is_int($limit));

$altMarkAll = __('vocabulary.multi.mark_all');
$altMarkNone = __('vocabulary.multi.mark_none');
$lblChangeStatus = htmlspecialchars(__('vocabulary.bulk.change_status'), ENT_QUOTES, 'UTF-8');

?>
<script type="application/json" id="bulk-translate-config">
<?php echo json_encode([
    'dictionaries' => $dictionaries,
    'sourceLanguage' => $sl,
    'targetLanguage' => $tl,
    'textId' => $tid,
    'offset' => $pos,
    'limit' => $limit
], JSON_HEX_TAG | JSON_HEX_AMP); ?></script>
<script type="text/javascript"
        src="//translate.google.com/translate_a/element.js?cb=googleTranslateElementInit"></script>

<form name="form1"
      x-data="bulkTranslateApp()"
      @submit="submitTerms($event)">

    <!-- Save outcome, rendered from the API response -->
    <template x-if="hasSaveError()">
        <div class="notification is-danger">
            <span x-text="saveError"></span>
        </div>
    </template>
    <template x-if="isDone()">
        <div class="notification is-success">
            <span x-text="savedMessage()"></span>
        </div>
    </template>

    <?php echo \Lwt\Shared\UI\Helpers\FormHelper::csrfField(); ?>

    <!-- Controls Panel -->
    <div class="box notranslate mb-4">
        <div id="google_translate_element" class="mb-3"></div>

        <div class="level">
            <div class="level-left">
                <div class="level-item">
                    <div class="buttons are-small">
                        <button type="button"
                                class="button is-info is-outlined"
                                @click="markAll()">
                            <span class="icon is-small">
                                <?php echo IconHelper::render('check-square', ['alt' => $altMarkAll]); ?>
                            </span>
                            <span><?= __('vocabulary.multi.mark_all') ?></span>
                        </button>
                        <button type="button"
                                class="button is-outlined"
                                @click="markNone()">
                            <span class="icon is-small">
                                <?php echo IconHelper::render('square', ['alt' => __('vocabulary.multi.mark_none')]); ?>
                            </span>
                            <span><?= __('vocabulary.multi.mark_none') ?></span>
                        </button>
                    </div>
                </div>
            </div>

            <div class="level-right">
                <div class="level-item">
                    <div class="field has-addons">
                        <div class="control">
                            <span class="button is-static is-small"><?= __('vocabulary.multi.marked_terms') ?></span>
                        </div>
                        <div class="control">
                            <div class="select is-small">
                                <select @change="handleTermToggles($event.target.value);
                                               $event.target.selectedIndex = 0;">
                                    <option value="0" selected><?= __('vocabulary.bulk.choose_placeholder') ?></option>
                                    <optgroup label="<?= $lblChangeStatus ?>">
                                        <?php for ($i = 1; $i <= 5; $i++) : ?>
                                        <option value="<?= $i ?>">
                                            <?= __('vocabulary.bulk.set_status_to', ['n' => $i]) ?>
                                        </option>
                                        <?php endfor; ?>
                                        <option value="99"><?= __('vocabulary.bulk.set_status_wkn') ?></option>
                                        <option value="98"><?= __('vocabulary.bulk.set_status_ign') ?></option>
                                    </optgroup>
                                    <option value="6"><?= __('vocabulary.bulk.set_to_lowercase') ?></option>
                                    <option value="7"><?= __('vocabulary.bulk.delete_translation') ?></option>
                                </select>
                            </div>
                        </div>
                        <div class="control">
                            <button type="submit" class="button is-primary is-small"
                                    :class="saveButtonClass()" :disabled="isSaving">
                                <span class="icon is-small">
                                    <?php echo IconHelper::render('save', ['alt' => __('vocabulary.common.save')]); ?>
                                </span>
                                <span x-text="submitButtonText"><?= __('vocabulary.common.save') ?></span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Terms Table -->
    <div class="table-container">
        <table class="table is-fullwidth is-striped is-hoverable">
            <thead>
                <tr class="notranslate">
                    <th class="has-text-centered" style="width: 60px;"><?= __('vocabulary.common.mark') ?></th>
                    <th style="min-width: 8em;"><?= __('vocabulary.list.col_term') ?></th>
                    <th><?= __('vocabulary.list.col_translation') ?></th>
                    <th class="has-text-centered" style="width: 100px;"><?= __('vocabulary.list.col_status') ?></th>
                </tr>
            </thead>
            <tbody>
                <template x-for="(term, i) in terms" :key="i">
                <tr>
                    <td class="has-text-centered notranslate">
                        <label class="checkbox">
                            <input :name="markedName(i)"
                                   type="checkbox"
                                   class="markcheck"
                                   checked
                                   :value="rowIndex(i)" />
                        </label>
                    </td>
                    <td :id="termCellId(i)" class="notranslate">
                        <span class="term tag is-medium is-light" x-text="term.word"></span>
                    </td>
                    <td class="trans" :id="transCellId(i)" x-text="lowercaseOf(term)"></td>
                    <td class="has-text-centered notranslate">
                        <div class="select is-small">
                            <select :id="statusFieldId(i)" :name="statusName(i)">
                                <option value="1" selected>[1]</option>
                                <option value="2">[2]</option>
                                <option value="3">[3]</option>
                                <option value="4">[4]</option>
                                <option value="5">[5]</option>
                                <option value="99"><?= __e('common.status_well_known') ?></option>
                                <option value="98"><?= __e('common.status_ignored') ?></option>
                            </select>
                        </div>
                        <input type="hidden" :id="textFieldId(i)" :name="textName(i)" :value="term.word" />
                        <input type="hidden" :name="langName(i)" :value="term.Ti2LgID" />
                    </td>
                </tr>
                </template>
            </tbody>
        </table>
    </div>

    <!-- Hidden fields -->
    <input type="hidden" name="tid" value="<?php echo $tid ?>" />
    <template x-if="nextOffset !== null">
        <span>
            <input type="hidden" name="offset" :value="nextOffset" />
            <input type="hidden" name="sl" value="<?php echo htmlspecialchars((string)$sl, ENT_QUOTES) ?>" />
            <input type="hidden" name="tl" value="<?php echo htmlspecialchars((string)$tl, ENT_QUOTES) ?>" />
        </span>
    </template>
</form>
