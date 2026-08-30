<?php

/**
 * Local Dictionary Import View
 *
 * Variables expected:
 * - $langId: int current language ID
 * - $langName: string current language name
 * - $dictionary: LocalDictionary entity or null
 * - $dictionaries: array of LocalDictionary entities for this language
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Dictionary\Views
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Dictionary\Views;

use Lwt\Modules\Dictionary\Domain\LocalDictionary;
use Lwt\Shared\UI\Helpers\IconHelper;
use Lwt\Shared\UI\Helpers\PageLayoutHelper;

/**
 * @var int $langId
 * @var string $langName
 * @var LocalDictionary|null $dictionary
 * @var array<LocalDictionary> $dictionaries
 * @var string $error
 */
if (!empty($error)) :
    ?>
<div class="notification is-danger is-light mb-4">
    <button class="delete" @click="$el.parentElement.remove()"></button>
    <?php echo htmlspecialchars($error, ENT_QUOTES); ?>
</div>
<?php endif; ?>

<?php
echo PageLayoutHelper::buildActionCard([
    [
        'url' => "/dictionaries?lang=$langId",
        'label' => __('dictionary.back_to_dictionaries'),
        'icon' => 'arrow-left',
    ],
]);
?>

<div class="box" x-data="dictionaryImport()">
    <h3 class="title is-4"><?php echo __('dictionary.import_dictionary'); ?></h3>
    <p class="subtitle is-6"><?php echo __('dictionary.import_dictionary_subtitle'); ?></p>

    <form method="POST" action="/dictionaries/import" enctype="multipart/form-data"
          @submit="submitting = true">
        <?php echo \Lwt\Shared\UI\Helpers\FormHelper::csrfField(); ?>
        <input type="hidden" name="lang_id" value="<?php echo $langId; ?>">

        <!-- Dictionary Selection -->
        <div class="field">
            <label class="label"><?php echo __('dictionary.dictionary'); ?></label>
            <div class="control">
                <?php if ($dictionary) : ?>
                <input type="hidden" name="dict_id" value="<?php echo $dictionary->id(); ?>">
                <input type="text" class="input"
                       value="<?php echo htmlspecialchars($dictionary->name(), ENT_QUOTES); ?>" readonly>
                <p class="help"><?php echo __('dictionary.adding_to_existing'); ?></p>
                <?php elseif (!empty($dictionaries)) : ?>
                <div class="select is-fullwidth">
                    <select name="dict_id">
                        <option value=""><?php echo __('dictionary.create_new_option'); ?></option>
                        <?php foreach ($dictionaries as $dict) : ?>
                        <option value="<?php echo $dict->id(); ?>">
                            <?php echo htmlspecialchars($dict->name(), ENT_QUOTES); ?>
                            (<?php echo number_format($dict->entryCount()); ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php else : ?>
                <p class="help"><?php echo __('dictionary.new_dict_will_be_created'); ?></p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Dictionary Name (for new dictionaries) -->
        <?php if (!$dictionary) : ?>
        <div class="field">
            <label class="label"><?php echo __('dictionary.dictionary_name'); ?></label>
            <div class="control">
                <input type="text" name="dict_name" class="input"
                       placeholder="<?php
                            echo htmlspecialchars(__('dictionary.dictionary_name_example'), ENT_QUOTES);
                        ?>">
            </div>
            <p class="help"><?php echo __('dictionary.auto_generate_help'); ?></p>
        </div>
        <?php endif; ?>

        <!-- File Format -->
        <div class="field">
            <label class="label"><?php echo __('dictionary.file_format'); ?></label>
            <div class="control">
                <div class="select is-fullwidth">
                    <select name="format" x-model="format" @change="resetOptions()">
                        <option value="csv"><?php echo __('dictionary.format_csv'); ?></option>
                        <option value="json"><?php echo __('dictionary.format_json'); ?></option>
                        <option value="stardict"><?php echo __('dictionary.format_stardict'); ?></option>
                    </select>
                </div>
            </div>
        </div>

        <!-- File Upload -->
        <div class="field">
            <label class="label"><?php echo __('dictionary.dictionary_file'); ?></label>
            <div class="file has-name is-fullwidth">
                <label class="file-label">
                    <input class="file-input" type="file" name="file" required
                           @change="fileSelected($event)"
                           :accept="acceptTypes[format]">
                    <span class="file-cta">
                        <span class="file-icon">
                            <?php echo IconHelper::render('upload', ['alt' => __('common.upload')]); ?>
                        </span>
                        <span class="file-label"><?php echo __('dictionary.choose_file'); ?></span>
                    </span>
                    <span class="file-name"
                          x-text="fileName || '<?php
                              echo htmlspecialchars(__('dictionary.no_file_selected'), ENT_QUOTES);
                            ?>'"></span>
                </label>
            </div>
            <p class="help" x-show="format === 'csv'"><?php echo __('dictionary.csv_help'); ?></p>
            <p class="help" x-show="format === 'json'"><?php echo __('dictionary.json_help'); ?></p>
            <p class="help" x-show="format === 'stardict'"><?php echo __('dictionary.stardict_help'); ?></p>
        </div>

        <!-- CSV Options -->
        <div x-show="format === 'csv'" class="box">
            <h5 class="title is-6"><?php echo __('dictionary.csv_options'); ?></h5>

            <div class="field">
                <label class="label"><?php echo __('dictionary.delimiter'); ?></label>
                <div class="control">
                    <div class="select">
                        <select name="delimiter">
                            <option value=","><?php echo __('dictionary.delimiter_comma'); ?></option>
                            <option value="tab"><?php echo __('dictionary.delimiter_tab'); ?></option>
                            <option value=";"><?php echo __('dictionary.delimiter_semicolon'); ?></option>
                            <option value="|"><?php echo __('dictionary.delimiter_pipe'); ?></option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="field">
                <label class="checkbox">
                    <input type="checkbox" name="has_header" value="yes" checked>
                    <?php echo __('dictionary.first_row_header'); ?>
                </label>
            </div>

            <h6 class="title is-6 mt-4"><?php echo __('dictionary.column_mapping'); ?></h6>
            <div class="columns">
                <div class="column is-3">
                    <div class="field">
                        <label class="label"><?php echo __('dictionary.term_column'); ?></label>
                        <div class="control">
                            <input type="number" name="term_column" class="input" value="0" min="0">
                        </div>
                        <p class="help"><?php echo __('dictionary.first_column_help'); ?></p>
                    </div>
                </div>
                <div class="column is-3">
                    <div class="field">
                        <label class="label"><?php echo __('dictionary.definition_column'); ?></label>
                        <div class="control">
                            <input type="number" name="definition_column" class="input" value="1" min="0">
                        </div>
                    </div>
                </div>
                <div class="column is-3">
                    <div class="field">
                        <label class="label"><?php echo __('dictionary.reading_column'); ?></label>
                        <div class="control">
                            <input type="number" name="reading_column" class="input" placeholder="">
                        </div>
                    </div>
                </div>
                <div class="column is-3">
                    <div class="field">
                        <label class="label"><?php echo __('dictionary.pos_column'); ?></label>
                        <div class="control">
                            <input type="number" name="pos_column" class="input" placeholder="">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- JSON Options -->
        <div x-show="format === 'json'" class="box">
            <h5 class="title is-6"><?php echo __('dictionary.json_field_mapping'); ?></h5>
            <p class="mb-3"><?php echo __('dictionary.json_field_help'); ?></p>

            <div class="columns">
                <div class="column is-3">
                    <div class="field">
                        <label class="label"><?php echo __('dictionary.term_field'); ?></label>
                        <div class="control">
                            <input type="text" name="term_field" class="input" placeholder="word">
                        </div>
                    </div>
                </div>
                <div class="column is-3">
                    <div class="field">
                        <label class="label"><?php echo __('dictionary.definition_field'); ?></label>
                        <div class="control">
                            <input type="text" name="definition_field" class="input" placeholder="meaning">
                        </div>
                    </div>
                </div>
                <div class="column is-3">
                    <div class="field">
                        <label class="label"><?php echo __('dictionary.reading_field'); ?></label>
                        <div class="control">
                            <input type="text" name="reading_field" class="input" placeholder="furigana">
                        </div>
                    </div>
                </div>
                <div class="column is-3">
                    <div class="field">
                        <label class="label"><?php echo __('dictionary.pos_field'); ?></label>
                        <div class="control">
                            <input type="text" name="pos_field" class="input" placeholder="pos">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- StarDict Info -->
        <div x-show="format === 'stardict'" class="box">
            <h5 class="title is-6"><?php echo __('dictionary.stardict_format'); ?></h5>
            <p><?php echo __('dictionary.stardict_intro'); ?></p>
            <ul class="mt-2 mb-2">
                <li><strong>.ifo</strong> - <?php echo __('dictionary.stardict_ifo'); ?></li>
                <li><strong>.idx</strong> - <?php echo __('dictionary.stardict_idx'); ?></li>
                <li><strong>.dict</strong> / <strong>.dict.dz</strong> -
                    <?php echo __('dictionary.stardict_dict'); ?></li>
            </ul>
            <p class="has-text-info"><?php echo __('dictionary.stardict_archive_required'); ?></p>
            <p class="help mt-2"><?php echo __('dictionary.stardict_archive_formats'); ?></p>
        </div>

        <!-- Submit -->
        <div class="field mt-5">
            <div class="control">
                <button type="submit" class="button is-primary is-medium"
                        :disabled="submitting || !fileName"
                        :class="{'is-loading': submitting}">
                    <?php echo IconHelper::render('upload', ['alt' => __('common.import')]); ?>
                    <?php echo __('dictionary.import_dictionary'); ?>
                </button>
            </div>
        </div>
    </form>
</div>
