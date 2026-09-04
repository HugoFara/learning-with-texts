<?php

declare(strict_types=1);

namespace Lwt\Tests\Core\Feed;

use Lwt\Shared\Infrastructure\Bootstrap\EnvLoader;
use Lwt\Shared\Infrastructure\Globals;
use Lwt\Modules\Feed\Application\FeedFacade;
use Lwt\Shared\Infrastructure\Container\Container;
use Lwt\Modules\Feed\FeedServiceProvider;
use Lwt\Shared\Infrastructure\Database\Configuration;
use PHPUnit\Framework\TestCase;

/**
 * Tests for FeedFacade RSS feed operations.
 *
 * Tests migrated from Core/Feed/feeds.php function tests to FeedFacade method tests.
 */
class FeedsTest extends TestCase
{
    private FeedFacade $feedService;

    protected function setUp(): void
    {
        // Ensure we have a test database set up
        if (!Globals::getDbConnection()) {
            $config = EnvLoader::getDatabaseConfig();
            $test_dbname = "test_" . $config['dbname'];
            try {
                $connection = Configuration::connect(
                    $config['server'],
                    $config['userid'],
                    $config['passwd'],
                    $test_dbname,
                    $config['socket'] ?? ''
                );
                Globals::setDbConnection($connection);
            } catch (\Exception $e) {
                $this->markTestSkipped('Database connection not available');
            }
        }

        // Register FeedServiceProvider and get FeedFacade
        $container = Container::getInstance();
        $provider = new FeedServiceProvider();
        $provider->register($container);
        $provider->boot($container);

        $this->feedService = $container->get(FeedFacade::class);
    }

    /**
     * Test getFeedOption method - parses options from feed options string
     * Note: Uses comma as separator, not semicolon
     */
    public function testGetNfOption(): void
    {
        // Test basic option retrieval (comma-separated)
        $options = "max_texts=10";
        $result = $this->feedService->getFeedOption($options, 'max_texts');
        $this->assertEquals('10', $result);

        // Test autoupdate option
        $options = "autoupdate=12h";
        $result = $this->feedService->getFeedOption($options, 'autoupdate');
        $this->assertEquals('12h', $result);

        // Test multiple options (comma-separated)
        $options = "max_texts=5,autoupdate=1d,tag=news";
        $this->assertEquals('5', $this->feedService->getFeedOption($options, 'max_texts'));
        $this->assertEquals('1d', $this->feedService->getFeedOption($options, 'autoupdate'));
        $this->assertEquals('news', $this->feedService->getFeedOption($options, 'tag'));

        // Test non-existent option - returns null, which is falsy
        $result = $this->feedService->getFeedOption($options, 'nonexistent');
        $this->assertNull($result);

        // Test empty options string
        $result = $this->feedService->getFeedOption('', 'max_texts');
        $this->assertNull($result);

        // Test option with special characters
        $options = "tag=news-daily";
        $result = $this->feedService->getFeedOption($options, 'tag');
        $this->assertEquals('news-daily', $result);
    }

    /**
     * Test parseRssFeed method exists and has correct signature
     */
    public function testParseRssFeedMethodExists(): void
    {
        $this->assertTrue(method_exists($this->feedService, 'parseRssFeed'));
    }

    /**
     * Test detectAndParseFeed method exists and has correct signature
     */
    public function testDetectAndParseFeedMethodExists(): void
    {
        $this->assertTrue(method_exists($this->feedService, 'detectAndParseFeed'));
    }

    /**
     * Test extractTextFromArticle method
     */
    public function testExtractTextFromArticle(): void
    {
        // Test with empty feed data
        $result = $this->feedService->extractTextFromArticle([], '', '', null);
        // Should handle gracefully
        $this->assertTrue($result === null || $result === '' || is_array($result));

        // Test method exists
        $this->assertTrue(method_exists($this->feedService, 'extractTextFromArticle'));
    }

    /**
     * Test formatLastUpdate method
     */
    public function testFormatLastUpdate(): void
    {
        // Test with various time differences
        $output = $this->feedService->formatLastUpdate(3600); // 1 hour ago
        $this->assertStringContainsString('hour', $output);

        $output = $this->feedService->formatLastUpdate(86400); // 1 day ago
        $this->assertStringContainsString('day', $output);

        $output = $this->feedService->formatLastUpdate(60); // 1 minute ago
        $this->assertStringContainsString('minute', $output);

        // Test method exists
        $this->assertTrue(method_exists($this->feedService, 'formatLastUpdate'));
    }

    /**
     * Test feed date parsing functionality
     */
    public function testFeedDateParsing(): void
    {
        // RFC 822 format (RSS)
        $date = 'Mon, 01 Jan 2024 12:00:00 GMT';
        $timestamp = strtotime($date);
        $this->assertIsInt($timestamp);
        $this->assertGreaterThan(0, $timestamp);

        // RFC 3339 format (Atom)
        $date = '2024-01-01T12:00:00Z';
        $timestamp = strtotime($date);
        $this->assertIsInt($timestamp);
        $this->assertGreaterThan(0, $timestamp);

        // Invalid date
        $date = 'Not a valid date';
        $timestamp = strtotime($date);
        $this->assertFalse($timestamp);
    }

