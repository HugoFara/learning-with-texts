<?php

/**
 * Unit tests for TextScoringService.
 *
 * Tests focus on the TextScore value object behavior, method signatures,
 * and return type contracts. Integration tests with a real database are
 * needed for full coverage of scoring queries.
 *
 * PHP version 8.2
 *
 * @category Testing
 * @package  Lwt\Tests\Modules\Text\Application\Services
 * @license  Unlicense <http://unlicense.org/>
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Tests\Modules\Text\Application\Services;

use Lwt\Modules\Text\Application\Services\TextScoringService;
use Lwt\Modules\Text\Domain\TextScore;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Unit tests for TextScoringService and TextScore value object.
 *
 * @since  3.0.0
 */
#[CoversClass(TextScoringService::class)]
#[CoversClass(TextScore::class)]
class TextScoringServiceTest extends TestCase
{
    // =========================================================================
    // Service instantiation and method existence
    // =========================================================================

    #[Test]
    public function canBeInstantiated(): void
    {
        $service = new TextScoringService();
        $this->assertInstanceOf(TextScoringService::class, $service);
    }

    #[Test]
    public function scoreTextMethodExists(): void
    {
        $this->assertTrue(method_exists(TextScoringService::class, 'scoreText'));
    }

    #[Test]
    public function scoreTextsMethodExists(): void
    {
        $this->assertTrue(method_exists(TextScoringService::class, 'scoreTexts'));
    }

    #[Test]
    public function getRecommendedTextsMethodExists(): void
    {
        $this->assertTrue(method_exists(TextScoringService::class, 'getRecommendedTexts'));
    }

    #[Test]
    public function scoreTextAcceptsCorrectParameters(): void
    {
        $method = new \ReflectionMethod(TextScoringService::class, 'scoreText');
        $params = $method->getParameters();

        $this->assertCount(2, $params);
        $this->assertSame('textId', $params[0]->getName());
        $this->assertSame('int', $params[0]->getType()->getName());
        $this->assertSame('unknownWordsLimit', $params[1]->getName());
        $this->assertTrue($params[1]->isDefaultValueAvailable());
        $this->assertSame(20, $params[1]->getDefaultValue());
    }

    #[Test]
    public function scoreTextsAcceptsArrayParameter(): void
    {
        $method = new \ReflectionMethod(TextScoringService::class, 'scoreTexts');
        $params = $method->getParameters();

        $this->assertCount(1, $params);
        $this->assertSame('textIds', $params[0]->getName());
        $this->assertSame('array', $params[0]->getType()->getName());
    }

    #[Test]
    public function scoreTextsReturnsArrayForEmptyInput(): void
    {
        $service = new TextScoringService();
        $result = $service->scoreTexts([]);
        $this->assertSame([], $result);
    }

    #[Test]
    public function getRecommendedTextsAcceptsCorrectParameters(): void
    {
        $method = new \ReflectionMethod(TextScoringService::class, 'getRecommendedTexts');
        $params = $method->getParameters();

        $this->assertCount(3, $params);
        $this->assertSame('languageId', $params[0]->getName());
        $this->assertSame('targetComprehensibility', $params[1]->getName());
        $this->assertSame(0.95, $params[1]->getDefaultValue());
        $this->assertSame('limit', $params[2]->getName());
        $this->assertSame(10, $params[2]->getDefaultValue());
    }

    // =========================================================================
    // TextScore value object
    // =========================================================================

    #[Test]
    public function textScoreHoldsProperties(): void
    {
        $score = new TextScore(
            textId: 1,
            totalUniqueWords: 100,
            knownWords: 80,
            learningWords: 10,
            unknownWords: 10
        );

        $this->assertSame(1, $score->textId);
        $this->assertSame(100, $score->totalUniqueWords);
        $this->assertSame(80, $score->knownWords);
        $this->assertSame(10, $score->learningWords);
        $this->assertSame(10, $score->unknownWords);
        $this->assertSame([], $score->unknownWordsList);
    }

    #[Test]
    public function textScoreStoresUnknownWordsList(): void
    {
        $score = new TextScore(
            textId: 1,
            totalUniqueWords: 50,
            knownWords: 45,
            learningWords: 3,
            unknownWords: 2,
            unknownWordsList: ['hello', 'world']
        );

        $this->assertSame(['hello', 'world'], $score->unknownWordsList);
    }

    #[Test]
    public function comprehensibilityCalculation(): void
    {
        $score = new TextScore(1, 100, 95, 3, 2);
        $this->assertEqualsWithDelta(0.95, $score->comprehensibility(), 0.001);
    }

    #[Test]
    public function comprehensibilityWithZeroWords(): void
    {
        $score = new TextScore(1, 0, 0, 0, 0);
        $this->assertSame(1.0, $score->comprehensibility());
    }

    #[Test]
    public function comprehensibilityWith100PercentKnown(): void
    {
        $score = new TextScore(1, 50, 50, 0, 0);
        $this->assertSame(1.0, $score->comprehensibility());
    }

