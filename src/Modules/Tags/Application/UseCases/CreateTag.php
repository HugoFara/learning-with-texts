<?php

/**
 * Create Tag Use Case
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
use Lwt\Modules\Tags\Domain\TagType;

/**
 * Use case for creating a new tag.
 *
 * @since 3.0.0
 */
class CreateTag
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
     * Execute the create tag use case.
     *
     * @param string $text    Tag text (max 20 chars, no spaces/commas)
     * @param string $comment Tag comment (max 200 chars)
     *
     * @return Tag The created tag entity
     *
     * @throws \InvalidArgumentException If validation fails
     * @throws \mysqli_sql_exception If duplicate tag
     */
    public function execute(string $text, string $comment = ''): Tag
    {
        // Check for duplicate
        if ($this->repository->textExists($text)) {
            throw new \InvalidArgumentException('Tag "' . $text . '" already exists');
        }

        $tag = Tag::create($this->repository->getTagType(), $text, $comment);
        $this->repository->save($tag);

        return $tag;
    }

    /**
     * Execute and return result.
     *
     * @param string $text    Tag text
     * @param string $comment Tag comment
     *
     * @return array{success: bool, tag: ?Tag, error: ?string} Result
     */
    public function executeWithResult(string $text, string $comment = ''): array
    {
        try {
            $tag = $this->execute($text, $comment);
            return ['success' => true, 'tag' => $tag, 'error' => null];
        } catch (\Exception $e) {
            return ['success' => false, 'tag' => null, 'error' => $e->getMessage()];
        }
    }
}
