<?php

/**
 * Language Wizard View
 *
 * Variables expected:
 * - $currentNativeLanguage: string current native language setting
 * - $languageOptions: string HTML options for language select
 * - $languageDefsJson: string JSON-encoded language definitions
 * - $languagePresetsArray: array language presets for searchable select
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Language\Views
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Language\Views;

use Lwt\Shared\UI\Helpers\IconHelper;
use Lwt\Shared\UI\Helpers\SearchableSelectHelper;
use Lwt\Shared\UI\Helpers\ConfigIsland;

// Type assertions for view variables
assert(is_string($languageDefsJson));
assert(is_string($languageOptions));
assert(is_string($languageOptionsEmpty));
assert(is_array($languagePresetsArray));

/**
 * @var string $languageDefsJson
 * @var string $languageOptions
 * @var string $languageOptionsEmpty
 * @var array<int, array{id: int|string, name: string}> $languagePresetsArray
 * @var string $currentNativeLanguage
 */
?>
<?php ConfigIsland::render('language-wizard-config', ['languageDefs' => json_decode($languageDefsJson, true)]); ?>

<section class="section py-5">
    <div class="container" style="max-width: 400px;">
        <!-- Language to study (L2) -->
        <div class="field mb-5">
            <label class="label is-medium" for="l2">
                <?php echo __('language.wizard.l2_label'); ?>
            </label>
            <div class="control">
                <?php echo SearchableSelectHelper::forLanguages(
                    $languagePresetsArray,
                    '',
                    [
                        'name' => 'l2',
                        'id' => 'l2',
                        'placeholder' => __('language.wizard.choose_placeholder'),
                        'required' => false,
                        'size' => 'medium'
                    ]
                ); ?>
            </div>
        </div>

        <!-- Native language (L1) -->
        <div class="field">
            <label class="label is-medium" for="l1">
                <?php echo __('language.wizard.l1_label'); ?>
            </label>
            <div class="control">
                <?php echo SearchableSelectHelper::forLanguages(
                    $languagePresetsArray,
                    $currentNativeLanguage,
                    [
                        'name' => 'l1',
                        'id' => 'l1',
                        'placeholder' => __('language.wizard.choose_placeholder'),
                        'required' => false,
                        'size' => 'medium'
                    ]
                ); ?>
            </div>
        </div>
    </div>
</section>