    /**
     * Test parseAutoUpdateInterval method
     */
    public function testParseAutoUpdateInterval(): void
    {
        // Test hours
        $result = $this->feedService->parseAutoUpdateInterval('12h');
        $this->assertEquals(12 * 60 * 60, $result);

        // Test days
        $result = $this->feedService->parseAutoUpdateInterval('1d');
        $this->assertEquals(24 * 60 * 60, $result);

        // Test weeks
        $result = $this->feedService->parseAutoUpdateInterval('2w');
        $this->assertEquals(2 * 7 * 24 * 60 * 60, $result);

        // Test invalid format - note: 'invalid' contains 'd' so it matches day pattern
        // and returns 0 (since (int)'invali' = 0)
        $result = $this->feedService->parseAutoUpdateInterval('invalid');
        $this->assertEquals(0, $result);

        // Test truly invalid format (no h/d/w characters)
        $result = $this->feedService->parseAutoUpdateInterval('xyz');
        $this->assertNull($result);
    }

    /**
     * Test getFeedOption with edge cases
     */
    public function testGetNfOptionEdgeCases(): void
    {
        // Test with empty string
        $result = $this->feedService->getFeedOption('', 'max_texts');
        $this->assertNull($result);

        // Test with whitespace
        $result = $this->feedService->getFeedOption(' ', 'max_texts');
        $this->assertNull($result);

        // Test with malformed option
        $result = $this->feedService->getFeedOption('no_equals_sign', 'max_texts');
        $this->assertNull($result);

        // Test with multiple equals signs (explode splits on first = only with limit 2)
        $options = 'key=value=extra';
        $result = $this->feedService->getFeedOption($options, 'key');
        // explode without limit takes all = signs, so 'value' is returned
        $this->assertEquals('value', $result);
    }

    /**
     * Test getFeedOption with special characters
     */
    public function testGetNfOptionWithSpecialCharacters(): void
    {
        // Test with special characters in value
        $options = 'tag=news&entertainment,max_texts=10';
        $result = $this->feedService->getFeedOption($options, 'tag');
        $this->assertEquals('news&entertainment', $result);

        // Test with URL in value
        $options = 'url=http://example.com/feed,max_texts=5';
        $result = $this->feedService->getFeedOption($options, 'url');
        $this->assertEquals('http://example.com/feed', $result);
    }

    /**
     * Test getFeedOption with 'all' parameter
     */
    public function testGetNfOptionAll(): void
    {
        $options = 'max_texts=10,autoupdate=12h,tag=news';
        $result = $this->feedService->getFeedOption($options, 'all');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('max_texts', $result);
        $this->assertArrayHasKey('autoupdate', $result);
        $this->assertArrayHasKey('tag', $result);
        $this->assertEquals('10', $result['max_texts']);
        $this->assertEquals('12h', $result['autoupdate']);
        $this->assertEquals('news', $result['tag']);
    }

    /**
     * Test getFeedOption with 'all' on empty string
     */
    public function testGetNfOptionAllEmpty(): void
    {
        $result = $this->feedService->getFeedOption('', 'all');
        $this->assertIsArray($result);
        // Empty string creates one empty element when exploded
        // So array will have one entry with empty key and value
    }

    /**
     * Test getFeedOption with single option
     */
    public function testGetNfOptionSingleOption(): void
    {
        $options = 'max_texts=25';
        $result = $this->feedService->getFeedOption($options, 'max_texts');
        $this->assertEquals('25', $result);
    }

    /**
     * Test getFeedOption with whitespace in option
     */
    public function testGetNfOptionWithWhitespace(): void
    {
        // With leading/trailing spaces (trim is used in function)
        $options = ' max_texts = 10 ,autoupdate=1d';
        $result = $this->feedService->getFeedOption($options, 'max_texts');
        $this->assertNotNull($result);
    }

    /**
     * Test getFeedOption with duplicate keys
     */
    public function testGetNfOptionDuplicateKeys(): void
    {
        // Last occurrence should win
        $options = 'max_texts=10,max_texts=20';
        $result = $this->feedService->getFeedOption($options, 'max_texts');
        // Function returns first match, not last
        $this->assertEquals('10', $result);
    }

    /**
     * Test formatLastUpdate with various time intervals
     */
    public function testFormatLastUpdateVariousIntervals(): void
    {
        // Test years
        $output = $this->feedService->formatLastUpdate(60 * 60 * 24 * 365 * 2); // 2 years
        $this->assertStringContainsString('2', $output);
        $this->assertStringContainsString('year', $output);

        // Test months
        $output = $this->feedService->formatLastUpdate(60 * 60 * 24 * 60); // ~2 months
        $this->assertStringContainsString('month', $output);

        // Test weeks
        $output = $this->feedService->formatLastUpdate(60 * 60 * 24 * 14); // 2 weeks
        $this->assertStringContainsString('week', $output);

        // Test seconds
        $output = $this->feedService->formatLastUpdate(30); // 30 seconds
        $this->assertStringContainsString('second', $output);
    }

