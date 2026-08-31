<?php

declare(strict_types=1);

/**
 * Text Check Form View - Form to check text parsing
 *
 * Variables expected:
 * - $languagesOption: string - HTML select options for languages
 * - $languageData: array - Mapping of language ID to language code
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
 * @psalm-suppress UndefinedVariable - Variables are set by the including controller
 *
 * @var string $languagesOption
 * @var array<int, string> $languageData
 */

namespace Lwt\Views\Text;

use Lwt\Shared\UI\Helpers\IconHelper;
use Lwt\Shared\UI\Helpers\ConfigIsland;

// Type-safe variable extraction from controller context
/**
 * @var string $languagesOptionTyped
*/
$languagesOptionTyped = $languagesOption;

/**
 * @var array<int, string> $languageDataTyped
*/
$languageDataTyped = $languageData;
?>
<?php ConfigIsland::render('language-data-config', $languageDataTyped); ?>

<h2 class="title is-4"><?= __e('text.check.heading') ?></h2>

<!--
    Checking goes through POST /api/v1/texts/check (#262), so the form carries
    no action of its own and the report renders in place.
-->
<form class="validate"
      x-data="textCheckForm"
      @submit="handleSubmit($event)">
    <?php echo \Lwt\Shared\UI\Helpers\FormHelper::csrfField(); ?>

    <div x-show="hasError()" class="notification is-danger" x-cloak>
        <span x-text="error"></span>
    </div>
    <div class="box">
        <!-- Language -->
        <div class="field is-horizontal">
            <div class="field-label is-normal">
                <label class="label" for="TxLgID"><?= __e('text.common.language') ?></label>
            </div>
            <div class="field-body">
                <div class="field has-addons">
                    <div class="control is-expanded">
                        <div class="select is-fullwidth">
                            <select name="TxLgID" id="TxLgID" class="notempty setfocus" required>
                                <?php echo $languagesOptionTyped; ?>
                            </select>
                        </div>
                    </div>
                    <div class="control">
                        <span class="icon has-text-danger" title="<?= __e('text.common.field_required') ?>">
                            <?php echo IconHelper::render('asterisk', ['alt' => __('text.common.required')]); ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Text Content -->
        <div class="field is-horizontal">
            <div class="field-label is-normal">
                <label class="label" for="TxText">
                    <?= __e('text.common.text') ?>
                    <span class="has-text-grey is-size-7"><?= __e('text.check.text_max') ?></span>
                </label>
            </div>
            <div class="field-body">
                <div class="field has-addons">
                    <div class="control is-expanded">
                        <textarea name="TxText"
                                  id="TxText"
                                  class="textarea notempty checkbytes checkoutsidebmp"
                                  data_maxlength="65000"
                                  data_info="Text"
                                  rows="15"
                                  placeholder="<?= __e('text.check.text_placeholder') ?>"
                                  required></textarea>
                    </div>
                    <div class="control">
                        <span class="icon has-text-danger" title="<?= __e('text.common.field_required') ?>">
                            <?php echo IconHelper::render('asterisk', ['alt' => __('text.common.required')]); ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Form Actions -->
    <div class="field is-grouped is-grouped-right">
        <div class="control">
            <button type="button"
                    class="button is-light"
                    data-action="navigate"
                    data-url="/">
                <span class="icon is-small">
                    <?php echo IconHelper::render('arrow-left', ['alt' => 'Back']); ?>
                </span>
                <span><?= __e('text.common.back') ?></span>
            </button>
        </div>
        <div class="control">
            <button type="submit" class="button is-primary" :disabled="checking">
                <span class="icon is-small">
                    <?php echo IconHelper::render('check', ['alt' => 'Check']); ?>
                </span>
                <span x-text="submitLabel()"><?= __e('text.common.check') ?></span>
            </button>
        </div>
    </div>

    <!-- The parse report renders here once the API answers. -->
    <div id="check_text" x-show="hasReport" x-cloak class="content mt-5"></div>
</form>