    #[Test]
    public function comprehensibilityWithZeroKnown(): void
    {
        $score = new TextScore(1, 100, 0, 0, 100);
        $this->assertSame(0.0, $score->comprehensibility());
    }

    #[Test]
    public function comprehensibilityPercentCalculation(): void
    {
        $score = new TextScore(1, 200, 190, 5, 5);
        $this->assertEqualsWithDelta(95.0, $score->comprehensibilityPercent(), 0.1);
    }

    #[Test]
    public function difficultyLabelTooEasy(): void
    {
        $score = new TextScore(1, 100, 100, 0, 0);
        $this->assertSame('too_easy', $score->difficultyLabel());
    }

    #[Test]
    public function difficultyLabelOptimal(): void
    {
        $score = new TextScore(1, 100, 96, 2, 2);
        $this->assertSame('optimal', $score->difficultyLabel());
    }

    #[Test]
    public function difficultyLabelChallenging(): void
    {
        $score = new TextScore(1, 100, 92, 4, 4);
        $this->assertSame('challenging', $score->difficultyLabel());
    }

    #[Test]
    public function difficultyLabelDifficult(): void
    {
        $score = new TextScore(1, 100, 85, 5, 10);
        $this->assertSame('difficult', $score->difficultyLabel());
    }

    #[Test]
    public function difficultyLabelTooHard(): void
    {
        $score = new TextScore(1, 100, 50, 10, 40);
        $this->assertSame('too_hard', $score->difficultyLabel());
    }

    #[Test]
    public function isOptimalReturnsTrueInRange(): void
    {
        $score = new TextScore(1, 100, 97, 2, 1);
        $this->assertTrue($score->isOptimal());
    }

    #[Test]
    public function isOptimalReturnsFalseBelow95(): void
    {
        $score = new TextScore(1, 100, 90, 5, 5);
        $this->assertFalse($score->isOptimal());
    }

    #[Test]
    public function isOptimalReturnsFalseAbove99(): void
    {
        $score = new TextScore(1, 100, 100, 0, 0);
        $this->assertFalse($score->isOptimal());
    }

    #[Test]
    public function newWordsToLearnCalculation(): void
    {
        $score = new TextScore(1, 100, 80, 10, 10);
        $this->assertSame(20, $score->newWordsToLearn());
    }

    #[Test]
    public function toArrayContainsAllKeys(): void
    {
        $score = new TextScore(42, 100, 80, 10, 10, ['foo', 'bar']);
        $arr = $score->toArray();

        $this->assertSame(42, $arr['text_id']);
        $this->assertSame(100, $arr['total_unique_words']);
        $this->assertSame(80, $arr['known_words']);
        $this->assertSame(10, $arr['learning_words']);
        $this->assertSame(10, $arr['unknown_words']);
        $this->assertEqualsWithDelta(0.8, $arr['comprehensibility'], 0.001);
        $this->assertEqualsWithDelta(80.0, $arr['comprehensibility_percent'], 0.1);
        $this->assertSame('difficult', $arr['difficulty_label']);
        $this->assertFalse($arr['is_optimal']);
        $this->assertSame(['foo', 'bar'], $arr['unknown_words_list']);
    }

    #[Test]
    public function toArrayWithZeroWords(): void
    {
        $score = new TextScore(1, 0, 0, 0, 0);
        $arr = $score->toArray();

        $this->assertSame(1.0, $arr['comprehensibility']);
        $this->assertSame(100.0, $arr['comprehensibility_percent']);
        $this->assertSame('too_easy', $arr['difficulty_label']);
    }

    // =========================================================================
    // Boundary values for difficulty labels
    // =========================================================================

    #[Test]
    public function difficultyLabelAt99Percent(): void
    {
        $score = new TextScore(1, 100, 99, 1, 0);
        $this->assertSame('too_easy', $score->difficultyLabel());
    }

    #[Test]
    public function difficultyLabelAt95Percent(): void
    {
        $score = new TextScore(1, 100, 95, 3, 2);
        $this->assertSame('optimal', $score->difficultyLabel());
    }

    #[Test]
    public function difficultyLabelAt90Percent(): void
    {
        $score = new TextScore(1, 100, 90, 5, 5);
        $this->assertSame('challenging', $score->difficultyLabel());
    }

    #[Test]
    public function difficultyLabelAt80Percent(): void
    {
        $score = new TextScore(1, 100, 80, 10, 10);
        $this->assertSame('difficult', $score->difficultyLabel());
    }

    #[Test]
    public function difficultyLabelAt79Percent(): void
    {
        $score = new TextScore(1, 100, 79, 10, 11);
        $this->assertSame('too_hard', $score->difficultyLabel());
    }

    #[Test]
    public function isOptimalAt95PercentExactly(): void
    {
        $score = new TextScore(1, 100, 95, 3, 2);
        $this->assertTrue($score->isOptimal());
    }

    #[Test]
    public function isOptimalAt98Percent(): void
    {
        $score = new TextScore(1, 100, 98, 1, 1);
        $this->assertTrue($score->isOptimal());
    }
}
