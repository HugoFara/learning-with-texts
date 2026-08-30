<?php

declare(strict_types=1);

/**
 * Unified print view using Alpine.js.
 *
 * Variables expected:
 * - $textId: int - Text ID
 * - $mode: string - Mode: 'plain', 'annotated', or 'edit'
 * - $viewData: array - View data (title, sourceUri, textSize, rtlScript, etc.)
 * - $savedAnn: int - Saved annotation flags
 * - $savedStatus: int - Saved status range
 * - $savedPlacement: int - Saved annotation placement
 * - $editFormHtml: string|null - Pre-rendered edit form HTML (for edit mode)
 *
 * @category User_Interface
 * @package  Lwt
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 *
 * @var int $textId
 * @var string $mode
 * @var array{title: string, sourceUri: string, audioUri: string,
 *           textSize: int, rtlScript: bool, hasAnnotation: bool} $viewData
 * @var int $savedAnn
 * @var int $savedStatus
 * @var int $savedPlacement
 * @var string|null $editFormHtml
 */

namespace Lwt\Modules\Text\Views;

use Lwt\Shared\UI\Helpers\SelectOptionsBuilder;
use Lwt\Shared\UI\Helpers\FormHelper;
use Lwt\Shared\UI\Helpers\PageLayoutHelper;
use Lwt\Shared\UI\Helpers\IconHelper;
use Lwt\Shared\UI\Helpers\ConfigIsland;

// Type-safe variable extraction from controller context
assert(is_int($textId));
/**
 * @var int $textId
*/
assert(is_string($mode));
/**
 * @var string $mode
*/
assert(is_array($viewData));
/**
 * @var array{title: string, sourceUri: string, audioUri: string,
 *           textSize: int, rtlScript: bool, hasAnnotation: bool} $viewData
*/
assert(is_int($savedAnn));
/**
 * @var int $savedAnn
*/
assert(is_int($savedStatus));
/**
 * @var int $savedStatus
*/
assert(is_int($savedPlacement));
/**
 * @var int $savedPlacement
*/
/**
 * @var string|null $editFormHtml
*/
assert(is_string($navLinksHtml));
/**
 * @var string $navLinksHtml
*/
assert(is_string($annotationLinkHtml));
/**
 * @var string $annotationLinkHtml
*/

$title = $viewData['title'];
$sourceUri = $viewData['sourceUri'];
$audioUri = $viewData['audioUri'];
$textSize = $viewData['textSize'];
$rtlScript = $viewData['rtlScript'];
$hasAnnotation = $viewData['hasAnnotation'];

