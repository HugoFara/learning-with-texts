<?php

declare(strict_types=1);

namespace Lwt\Tests\Modules\Text\Http;

use Lwt\Modules\Text\Http\TextApiHandler;
use Lwt\Shared\Infrastructure\Http\JsonResponse;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for POST /texts and PUT /texts/{id} input validation.
 *
 * These are the endpoints the text editor saves through since its op=Save /
 * op=Change form POST was retired (#262).
 *
 * Every branch asserted here returns before the language-ownership lookup, so
 * the suite runs without a database. The ownership gate itself and the save are
 * covered by the facade's DB-backed tests and by the Cypress run.
 */
class TextApiHandlerSaveTest extends TestCase
{
    private TextApiHandler $handler;

    protected function setUp(): void
    {
        $this->handler = new TextApiHandler(null);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function create(array $params): JsonResponse
    {
        return $this->handler->routePost(['texts'], $params);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function update(int $textId, array $params): JsonResponse
    {
        return $this->handler->routePut(['texts', (string) $textId], $params);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function updateArchived(int $textId, array $params): JsonResponse
    {
        return $this->handler->routePut(['texts', 'archived', (string) $textId], $params);
    }

    /**
     * A payload that passes validation, for tests that vary one field.
     *
     * @return array<string, mixed>
     */
    private function validPayload(): array
    {
        return [
            'title' => 'Chapter One',
            'text' => 'Ein kurzer Text.',
            'language_id' => 3,
        ];
    }

    public function testCreateRejectsMissingTitle(): void
    {
        $res = $this->create(['text' => 'Some words', 'language_id' => 1]);

        $this->assertSame(400, $res->getStatusCode());
        $this->assertSame(['error' => 'title is required'], $res->getData());
    }

    public function testCreateRejectsBlankTitle(): void
    {
        $res = $this->create(['title' => "  \t ", 'text' => 'Some words', 'language_id' => 1]);

        $this->assertSame(400, $res->getStatusCode());
        $this->assertSame(['error' => 'title is required'], $res->getData());
    }

    public function testCreateRejectsMissingText(): void
    {
        $res = $this->create(['title' => 'A title', 'language_id' => 1]);

        $this->assertSame(400, $res->getStatusCode());
        $this->assertSame(['error' => 'text is required'], $res->getData());
    }

    public function testCreateRejectsWhitespaceOnlyText(): void
    {
        $res = $this->create(['title' => 'A title', 'text' => "\n\n  ", 'language_id' => 1]);

        $this->assertSame(400, $res->getStatusCode());
        $this->assertSame(['error' => 'text is required'], $res->getData());
    }

    public function testCreateRejectsMissingLanguage(): void
    {
        $res = $this->create(['title' => 'A title', 'text' => 'Some words']);

        $this->assertSame(400, $res->getStatusCode());
        $this->assertSame(['error' => 'language_id is required'], $res->getData());
    }

    public function testCreateRejectsNonPositiveLanguage(): void
    {
        $params = $this->validPayload();
        $params['language_id'] = 0;

        $res = $this->create($params);

        $this->assertSame(400, $res->getStatusCode());
        $this->assertSame(['error' => 'language_id is required'], $res->getData());
    }

    /**
     * The update path validates the same way the create path does.
     */
    public function testUpdateAppliesTheSameValidation(): void
    {
        $res = $this->update(9, ['text' => 'Some words', 'language_id' => 1]);

        $this->assertSame(400, $res->getStatusCode());
        $this->assertSame(['error' => 'title is required'], $res->getData());
    }

    /**
     * A non-numeric first fragment is a sub-resource name, not an id, so it
     * must not be mistaken for an update of text 0.
     */
    public function testUpdateRejectsNonNumericId(): void
    {
        $res = $this->handler->routePut(['texts', 'not-an-id'], $this->validPayload());

        $this->assertSame(404, $res->getStatusCode());
    }

    /**
     * POST /texts/{id} is not a save — the id-carrying POST routes are the
     * annotation / position sub-resources, and an unknown one is a 404.
     */
    public function testPostToATextIdIsNotASave(): void
    {
        $res = $this->handler->routePost(['texts', '9'], $this->validPayload());

        $this->assertSame(404, $res->getStatusCode());
    }

    /**
     * The archived save validates its payload the way the active save does.
     */
    public function testArchivedUpdateRejectsMissingTitle(): void
    {
        $res = $this->updateArchived(9, ['text' => 'Some words', 'language_id' => 1]);

        $this->assertSame(400, $res->getStatusCode());
        $this->assertSame(['error' => 'title is required'], $res->getData());
    }

    public function testArchivedUpdateRejectsMissingText(): void
    {
        $res = $this->updateArchived(9, ['title' => 'A title', 'language_id' => 1]);

        $this->assertSame(400, $res->getStatusCode());
        $this->assertSame(['error' => 'text is required'], $res->getData());
    }

    public function testArchivedUpdateRejectsMissingLanguage(): void
    {
        $res = $this->updateArchived(9, ['title' => 'A title', 'text' => 'Some words']);

        $this->assertSame(400, $res->getStatusCode());
        $this->assertSame(['error' => 'language_id is required'], $res->getData());
    }

    /**
     * `archived` names a sub-resource, so it must carry an id of its own
     * rather than being read as the id.
     */
    public function testArchivedUpdateRequiresAnId(): void
    {
        $res = $this->handler->routePut(['texts', 'archived'], $this->validPayload());

        $this->assertSame(404, $res->getStatusCode());
    }

    public function testArchivedUpdateRejectsNonNumericId(): void
    {
        $res = $this->handler->routePut(['texts', 'archived', 'not-an-id'], $this->validPayload());

        $this->assertSame(404, $res->getStatusCode());
    }
}