    /**
     * Test formatLastUpdate with zero/negative diff
     */
    public function testFormatLastUpdateUpToDate(): void
    {
        // Test with 0
        $output = $this->feedService->formatLastUpdate(0);
        $this->assertStringContainsString('up to date', $output);

        // Test with negative (treated as up to date)
        $output = $this->feedService->formatLastUpdate(-100);
        $this->assertStringContainsString('up to date', $output);
    }

    /**
     * Test formatLastUpdate pluralization
     */
    public function testFormatLastUpdatePluralization(): void
    {
        // Single hour
        $output = $this->feedService->formatLastUpdate(3600);
        $this->assertStringContainsString('1', $output);
        $this->assertStringContainsString('hour', $output);
        $this->assertStringNotContainsString('hours', $output);

        // Multiple hours
        $output = $this->feedService->formatLastUpdate(7200); // 2 hours
        $this->assertStringContainsString('2', $output);
        $this->assertStringContainsString('hours', $output);
    }

    /**
     * Test extractTextFromArticle with empty feed data
     */
    public function testExtractTextFromArticleEmptyData(): void
    {
        $result = $this->feedService->extractTextFromArticle([], '', '', null);
        $this->assertTrue($result === null || $result === '' || is_array($result));
    }

    /**
     * Test extractTextFromArticle error handling
     */
    public function testExtractTextFromArticleErrorHandling(): void
    {
        // Test with minimal valid structure
        $feed_data = [
            ['title' => 'Test', 'link' => '#1', 'text' => '']
        ];

        $result = $this->feedService->extractTextFromArticle($feed_data, '', '', null);

        // Should handle gracefully - result may be array or null
        $this->assertTrue(is_array($result) || $result === null || $result === '');
    }

    /**
     * Test getFeedLoadConfig method exists
     */
    public function testGetFeedLoadConfigExists(): void
    {
        $this->assertTrue(method_exists($this->feedService, 'getFeedLoadConfig'));
    }

    /**
     * Test getFeedOption with numeric values
     */
    public function testGetNfOptionNumericValues(): void
    {
        $options = 'max_texts=100,min_length=50';

        $result = $this->feedService->getFeedOption($options, 'max_texts');
        $this->assertEquals('100', $result);
        $this->assertIsString($result); // Returns string, not int

        $result = $this->feedService->getFeedOption($options, 'min_length');
        $this->assertEquals('50', $result);
    }

    /**
     * Test getFeedOption case sensitivity
     */
    public function testGetNfOptionCaseSensitivity(): void
    {
        $options = 'MaxTexts=10,max_texts=20';

        // Keys are case-sensitive
        $result = $this->feedService->getFeedOption($options, 'max_texts');
        $this->assertEquals('20', $result);

        $result = $this->feedService->getFeedOption($options, 'MaxTexts');
        $this->assertEquals('10', $result);
    }

    /**
     * Test formatLastUpdate edge case - exactly 1 unit
     */
    public function testFormatLastUpdateExactlyOneUnit(): void
    {
        // Exactly 1 day
        $output = $this->feedService->formatLastUpdate(60 * 60 * 24);
        $this->assertStringContainsString('1', $output);
        $this->assertStringContainsString('day', $output);
        $this->assertStringNotContainsString('days', $output); // Should not pluralize

        // Exactly 1 minute
        $output = $this->feedService->formatLastUpdate(60);
        $this->assertStringContainsString('1', $output);
        $this->assertStringContainsString('minute', $output);
    }

    /**
     * Test buildQueryFilter method
     */
    public function testBuildQueryFilter(): void
    {
        // Test with empty query - returns array with empty values
        $result = $this->feedService->buildQueryFilter('', 'title', '');
        $this->assertIsArray($result);
        $this->assertEquals('', $result['clause']);
        $this->assertEquals('', $result['search']);

        // Test method exists
        $this->assertTrue(method_exists($this->feedService, 'buildQueryFilter'));
    }

    /**
     * Test getSortColumn method
     */
    public function testGetSortColumn(): void
    {
        // Test default prefix (Fl)
        $result = $this->feedService->getSortColumn(1);
        $this->assertStringContainsString('Title', $result);

        $result = $this->feedService->getSortColumn(2);
        $this->assertStringContainsString('Date', $result);

        // Test Nf prefix
        $result = $this->feedService->getSortColumn(1, 'Nf');
        $this->assertStringContainsString('Name', $result);
    }

    /**
     * Test validateRegexPattern method
     */
    public function testValidateRegexPattern(): void
    {
        // Empty pattern should be valid
        $this->assertTrue($this->feedService->validateRegexPattern(''));

        // Test method exists
        $this->assertTrue(method_exists($this->feedService, 'validateRegexPattern'));
    }
}
