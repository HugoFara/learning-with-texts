<?php

/**
 * Recovery Code View — shown exactly once.
 *
 * Variables expected:
 * - $recoveryCode: string    The plaintext recovery code to display.
 * - $recoveryContext: string 'register' (after sign-up) or 'reset' (after a
 *                            recovery-code reset). Controls the wording.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\User\Views
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @since    3.1.2
 */

declare(strict_types=1);

namespace Lwt\Modules\User\Views;

// Variables are extracted from the controller and may not be set here.
/** @psalm-suppress TypeDoesNotContainNull, RedundantCondition, DocblockTypeContradiction */
$recoveryCode = isset($recoveryCode) && is_string($recoveryCode) ? $recoveryCode : '';
/** @psalm-suppress TypeDoesNotContainNull, RedundantCondition, DocblockTypeContradiction */
$recoveryContext = isset($recoveryContext) && is_string($recoveryContext) ? $recoveryContext : 'register';

$t = static fn(string $key): string => htmlspecialchars(__('user.' . $key), ENT_QUOTES, 'UTF-8');
$intro = $recoveryContext === 'reset' ? $t('recovery.intro_reset') : $t('recovery.intro_register');
?>

<section class="section">
    <div class="container">
        <div class="columns is-centered">
            <div class="column is-5-tablet is-4-desktop">
                <div class="box">
                    <div class="has-text-centered mb-5">
                        <h1 class="title is-3">
                            <span class="icon-text">
                                <span class="icon has-text-primary">
                                    <i data-lucide="key"></i>
                                </span>
                                <span><?php echo $t('recovery.page_title'); ?></span>
                            </span>
                        </h1>
                        <p class="subtitle is-6 has-text-grey"><?php echo $intro; ?></p>
                    </div>

                    <div class="notification is-warning is-light">
                        <span class="icon-text">
                            <span class="icon"><i data-lucide="triangle-alert"></i></span>
                            <span><?php echo $t('recovery.warning'); ?></span>
                        </span>
                    </div>

                    <!-- The code itself: large, monospace, selectable. -->
                    <div class="field">
                        <label class="label"><?php echo $t('recovery.code_label'); ?></label>
                        <div class="control">
                            <input
                                type="text"
                                class="input is-medium has-text-centered has-text-weight-semibold"
                                style="font-family: monospace; letter-spacing: 0.1em;"
                                value="<?php echo htmlspecialchars($recoveryCode, ENT_QUOTES, 'UTF-8'); ?>"
                                readonly
                            >
                        </div>
                        <p class="help"><?php echo $t('recovery.code_help'); ?></p>
                    </div>

                    <div class="field">
                        <div class="control">
                            <a href="/login" class="button is-primary is-fullwidth">
                                <span class="icon"><i data-lucide="check"></i></span>
                                <span><?php echo $t('recovery.saved_continue'); ?></span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