?>
<!-- Alpine.js container -->
<div x-data="textPrintApp()" x-init="init()" x-cloak>

    <!-- Header (noprint) -->
    <div class="noprint">
        <div class="flex-header">
            <div>
                <?php echo PageLayoutHelper::buildLogo(); ?>
            </div>
            <div>
                <?php echo $navLinksHtml; ?>
            </div>
            <div>
                <a href="/text/<?php echo $textId; ?>/read" target="_top">
                    <?php echo IconHelper::render('book-open', ['title' => 'Read', 'alt' => 'Read']); ?>
                </a>
                <a href="/review?text=<?php echo $textId; ?>" target="_top">
                    <?php echo IconHelper::render('circle-help', ['title' => 'Review', 'alt' => 'Review']); ?>
                </a>
                <?php if ($mode !== 'edit') : ?>
                    <?php echo $annotationLinkHtml; ?>
                <?php endif; ?>
                <a target="_top" href="/texts/<?php echo $textId; ?>/edit">
                    <?php echo IconHelper::render('file-pen', ['title' => 'Edit Text', 'alt' => 'Edit Text']); ?>
                </a>
            </div>
            <div>
                <?php echo PageLayoutHelper::buildNavbarPlaceholder(); ?>
            </div>
        </div>

        <!-- Page title -->
        <h1>
            <?php if ($mode === 'plain') : ?>
                PRINT &#9654;
            <?php else : ?>
                ANN.TEXT &#9654;
            <?php endif; ?>
            <?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?>
            <?php if ($sourceUri !== '' && substr(trim($sourceUri), 0, 1) !== '#') : ?>
                <a href="<?php echo htmlspecialchars($sourceUri, ENT_QUOTES, 'UTF-8'); ?>" target="_blank">
                    <?php echo IconHelper::render('link', ['title' => 'Text Source', 'alt' => 'Text Source']); ?>
                </a>
            <?php endif; ?>
        </h1>

        <!-- Loading state -->
        <div x-show="loading" class="has-text-centered py-6">
            <span class="icon is-large">
                <i data-lucide="loader-2" class="icon-spin"></i>
            </span>
            <p class="mt-2">Loading...</p>
        </div>

        <?php if ($mode === 'plain') : ?>
        <!-- Plain print options -->
        <div x-show="!loading" class="card mb-4" id="printoptions" data-text-id="<?php echo $textId; ?>">
            <div class="card-content">
                <p class="mb-3">
                    Terms with <strong>status(es)</strong>
                    <span class="select is-small">
                        <select x-model="statusFilter" @change="handleStatusChange($event)">
                            <?php echo SelectOptionsBuilder::forWordStatus($savedStatus, true, true, false); ?>
                        </select>
                    </span>
                    ...
                </p>
                <p class="mb-3">
                    will be <strong>annotated</strong> with
                    <span class="select is-small">
                        <select x-model="annotationFlags" @change="handleAnnotationChange($event)">
                            <option value="0"
                                <?php echo FormHelper::getSelected(0, $savedAnn); ?>>Nothing</option>
                            <option value="1"
                                <?php echo FormHelper::getSelected(1, $savedAnn); ?>>Translation</option>
                            <option value="5"
                                <?php echo FormHelper::getSelected(5, $savedAnn); ?>
                                >Translation &amp; Tags</option>
                            <option value="2"
                                <?php echo FormHelper::getSelected(2, $savedAnn); ?>>Romanization</option>
                            <option value="3"
                                <?php echo FormHelper::getSelected(3, $savedAnn); ?>
                                >Romanization &amp; Translation</option>
                            <option value="7"
                                <?php echo FormHelper::getSelected(7, $savedAnn); ?>
                                >Romanization, Translation &amp; Tags</option>
                        </select>
                    </span>
                    <span class="select is-small">
                        <select x-model="placementMode" @change="handlePlacementChange($event)">
                            <option value="0"
                                <?php echo FormHelper::getSelected(0, $savedPlacement); ?>
                                >behind</option>
                            <option value="1"
                                <?php echo FormHelper::getSelected(1, $savedPlacement); ?>
                                >in front of</option>
                            <option value="2"
                                <?php echo FormHelper::getSelected(2, $savedPlacement); ?>
                                >above (ruby)</option>
                        </select>
                    </span>
                    the term.
                </p>
                <div class="buttons">
                    <button type="button" class="button is-primary" @click="handlePrint()">
                        <?php echo IconHelper::render('printer', ['size' => 16]); ?>
                        <span class="ml-1">Print it!</span>
                    </button>
                    <span class="is-size-7 ml-2">(only the text below the line)</span>
                </div>
                <div class="mt-3">
                    <?php if ($hasAnnotation) : ?>
                        Or
                        <button type="button" class="button is-small"
                            @click="navigateTo('/text/<?php echo $textId; ?>/print')">
                            Print/Edit/Delete
                        </button>
                        your <strong>Improved Annotated Text</strong>
                        <?php echo $annotationLinkHtml; ?>.
                    <?php else : ?>
                        <button type="button" class="button is-small"
                            @click="navigateTo('/text/<?php echo $textId; ?>/print/edit')">
                            Create
                        </button>
                        an <strong>Improved Annotated Text</strong>
                        [<?php
                            echo IconHelper::render(
                                'check',
                                ['title' => 'Annotated Text', 'alt' => 'Annotated Text']
                            );
                            ?>].
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php elseif ($mode === 'annotated') : ?>
        <!-- Annotated display options -->
        <div x-show="!loading" class="card mb-4" id="printoptions" data-text-id="<?php echo $textId; ?>">
            <header class="card-header">
                <p class="card-header-title">Improved Annotated Text (Display/Print Mode)</p>
            </header>
            <div class="card-content">
                <div class="buttons">
                    <button type="button" class="button"
                        @click="navigateTo('/text/<?php echo $textId; ?>/print/edit')">
                        <?php echo IconHelper::render('pencil', ['size' => 16]); ?>
                        <span class="ml-1">Edit</span>
                    </button>
                    <button type="button" class="button is-danger is-outlined"
                        @click="confirmDeleteAnnotation(<?php echo $textId; ?>, 'Are you sure?')">
                        <?php echo IconHelper::render('trash-2', ['size' => 16]); ?>
                        <span class="ml-1">Delete</span>
                    </button>
                    <button type="button" class="button is-primary" @click="handlePrint()">
                        <?php echo IconHelper::render('printer', ['size' => 16]); ?>
                        <span class="ml-1">Print</span>
                    </button>
                    <button type="button" class="button"
                        @click="openWindow('/text/display?text=<?php echo $textId; ?>')">
                        <?php echo IconHelper::render('external-link', ['size' => 16]); ?>
                        <span class="ml-1">Display<?php
                            echo ($audioUri !== '' ? ' with Audio Player' : '');
                        ?> in new Window</span>
                    </button>
                </div>
            </div>
        </div>
        <?php else : ?>
        <!-- Edit mode options -->
        <div x-show="!loading" class="card mb-4" id="printoptions">
            <header class="card-header">
                <p class="card-header-title">
                    Improved Annotated Text (Edit Mode)
                    <a href="docs/info.html#il" target="_blank" class="ml-2">
                        <?php echo IconHelper::render('help-circle', ['title' => 'Help', 'alt' => 'Help']); ?>
                    </a>
                </p>
            </header>
            <div class="card-content">
                <button type="button" class="button" @click="navigateTo('/text/<?php echo $textId; ?>/print')">
                    Display/Print Mode
                </button>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <!-- End noprint -->

    <!-- Print content -->
    <?php if ($mode === 'plain') : ?>
    <div x-show="!loading" id="print" <?php echo ($rtlScript ? 'dir="rtl"' : ''); ?>>
        <h2 x-text="getConfigTitle('<?php
            echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        ?>')"></h2>
        <p :style="'font-size:' + getConfigTextSize(<?php echo $textSize; ?>)
            + '%; line-height: 1.35; margin-bottom: 10px;'">
            <template x-for="item in items" :key="item.position">
                <span x-effect="setItemHtml($el, item)"></span>
            </template>
        </p>
    </div>
    <?php elseif ($mode === 'annotated') : ?>
    <div x-show="!loading" id="print" <?php echo ($rtlScript ? 'dir="rtl"' : ''); ?>>
        <p :style="'font-size:' + getAnnConfigTextSize(<?php echo $textSize; ?>)
            + '%; line-height: 1.35; margin-bottom: 10px;'">
            <span x-text="getAnnConfigTitle('<?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?>')"></span>
            <br /><br />
            <template x-if="annItems">
                <template x-for="item in annItems" :key="item.order + '-' + item.text">
                    <span x-effect="setAnnotationItemHtml($el, item)"></span>
                </template>
            </template>
            <template x-if="!annItems">
                <span class="has-text-grey">No annotation found.</span>
            </template>
        </p>
    </div>
    <?php else : ?>
    <!-- Edit mode content -->
    <div x-show="!loading" id="print">
        <?php if (isset($editFormHtml) && $editFormHtml !== null) : ?>
            <div data_id="<?php echo $textId; ?>" id="editimprtextdata">
                <?php echo $editFormHtml; ?>
            </div>
        <?php else : ?>
            <p>No annotated text found, and creation seems not possible.</p>
        <?php endif; ?>
    </div>
    <div class="noprint mt-4">
        <button type="button" class="button" @click="navigateTo('/text/<?php echo $textId; ?>/print')">
            Display/Print Mode
        </button>
    </div>
    <?php endif; ?>
</div>

<!-- Config for Alpine -->
<?php ConfigIsland::render('print-config', [
    'textId' => $textId,
    'mode' => $mode,
    'savedAnn' => $savedAnn,
    'savedStatus' => $savedStatus,
    'savedPlacement' => $savedPlacement
    ]); ?>
