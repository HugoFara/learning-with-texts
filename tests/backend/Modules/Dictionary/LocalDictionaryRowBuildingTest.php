<?php

/**
 * Unit tests for LocalDictionaryService's per-entry row building.
 *
 * Regression cover for issue #250: FreeDict German-English contains a single
 * 293-character headword. Under STRICT_ALL_TABLES that over-long value failed
 * the whole 1000-row INSERT it landed in, aborting the import of all 517,534
 * entries with "Data too long for column 'LeTerm' at row 670".
 *
 * These exercise the pure decision step, so they need no database.
 *
 * PHP version 8.2
 *
 * @category Testing
 * @package  Lwt\Tests\Modules\Dictionary
 * @license  Unlicense <http://unlicense.org/>
 * @since    3.2.2-fork
 */

declare(strict_types=1);

namespace Lwt\Tests\Modules\Dictionary;

use Lwt\Modules\Dictionary\Application\Services\LocalDictionaryService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Unit tests for LocalDictionaryService::buildRow() and clip().
 *
 * @since 3.2.2-fork
 */
#[CoversClass(LocalDictionaryService::class)]
class LocalDictionaryRowBuildingTest extends TestCase
{
    /**
     * Character capacity of LeTerm / LeTermLc.
     */
    private const TERM_LIMIT = 250;

    /**
     * Build a row through the private helper.
     *
     * @param array<string, mixed> $entry Parsed entry
     *
     * @return array<string, string|int|null>|null
     */
    private function buildRow(array $entry): ?array
    {
        $method = new ReflectionMethod(LocalDictionaryService::class, 'buildRow');

        /** @var array<string, string|int|null>|null $row */
        $row = $method->invoke(null, 7, $entry);
        return $row;
    }

    #[Test]
    public function buildsARowForAnOrdinaryEntry(): void
    {
        $row = $this->buildRow(['term' => 'Haus', 'definition' => 'house']);

        $this->assertNotNull($row);
        $this->assertSame(7, $row['LeLdID']);
        $this->assertSame('Haus', $row['LeTerm']);
        $this->assertSame('haus', $row['LeTermLc']);
        $this->assertSame('house', $row['LeDefinition']);
        $this->assertNull($row['LeReading']);
        $this->assertNull($row['LePartOfSpeech']);
    }

    #[Test]
    public function keepsATermExactlyAtTheLimit(): void
    {
        $term = str_repeat('a', self::TERM_LIMIT);

        $row = $this->buildRow(['term' => $term, 'definition' => 'x']);

        $this->assertNotNull($row);
        $this->assertSame($term, $row['LeTerm']);
    }

    /**
     * The #250 entry: one headword one character over the limit must be
     * reported as unstorable rather than handed to the INSERT, where it would
     * take its whole batch down with it.
     */
    #[Test]
    public function skipsATermOneCharacterOverTheLimit(): void
    {
        $term = str_repeat('a', self::TERM_LIMIT + 1);

        $this->assertNull($this->buildRow(['term' => $term, 'definition' => 'x']));
    }

    #[Test]
    public function skipsTheActualFreeDictOffender(): void
    {
        // The 293-character headword that aborted the FreeDict German-English
        // import: the opening of the Lord's Prayer, stored as one entry.
        $term = 'Vater unser im Himmel, geheiligt werde dein Name, dein Reich '
            . 'komme, dein Wille geschehe, wie im Himmel, so auf Erden. Unser '
            . 'täglich Brot gib uns heute und vergib uns unsere Schuld, wie '
            . 'auch wir vergeben unseren Schuldigern, und führe uns nicht in '
            . 'Versuchung, sondern erlöse uns von dem Bösen.';

        $this->assertGreaterThan(self::TERM_LIMIT, mb_strlen($term, 'UTF-8'));
        $this->assertNull($this->buildRow(['term' => $term, 'definition' => 'x']));
    }

    /**
     * Multi-byte characters are counted as characters, matching how MySQL
     * measures a utf8mb4 VARCHAR — a byte-based check would wrongly reject
     * accented terms well inside the limit.
     */
    #[Test]
    public function measuresLengthInCharactersNotBytes(): void
    {
        // 200 characters, 400 bytes: comfortably storable.
        $term = str_repeat('ä', 200);

        $row = $this->buildRow(['term' => $term, 'definition' => 'x']);

        $this->assertNotNull($row, 'A 200-character term must not be rejected on byte length');
        $this->assertSame($term, $row['LeTerm']);
    }

    #[Test]
    public function skipsWhenLowercasingPushesTheTermOverTheLimit(): void
    {
        // 'İ' (U+0130) lowercases to two code points, so a term at the limit
        // can overflow LeTermLc while LeTerm still fits.
        $term = str_repeat('İ', self::TERM_LIMIT);
        $this->assertSame(self::TERM_LIMIT, mb_strlen($term, 'UTF-8'));
        $this->assertGreaterThan(
            self::TERM_LIMIT,
            mb_strlen(mb_strtolower($term, 'UTF-8'), 'UTF-8'),
            'Precondition: lowercasing this term must lengthen it'
        );

        $this->assertNull($this->buildRow(['term' => $term, 'definition' => 'x']));
    }

    #[Test]
    public function truncatesOverLongReadingAndPartOfSpeechInsteadOfSkipping(): void
    {
        $row = $this->buildRow([
            'term' => 'Haus',
            'definition' => 'house',
            'reading' => str_repeat('r', 400),
            'pos' => str_repeat('p', 80),
        ]);

        $this->assertNotNull($row, 'Over-long metadata must not cost the entry');
        $this->assertIsString($row['LeReading']);
        $this->assertIsString($row['LePartOfSpeech']);
        $this->assertSame(250, mb_strlen($row['LeReading'], 'UTF-8'));
        $this->assertSame(50, mb_strlen($row['LePartOfSpeech'], 'UTF-8'));
    }

    #[Test]
    public function leavesShortMetadataUntouched(): void
    {
        $row = $this->buildRow([
            'term' => 'Haus',
            'definition' => 'house',
            'reading' => 'haʊs',
            'pos' => 'noun',
        ]);

        $this->assertNotNull($row);
        $this->assertSame('haʊs', $row['LeReading']);
        $this->assertSame('noun', $row['LePartOfSpeech']);
    }

    /**
     * A definition of any size is fine — LeDefinition is TEXT, so it must not
     * be clipped or cause a skip. FreeDict definitions run to ~14 KB.
     */
    #[Test]
    public function neverClipsTheDefinition(): void
    {
        $definition = str_repeat('d', 20000);

        $row = $this->buildRow(['term' => 'Haus', 'definition' => $definition]);

        $this->assertNotNull($row);
        $this->assertSame($definition, $row['LeDefinition']);
    }
}
