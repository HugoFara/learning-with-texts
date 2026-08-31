<?php

/**
 * Starter Vocabulary Import View
 *
 * Offers to import common words from the FrequencyWords project
 * after language creation, with optional enrichment from Wiktionary.
 * Also supports one-click curated dictionary import.
 *
 * Expected variables:
 * - $langName: string - Language display name
 * - $langId: int - Language ID
 * - $isAvailable: bool - Whether frequency data exists for this language
 * - $skipUrl: string - URL to skip and go to text creation
 * - $importUrl: string - AJAX endpoint for importing words
 * - $enrichUrl: string - AJAX endpoint for enrichment
 * - $csrfToken: string - CSRF token for POST requests (field: _csrf_token)
 * - $curatedDictionaries: list<array<string, mixed>> - Curated dictionaries for this language
 *
 * PHP version 8.2
 */

declare(strict_types=1);

use Lwt\Shared\UI\Helpers\IconHelper;
use Lwt\Shared\UI\Helpers\ConfigIsland;

/** @var string $langName */
/** @var int $langId */
/** @var bool $isAvailable */
/** @var string $skipUrl */
/** @var string $importUrl */
/** @var string $enrichUrl */
/** @var string $csrfToken */
/** @var list<array<string, mixed>> $curatedDictionaries */

$escapedLangName = htmlspecialchars($langName, ENT_QUOTES, 'UTF-8');
$escapedSkipUrl = htmlspecialchars($skipUrl, ENT_QUOTES, 'UTF-8');
$escapedVocabUrl = htmlspecialchars(url('/words') . '?filterlang=' . (string) $langId, ENT_QUOTES, 'UTF-8');

$downloadIcon = IconHelper::render('download', ['alt' => 'Import', 'size' => 14]);
$externalLinkIcon = IconHelper::render('external-link', ['alt' => 'Download', 'size' => 14]);

?>
<?php ConfigIsland::render('starter-vocab-config', [
    'importUrl' => $importUrl,
    'enrichUrl' => $enrichUrl,
    'csrfToken' => $csrfToken,
    'langId' => $langId,
    'curatedDictionaries' => $curatedDictionaries,
    'isAvailable' => $isAvailable,
]); ?>

