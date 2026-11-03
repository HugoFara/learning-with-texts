<?php

declare(strict_types=1);

namespace Lwt\Tests\Modules\Vocabulary\Http;

use Lwt\Modules\Review\Application\UseCases\MergeReviewHistory;
use Lwt\Modules\Review\Domain\Scheduling\Fsrs6Scheduler;
use Lwt\Modules\Vocabulary\Application\Services\Anki\AnkiReviewPull;
use Lwt\Modules\Vocabulary\Domain\TermRepositoryInterface;
use Lwt\Modules\Vocabulary\Http\AnkiApiHandler;
use Lwt\Tests\Modules\Vocabulary\Application\Services\Anki\InMemoryTermScheduleRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * POST /api/v1/anki/reviews (#264).
 */
#[CoversClass(AnkiApiHandler::class)]
final class AnkiApiHandlerTest extends TestCase
{
    private AnkiApiHandler $handler;

    protected function setUp(): void
    {
        $this->handler = new AnkiApiHandler(new AnkiReviewPull(
            $this->createMock(TermRepositoryInterface::class),
            new MergeReviewHistory(new Fsrs6Scheduler(), new InMemoryTermScheduleRepository())
        ));
    }

    public function testReviewsMustBeAList(): void
    {
        $response = $this->handler->routePost(['anki', 'reviews'], ['reviews' => ['a' => 1]]);

        self::assertSame(400, $response->getStatusCode());
    }

    public function testAMissingListIsRejected(): void
    {
        self::assertSame(400, $this->handler->routePost(['anki', 'reviews'], [])->getStatusCode());
    }

    public function testAnUnknownSubrouteIsNotFound(): void
    {
        self::assertSame(404, $this->handler->routePost(['anki', 'notes'], ['reviews' => []])->getStatusCode());
    }

    public function testAnEmptyListIsASummaryOfNothing(): void
    {
        $response = $this->handler->routePost(['anki', 'reviews'], ['reviews' => []]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            ['terms' => 0, 'applied' => 0, 'duplicates' => 0, 'rebuilt' => 0, 'skipped' => 0],
            $response->getData()
        );
    }

    public function testOnlyPostIsAllowed(): void
    {
        self::assertSame(405, $this->handler->routeGet(['anki', 'reviews'], [])->getStatusCode());
        self::assertSame(405, $this->handler->routePut(['anki', 'reviews'], [])->getStatusCode());
        self::assertSame(405, $this->handler->routeDelete(['anki', 'reviews'], [])->getStatusCode());
    }
}
