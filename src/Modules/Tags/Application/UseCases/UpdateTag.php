<?php

/**
 * Update Tag Use Case
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Tags\Application\UseCases
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Tags\Application\UseCases;

use Lwt\Modules\Tags\Domain\Tag;
use Lwt\Modules\Tags\Domain\TagRepositoryInterface;

/**
 * Use case for updating an existing tag.
 *
 * @since 3.0.0
 */
class UpdateTag
{
    private TagRepositoryInterface $repository;

    /**
     * Constructor.
     *
     * @param TagRepositoryInterface $repository Tag repository
     */
    public function __construct(TagRepositoryInterface $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Execute the update tag use case.
     *
     * @param int    $id      Tag ID
     * @param string $text    New tag text
     * @param string $comment New tag comment
     *
     * @return Tag The updated tag entity
     *
     * @throws \InvalidArgumentException If tag not found or validation fails
     */
    public function execute(int $id, string $text, string $comment): Tag
    {
        $tag = $this->repository->find($id);
        if ($tag === null) {
            throw new \InvalidArgumentException('Tag not found: ' . $id);
        }

        // Check for duplicate (excluding current tag)
        if ($this->repository->textExists($text, $id)) {
            throw new \InvalidArgumentException('Tag "' . $text . '" already exists');
        }

        $tag->rename($text);
        $tag->updateComment($comment);
        $this->repository->save($tag);

        return $tag;
    }

    /**
     * Execute and return result.
     *
     * @param int    $id      Tag ID
     * @param string $text    New tag text
     * @param string $comment New tag comment
     *
     * @return array{success: bool, tag: ?Tag, error: ?string} Result
     */
    public function executeWithResult(int $id, string $text, string $comment): array
    {
        try {
            $tag = $this->execute($id, $text, $comment);
            return ['success' => true, 'tag' => $tag, 'error' => null];
        } catch (\Exception $e) {
            return ['success' => false, 'tag' => null, 'error' => $e->getMessage()];
        }
    }
}
