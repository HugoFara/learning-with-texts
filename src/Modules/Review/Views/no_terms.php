<?php

/**
 * No Terms View - Shows message when no terms available
 *
 * Reached when a review selection has no terms at all, so the controller
 * cannot even work out which language it is about and never gets as far as
 * review_desktop.php. It still has to look like a page: the navbar placeholder
 * and the way out are the whole difference between an empty review and a dead
 * end.
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

namespace Lwt\Views\Review;

use Lwt\Shared\UI\Helpers\PageLayoutHelper;

?>
<!-- Main navigation -->
<?php echo PageLayoutHelper::buildNavbarPlaceholder(); ?>

<div class="container py-6">
  <div class="notification is-info is-light has-text-centered">
    <p class="is-size-5 has-text-weight-bold mb-2">
      <?php echo \htmlspecialchars(__('review.no_terms'), ENT_QUOTES, 'UTF-8'); ?>
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
