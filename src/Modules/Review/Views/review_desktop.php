<?php

/**
 * Desktop Review Layout View
 *
 * Minimal container for client-side rendered review interface.
 * All UI is rendered by Alpine.js.
 *
 * Variables expected:
 * - $config: array - Review configuration (from ReviewController)
 *
 * PHP version 8.2
 *
 * @category Views
 * @package  Lwt
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 *
 * @var array $config
 */

declare(strict_types=1);

namespace Lwt\Views\Review;

use Lwt\Shared\Infrastructure\Utilities\StringUtils;
use Lwt\Shared\UI\Helpers\PageLayoutHelper;
use Lwt\Shared\UI\Helpers\ConfigIsland;

// Type-safe variable extraction from controller context
/**
 * @var array $configTyped
*/
$configTyped = $config;

?>
<!-- Main navigation -->
<?php echo PageLayoutHelper::buildNavbarPlaceholder(); ?>

<!-- Review application root - all UI rendered by Alpine.js -->
<div id="review-app">
<?php /** @psalm-suppress MixedArrayAccess */ if (($config['progress']['total'] ?? 0) === 0) : ?>
  <div class="container py-6">
    <div class="notification is-info is-light has-text-centered">
      <p class="is-size-5 has-text-weight-bold mb-2">
        <?php echo \htmlspecialchars(__('review.no_vocabulary_title'), ENT_QUOTES, 'UTF-8'); ?>
      </p>
      <p class="has-text-grey-dark">
        <?php echo \htmlspecialchars(__('review.no_vocabulary_hint'), ENT_QUOTES, 'UTF-8'); ?>
      </p>
      <div class="buttons is-centered mt-5">
        <a href="/texts" class="button is-primary">
          <?php echo \htmlspecialchars(__('review.back_to_texts'), ENT_QUOTES, 'UTF-8'); ?>
        </a>
      </div>
    </div>
  </div>
<?php else : ?>
  <div class="has-text-centered py-6">
    <p class="has-text-grey"><?php echo \htmlspecialchars(__('review.loading'), ENT_QUOTES, 'UTF-8'); ?></p>
  </div>
<?php endif; ?>
</div>

<!-- Audio elements for feedback -->
<audio id="success_sound" preload="auto">
  <source src="<?php StringUtils::printFilePath("sounds/success.mp3"); ?>" type="audio/mpeg" />
</audio>
<audio id="failure_sound" preload="auto">
  <source src="<?php StringUtils::printFilePath("sounds/failure.mp3"); ?>" type="audio/mpeg" />
</audio>

<!-- Review configuration -->
<?php ConfigIsland::render('review-config', $configTyped); ?>
