<?php

/**
 * Unit tests for GetTableWords use case.
 *
 * PHP version 8.2
 *
 * @category Testing
 * @package  Lwt\Tests\Modules\Review\Application\UseCases
 * @license  Unlicense <http://unlicense.org/>
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Tests\Modules\Review\Application\UseCases;

use Lwt\Modules\Review\Application\UseCases\GetTableWords;
use Lwt\Modules\Review\Domain\ReviewConfiguration;
use Lwt\Modules\Review\Domain\ReviewRepositoryInterface;
use Lwt\Modules\Review\Domain\ReviewWord;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for GetTableWords use case.
 *
 * Tests table review word retrieval, formatting, and validation.
 * Methods that depend on static LanguageFacade calls are tested
 * for structure and contracts only.
 *
 * @since 3.0.0
 */
class GetTableWordsTest extends TestCase
{
    private ReviewRepositoryInterface&MockObject $repository;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(ReviewRepositoryInterface::class);
    }

    /**
     * Helper to create a ReviewWord instance.
     */
    private function makeWord(
        int $id = 1,
        string $text = 'hello',
        string $textLc = 'hello',
        string $translation = 'bonjour',
        ?string $romanization = null,
        ?string $sentence = null,
        int $langId = 1,
        int $status = 2,
        int $score = 50,
        int $daysOld = 3
    ): ReviewWord {
        return new ReviewWord(
            $id,
            $text,
            $textLc,
            $translation,
            $romanization,
            $sentence,
            $langId,
            $status,
            $score,
            $daysOld
        );
    }

    // =========================================================================
    // Instantiation
    // =========================================================================

    #[Test]
    public function canBeInstantiated(): void
    {
        $useCase = new GetTableWords($this->repository);
        $this->assertInstanceOf(GetTableWords::class, $useCase);
    }

    // =========================================================================
    // Invalid configuration
    // =========================================================================

    #[Test]
    public function executeWithInvalidConfigReturnsError(): void
    {
        // Empty reviewKey makes config invalid
        $config = new ReviewConfiguration('', 0);

        $useCase = new GetTableWords($this->repository);
        $result = $useCase->execute($config);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Invalid test configuration', $result['error']);
    }

    // =========================================================================
    // Language validation failure
    // =========================================================================

    #[Test]
    public function executeWithMultipleLanguagesReturnsError(): void
    {
        $config = ReviewConfiguration::forTableMode('words', [1, 2, 3]);

        $this->repository->expects($this->once())
            ->method('validateSingleLanguage')
            ->with($config)
            ->willReturn([
                'valid' => false,
                'langCount' => 2,
                'error' => 'Multiple languages found'
            ]);

        $useCase = new GetTableWords($this->repository);
        $result = $useCase->execute($config);

        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Multiple languages found', $result['error']);
    }

    // =========================================================================
    // No language ID found
    // =========================================================================

    #[Test]
    public function executeWithNoLanguageIdReturnsEmptyWords(): void
    {
        $config = ReviewConfiguration::forTableMode('lang', 1);

        $this->repository->expects($this->once())
            ->method('validateSingleLanguage')
            ->willReturn(['valid' => true, 'langCount' => 1, 'error' => null]);

        $this->repository->expects($this->once())
            ->method('getLanguageIdFromConfig')
            ->with($config)
            ->willReturn(null);

        $useCase = new GetTableWords($this->repository);
        $result = $useCase->execute($config);

        $this->assertSame([], $result['words']);
        $this->assertNull($result['langSettings']);
    }

    // =========================================================================
    // Repository interaction sequence
    // =========================================================================

    #[Test]
    public function executeCallsValidationBeforeDataFetch(): void
    {
        $config = new ReviewConfiguration('', 0);

        // With an invalid config, it should not call any repository methods
        $this->repository->expects($this->never())
            ->method('validateSingleLanguage');

        $this->repository->expects($this->never())
            ->method('getLanguageIdFromConfig');

        $this->repository->expects($this->never())
            ->method('getTableWords');

        $useCase = new GetTableWords($this->repository);
        $useCase->execute($config);
    }

    #[Test]
    public function executeDoesNotFetchWordsWhenValidationFails(): void
    {
        $config = ReviewConfiguration::forTableMode('lang', 1);

        $this->repository->expects($this->once())
            ->method('validateSingleLanguage')
            ->willReturn(['valid' => false, 'langCount' => 0, 'error' => 'No words']);

        $this->repository->expects($this->never())
            ->method('getTableWords');

        $useCase = new GetTableWords($this->repository);
        $useCase->execute($config);
    }

    // =========================================================================
    // Configuration forwarding
    // =========================================================================

    #[Test]
    public function executePassesConfigToValidation(): void
    {
        $config = ReviewConfiguration::forTableMode('lang', 5);

        $this->repository->expects($this->once())
            ->method('validateSingleLanguage')
            ->with($config)
            ->willReturn(['valid' => false, 'langCount' => 0, 'error' => 'fail']);

        $useCase = new GetTableWords($this->repository);
        $useCase->execute($config);
    }

    // =========================================================================
    // Table mode configuration
    // =========================================================================

    #[Test]
    public function executeWithTableModeConfigIsValid(): void
    {
        $config = ReviewConfiguration::forTableMode('lang', 1);
        $this->assertTrue($config->isValid());
        $this->assertTrue($config->isTableMode);
    }

    // =========================================================================
    // Error response structure
    // =========================================================================

    #[Test]
    public function executeErrorResponseContainsOnlyErrorKey(): void
    {
        $config = new ReviewConfiguration('', 0);

        $useCase = new GetTableWords($this->repository);
        $result = $useCase->execute($config);

        $this->assertCount(1, $result);
        $this->assertArrayHasKey('error', $result);
    }

    #[Test]
    public function executeValidationErrorPreservesErrorMessage(): void
    {
        $config = ReviewConfiguration::forTableMode('lang', 1);
        $errorMsg = 'Selection includes multiple languages';

        $this->repository->expects($this->once())
            ->method('validateSingleLanguage')
            ->willReturn([
                'valid' => false,
                'langCount' => 3,
                'error' => $errorMsg
            ]);

        $useCase = new GetTableWords($this->repository);
        $result = $useCase->execute($config);

        $this->assertSame($errorMsg, $result['error']);
    }

    // =========================================================================
    // Empty words result
    // =========================================================================

    #[Test]
    public function executeNoLangIdReturnsNullLangSettings(): void
    {
        $config = ReviewConfiguration::forTableMode('lang', 99);

        $this->repository->expects($this->once())
            ->method('validateSingleLanguage')
            ->willReturn(['valid' => true, 'langCount' => 1, 'error' => null]);

        $this->repository->expects($this->once())
            ->method('getLanguageIdFromConfig')
            ->willReturn(null);

        $useCase = new GetTableWords($this->repository);
        $result = $useCase->execute($config);

        $this->assertIsArray($result['words']);
        $this->assertEmpty($result['words']);
        $this->assertNull($result['langSettings']);
        $this->assertArrayNotHasKey('tableSettings', $result);
    }
}