<div x-data="starterVocab" class="container" style="max-width: 640px;">
    <h2 class="title is-4 mb-4"><?= __e('vocabulary.starter.title') ?></h2>

    <?php if (!$isAvailable && empty($curatedDictionaries)) : ?>
    <div class="notification is-warning">
        <?= __('vocabulary.starter.not_available_html', ['lang' => $escapedLangName]) ?>
    </div>
    <a class="button is-primary" href="<?= $escapedSkipUrl ?>">
        <?= __e('vocabulary.starter.continue_to_text') ?>
    </a>
    <?php else : ?>
    <!-- Step 1: Choose sources and options -->
    <template x-if="step === 'choose'">
        <div class="box">
            <p class="mb-4">
                <?= __('vocabulary.starter.intro_html', ['lang' => $escapedLangName]) ?>
            </p>

            <div class="field">
                <label class="label"><?= __e('vocabulary.starter.enrichment_mode') ?></label>
                <div class="control">
                    <label class="radio">
                        <input type="radio" x-model="mode" value="translation">
                        <?= __e('vocabulary.starter.translation') ?>
                        <span class="has-text-grey is-size-7"><?= __e('vocabulary.starter.translation_hint') ?></span>
                    </label>
                </div>
                <div class="control mt-1">
                    <label class="radio">
                        <input type="radio" x-model="mode" value="definition">
                        <?= __e('vocabulary.starter.definition') ?>
                        <span class="has-text-grey is-size-7"><?= __e('vocabulary.starter.definition_hint') ?></span>
                    </label>
                </div>
            </div>

            <hr>
            <label class="label"><?= __e('vocabulary.starter.sources') ?></label>

            <?php if ($isAvailable) : ?>
            <!-- Wiktionary frequency words source -->
            <label class="box mb-3 p-4" style="cursor: pointer;"
                   :class="useWiktionary ? 'has-background-success-light' : ''">
                <div class="is-flex is-align-items-center">
                    <input type="checkbox" class="mr-3"
                           :checked="useWiktionary"
                           @change="toggleWiktionary()">
                    <div class="is-flex-grow-1">
                        <p class="has-text-weight-semibold mb-1">
                            <?= __e('vocabulary.starter.most_common') ?>
                        </p>
                        <p class="is-size-7 has-text-grey mb-2">
                            <?= __('vocabulary.starter.most_common_help_html') ?>
                        </p>
                        <template x-if="useWiktionary">
                            <div class="field">
                                <label class="label is-small">
                                    <?= __e('vocabulary.starter.how_many') ?>
                                </label>
                                <div class="buttons has-addons are-small">
                                    <button :class="sizeClass(50)"
                                            @click="setSize(50)">50</button>
                                    <button :class="sizeClass(100)"
                                            @click="setSize(100)">100</button>
                                    <button :class="sizeClass(500)"
                                            @click="setSize(500)">500</button>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>
            </label>
            <?php endif; ?>

            <!-- Curated dictionaries -->
            <template x-for="source in dictSources" :key="source.name">
                <label class="box mb-3 p-4" style="cursor: pointer;"
                       :class="isSourceSelected(source.url) ? 'has-background-success-light' : ''">
                    <div class="is-flex is-align-items-center">
                        <input type="checkbox" class="mr-3"
                               :checked="isSourceSelected(source.url)"
                               :disabled="!source.directDownload"
                               @change="toggleSource(source.url)">
                        <div class="is-flex-grow-1">
                            <p class="has-text-weight-semibold mb-1" x-text="source.name"></p>
                            <div class="tags mb-1">
                                <span class="tag is-light" x-text="source.entries"></span>
                                <span class="tag is-info is-light" x-text="source.format"></span>
                                <span class="tag is-success is-light" x-text="source.license"></span>
                                <span class="tag is-primary is-light"
                                      x-text="dictTypeLabel(source)"></span>
                            </div>
                            <p class="is-size-7 has-text-grey" x-text="source.notes"></p>
                            <p class="is-size-7 has-text-warning-dark" x-show="!source.directDownload">
                                <?= __e('vocabulary.starter.manual_download') ?>
                                <a :href="source.url" target="_blank" rel="noopener">
                                    <?= __e('vocabulary.starter.visit_site') ?> <?= $externalLinkIcon ?>
                                </a>
                            </p>
                        </div>
                    </div>
                </label>
            </template>

            <div class="field is-grouped mt-5">
                <div class="control">
                    <button class="button is-success"
                            :disabled="!canImport()"
                            @click="startImport()">
                        <?= $downloadIcon ?>
                        <span class="ml-1"><?= __e('vocabulary.starter.import') ?></span>
                    </button>
                </div>
                <div class="control">
                    <a class="button" href="<?= $escapedSkipUrl ?>">
                        <?= __e('vocabulary.starter.skip') ?>
                    </a>
                </div>
            </div>
        </div>
    </template>

    <!-- Step 2: Importing frequency words -->
    <template x-if="step === 'importing'">
        <div class="box">
            <p class="mb-3">
                <strong><?= __e('vocabulary.starter.fetching') ?></strong>
            </p>
            <progress class="progress is-info" max="100"></progress>
            <p class="has-text-grey is-size-7">
                <?= __e('vocabulary.starter.fetching_help') ?>
            </p>
        </div>
    </template>

    <!-- Step 3: Enriching with translations/definitions -->
    <template x-if="step === 'enriching'">
        <div class="box">
            <p class="mb-3">
                <strong x-text="enrichingLabel()"></strong>
            </p>
            <progress class="progress is-success" :value="enrichProgress" max="100"></progress>
            <p class="is-size-7 mb-3">
                <span x-text="enrichStats.done"></span> <?= __e('vocabulary.starter.of') ?>
                <span x-text="enrichStats.total"></span> <?= __e('vocabulary.starter.words_enriched') ?>
                <template x-if="enrichStats.failed > 0">
                    <span class="has-text-grey">(<span x-text="enrichStats.failed"></span>
                        <?= __e('vocabulary.starter.not_found') ?>)</span>
                </template>
            </p>

            <!-- Warning message -->
            <template x-if="enrichWarning">
                <div class="notification is-warning is-light is-size-7 p-3 mb-3" x-text="enrichWarning"></div>
            </template>

            <div class="field is-grouped">
                <div class="control">
                    <button class="button is-warning is-small" @click="stopEnrichment()">
                        <?= __e('vocabulary.starter.stop_continue') ?>
                    </button>
                </div>
            </div>
        </div>
    </template>

    <!-- Step 4: Importing curated dictionaries -->
    <template x-if="step === 'dictImporting'">
        <div class="box">
            <p class="mb-3">
                <strong><?= __e('vocabulary.starter.importing_dicts') ?></strong>
            </p>
            <progress class="progress is-info"
                      :value="dictBatchCurrent" :max="dictBatchTotal"></progress>
            <p class="is-size-7 has-text-grey">
                <?= __e('vocabulary.starter.dictionary') ?> <span x-text="dictBatchCurrent"></span>
                <?= __e('vocabulary.starter.of') ?> <span x-text="dictBatchTotal"></span>
            </p>
        </div>
    </template>

    <!-- Step 5: Done -->
    <template x-if="step === 'done'">
        <div class="box">
            <div class="notification is-success is-light">
                <template x-if="wiktResult.imported > 0 || wiktResult.skipped > 0">
                    <p>
                        <?= __e('vocabulary.starter.imported') ?>
                        <strong x-text="wiktResult.imported"></strong>
                        <?= __e('vocabulary.starter.words') ?>
                        <template x-if="wiktResult.skipped > 0">
                            <span>(<span x-text="wiktResult.skipped"></span>
                                <?= __e('vocabulary.starter.already_existed') ?>)</span>
                        </template>
                        <?= __e('vocabulary.starter.for_lang') ?> <strong><?= $escapedLangName ?></strong>.
                    </p>
                </template>
                <template x-if="enrichStats.done > 0">
                    <p class="mt-1">
                        <span x-text="enrichStats.done"></span>
                        <?= __e('vocabulary.starter.enriched_with') ?>
                        <span x-text="enrichedModeLabel()"></span>.
                    </p>
                </template>
                <template x-for="(msg, i) in dictMessages" :key="i">
                    <p class="mt-1" :class="msg.success ? '' : 'has-text-danger'" x-text="msg.text"></p>
                </template>
            </div>

            <div class="field is-grouped">
                <div class="control">
                    <a class="button is-primary" href="<?= $escapedSkipUrl ?>">
                        <?= __e('vocabulary.starter.continue_to_text') ?>
                    </a>
                </div>
                <div class="control">
                    <a class="button" href="<?= $escapedVocabUrl ?>">
                        <?= __e('vocabulary.starter.view_vocabulary') ?>
                    </a>
                </div>
            </div>
        </div>
    </template>

    <!-- Error state -->
    <template x-if="step === 'error'">
        <div class="box">
            <div class="notification is-danger is-light">
                <strong><?= __e('vocabulary.starter.import_failed') ?></strong>
                <span x-text="errorMessage"></span>
            </div>
            <div class="field is-grouped">
                <div class="control">
                    <button class="button" @click="retryImport()">
                        <?= __e('vocabulary.starter.try_again') ?>
                    </button>
                </div>
                <div class="control">
                    <a class="button" href="<?= $escapedSkipUrl ?>">
                        <?= __e('vocabulary.starter.skip') ?>
                    </a>
                </div>
            </div>
        </div>
    </template>

    <?php endif; ?>
</div>
