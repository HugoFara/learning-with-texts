<?php

/**
 * Create Feed Use Case
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Feed\Application\UseCases
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Feed\Application\UseCases;

use Lwt\Modules\Feed\Domain\Feed;
use Lwt\Modules\Feed\Domain\FeedRepositoryInterface;

/**
 * Use case for creating a new feed.
 *
 * @since 3.0.0
 */
class CreateFeed
{
    /**
     * Constructor.
     *
     * @param FeedRepositoryInterface $feedRepository Feed repository
     */
    public function __construct(
        private FeedRepositoryInterface $feedRepository
    ) {
    }

    /**
     * Execute the use case.
     *
     * @param int    $languageId         Language ID
     * @param string $name               Feed name
     * @param string $sourceUri          Feed source URI
     * @param string $articleSectionTags XPath selectors for article content
     * @param string $filterTags         XPath selectors for elements to remove
     * @param string $options            Feed options string
     *
     * @return Feed The created feed
     */
    public function execute(
        int $languageId,
        string $name,
        string $sourceUri,
        string $articleSectionTags = '',
        string $filterTags = '',
        string $options = ''
    ): Feed {
        $feed = Feed::create(
            $languageId,
            $name,
            $sourceUri,
            $articleSectionTags,
            $filterTags,
            $options
        );

        $this->feedRepository->save($feed);

        return $feed;
    }
}
