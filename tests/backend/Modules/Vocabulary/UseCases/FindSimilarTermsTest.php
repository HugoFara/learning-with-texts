<?php

declare(strict_types=1);

namespace Lwt\Tests\Modules\Vocabulary\UseCases;

use Lwt\Shared\Infrastructure\Globals;
use Lwt\Modules\Vocabulary\Application\UseCases\FindSimilarTerms;
use Lwt\Modules\Vocabulary\Application\Services\SimilarityCalculator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Unit tests for the FindSimilarTerms use case.
 *
 * Note: The execute() method depends on QueryBuilder which requires a database.
 * These tests focus on the constructor and SimilarityCalculator integration.
 * Full integration tests would require a database setup.
 */
class FindSimilarTermsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Globals::reset();
    }

    protected function tearDown(): void
    {
        Globals::reset();
        parent::tearDown();
    }

    // =========================================================================
    // Constructor Tests
    // =========================================================================

    public function testConstructorCreatesDefaultCalculator(): void
    {
        $useCase = new FindSimilarTerms();

        $this->assertInstanceOf(FindSimilarTerms::class, $useCase);
    }

    public function testConstructorAcceptsCustomCalculator(): void
    {
        $calculator = new SimilarityCalculator();
        $useCase = new FindSimilarTerms($calculator);

        $this->assertInstanceOf(FindSimilarTerms::class, $useCase);
    }

    // =========================================================================
    // Integration with SimilarityCalculator
    // =========================================================================

    public function testSimilarityCalculatorGetsCombinedRanking(): void
    {
        $calculator = new SimilarityCalculator();

        // Test the underlying calculator which is used by the use case
        $similarity = $calculator->getCombinedSimilarityRanking('hello', 'hallo', 0.3);

        $this->assertIsFloat($similarity);
        $this->assertGreaterThan(0.0, $similarity);
        $this->assertLessThanOrEqual(1.0, $similarity);
    }

    public function testSimilarityCalculatorGetsStatusWeight(): void
    {
        $calculator = new SimilarityCalculator();

        // Learning statuses (1-5) should have weights
        $this->assertGreaterThan(0.0, $calculator->getStatusWeight(1));
        $this->assertGreaterThan(0.0, $calculator->getStatusWeight(5));

        // Special statuses
        $this->assertGreaterThan(0.0, $calculator->getStatusWeight(98)); // Ignored
        $this->assertGreaterThan(0.0, $calculator->getStatusWeight(99)); // Well-known
    }

    public function testSimilarityCalculatorHigherStatusGetsHigherWeight(): void
    {
        $calculator = new SimilarityCalculator();

        // Higher learning statuses should generally have higher weights
        $weight1 = $calculator->getStatusWeight(1);
        $weight5 = $calculator->getStatusWeight(5);

        $this->assertGreaterThanOrEqual($weight1, $weight5);
    }

    // =========================================================================
    // Edge Cases for Text Comparison
    // =========================================================================

    public function testSimilarityForIdenticalStrings(): void
    {
        $calculator = new SimilarityCalculator();

        $similarity = $calculator->getCombinedSimilarityRanking('test', 'test', 0.3);

        $this->assertEquals(1.0, $similarity);
    }

    public function testSimilarityForCompletelyDifferentStrings(): void
    {
        $calculator = new SimilarityCalculator();

        $similarity = $calculator->getCombinedSimilarityRanking('abc', 'xyz', 0.3);

        $this->assertLessThan(0.5, $similarity);
    }

    public function testSimilarityForEmptyString(): void
    {
        $calculator = new SimilarityCalculator();

        $similarity = $calculator->getCombinedSimilarityRanking('', 'test', 0.3);

        $this->assertIsFloat($similarity);
    }

    public function testSimilarityForUnicodeStrings(): void
    {
        $calculator = new SimilarityCalculator();

        $similarity = $calculator->getCombinedSimilarityRanking('日本語', '日本人', 0.3);

        $this->assertIsFloat($similarity);
        $this->assertGreaterThan(0.0, $similarity);
    }
    #[DataProvider('phoneticWeightProvider')]
    public function testPhoneticWeightAffectsSimilarity(float $weight): void
    {
        $calculator = new SimilarityCalculator();

        $similarity = $calculator->getCombinedSimilarityRanking('hello', 'hallo', $weight);

        $this->assertIsFloat($similarity);
        $this->assertGreaterThanOrEqual(0.0, $similarity);
        $this->assertLessThanOrEqual(1.0, $similarity);
    }

    /**
     * @return array<string, array{float}>
     */
    public static function phoneticWeightProvider(): array
    {
        return [
            'no phonetic' => [0.0],
            'low phonetic' => [0.1],
            'default phonetic' => [0.3],
            'high phonetic' => [0.5],
            'max phonetic' => [1.0],
        ];
    }

    // =========================================================================
    // Coverage-based ranking (#137)
    // =========================================================================

    /**
     * Build candidates from a term => status map, numbering them from 1.
     *
     * @param array<string, int> $terms Terms and their status
     *
     * @return list<array{id: int, textLc: string, status: int}>
     */
    private static function candidates(array $terms): array
    {
        $candidates = [];
        $id = 1;
        foreach ($terms as $textLc => $status) {
            $candidates[] = ['id' => $id, 'textLc' => (string) $textLc, 'status' => $status];
            $id++;
        }
        return $candidates;
    }

    /**
     * Build candidates from a term => [status, lemma] map, numbering from 1.
     *
     * @param array<string, array{int, string}> $terms Terms with status and lemma
     *
     * @return list<array{id: int, textLc: string, status: int, lemmaLc: string}>
     */
    private static function candidatesWithLemmas(array $terms): array
    {
        $candidates = [];
        $id = 1;
        foreach ($terms as $textLc => [$status, $lemmaLc]) {
            $candidates[] = [
                'id' => $id,
                'textLc' => (string) $textLc,
                'status' => $status,
                'lemmaLc' => $lemmaLc,
            ];
            $id++;
        }
        return $candidates;
    }

    public function testCoveringTermBeatsASiblingSharingTheSameHalf(): void
    {
        // The reported case: every word built on "geschwindigkeit" scores on
        // that shared half, so the term explaining the *other* half used to be
        // pushed out. 1 = geschwindigkeit, 2 = begrenzung, 3 = …messer.
        $useCase = new FindSimilarTerms();

        $result = $useCase->rankByCoverage(
            self::candidates([
                'geschwindigkeit' => 1,
                'begrenzung' => 1,
                'geschwindigkeitsmesser' => 1,
            ]),
            'geschwindigkeitsbegrenzung',
            3,
            0.33
        );

        $this->assertSame([1, 2, 3], $result);
    }

    public function testASiblingDoesNotCrowdOutTheOtherHalf(): void
    {
        $useCase = new FindSimilarTerms();

        $result = $useCase->rankByCoverage(
            self::candidates([
                'great' => 1,
                'idea' => 1,
                'greatriver' => 1,
                'greatbuilding' => 1,
            ]),
            'greatidea',
            2,
            0.33
        );

        // "great" explains the head, "idea" the tail — the two siblings that
        // only repeat the head are left out of a two-slot list entirely.
        $this->assertSame([1, 2], $result);
    }

    public function testFirstPickIsStillThePlainPairwiseBest(): void
    {
        $useCase = new FindSimilarTerms();
        $calculator = new SimilarityCalculator();

        $terms = ['begrenzung' => 1, 'geschwindigkeit' => 1, 'geschwindigkeitsmesser' => 1];
        $result = $useCase->rankByCoverage(
            self::candidates($terms),
            'geschwindigkeitsbegrenzung',
            3,
            0.33
        );

        $best = '';
        $bestScore = -1.0;
        foreach (array_keys($terms) as $term) {
            $score = $calculator->getCombinedSimilarityRanking(
                'geschwindigkeitsbegrenzung',
                (string) $term,
                0.3
            );
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = (string) $term;
            }
        }

        $this->assertSame(
            array_search($best, array_keys($terms), true) + 1,
            $result[0]
        );
    }

    public function testStatusWeightStillPromotesKnownTerms(): void
    {
        $useCase = new FindSimilarTerms();

        $unweighted = $useCase->rankByCoverage(
            self::candidates(['great' => 1, 'greatman' => 1]),
            'greatidea',
            1,
            0.33
        );
        $weighted = $useCase->rankByCoverage(
            self::candidates(['great' => 1, 'greatman' => 5]),
            'greatidea',
            1,
            0.33
        );

        $this->assertSame([1], $unweighted);
        $this->assertSame([2], $weighted);
    }

    public function testRespectsMaxCount(): void
    {
        $useCase = new FindSimilarTerms();

        $result = $useCase->rankByCoverage(
            self::candidates(['great' => 1, 'idea' => 1, 'greatriver' => 1]),
            'greatidea',
            2,
            0.33
        );

        $this->assertCount(2, $result);
    }

    public function testMaxCountOfZeroReturnsNothing(): void
    {
        $useCase = new FindSimilarTerms();

        $result = $useCase->rankByCoverage(
            self::candidates(['great' => 1]),
            'greatidea',
            0,
            0.33
        );

        $this->assertSame([], $result);
    }

    public function testDropsCandidatesBelowTheMinimumRanking(): void
    {
        $useCase = new FindSimilarTerms();

        $result = $useCase->rankByCoverage(
            self::candidates(['great' => 1, 'xylophone' => 1]),
            'greatidea',
            5,
            0.33
        );

        $this->assertSame([1], $result);
    }

    public function testReturnsNothingWithoutCandidates(): void
    {
        $useCase = new FindSimilarTerms();

        $this->assertSame([], $useCase->rankByCoverage([], 'greatidea', 5, 0.33));
    }

    public function testFillsTheListOnceTheTermIsFullyExplained(): void
    {
        $useCase = new FindSimilarTerms();

        // "great" + "idea" leave nothing uncovered; the siblings still have to
        // fill the remaining slots rather than be dropped.
        $result = $useCase->rankByCoverage(
            self::candidates([
                'great' => 1,
                'idea' => 1,
                'greatriver' => 1,
                'greatbuilding' => 1,
            ]),
            'greatidea',
            4,
            0.33
        );

        $this->assertCount(4, $result);
        $this->assertSame([1, 2], array_slice($result, 0, 2));
        $this->assertContains(3, $result);
        $this->assertContains(4, $result);
    }

    // =========================================================================
    // Word families (#136)
    // =========================================================================

    public function testAnIrregularFormIsFoundThroughItsLemma(): void
    {
        // "bought" and "buy" share no letter pair at all, so string similarity
        // scores them 0.0 and the old threshold dropped both. 1 = buy (the
        // lemma itself, carrying no lemma of its own), 2 = buying.
        $useCase = new FindSimilarTerms();

        $result = $useCase->rankByCoverage(
            self::candidatesWithLemmas([
                'buy' => [1, ''],
                'buying' => [1, 'buy'],
                'boughs' => [1, ''],
            ]),
            'bought',
            3,
            0.33,
            0.3,
            'buy'
        );

        $this->assertSame([1, 2], array_slice($result, 0, 2));
    }

    public function testFamilyOutranksATermThatMerelyLooksAlike(): void
    {
        $useCase = new FindSimilarTerms();

        // "boughs" scores well against "bought" on letter pairs and is
        // unrelated; "buy" scores nothing and is the lemma.
        $result = $useCase->rankByCoverage(
            self::candidatesWithLemmas(['boughs' => [1, ''], 'buy' => [1, '']]),
            'bought',
            1,
            0.33,
            0.3,
            'buy'
        );

        $this->assertSame([2], $result);
    }

    public function testKnownTermsComeFirstWithinAFamily(): void
    {
        $useCase = new FindSimilarTerms();

        $result = $useCase->rankByCoverage(
            self::candidatesWithLemmas(['buying' => [1, 'buy'], 'buys' => [5, 'buy']]),
            'bought',
            2,
            0.33,
            0.3,
            'buy'
        );

        $this->assertSame([2, 1], $result);
    }

    public function testFamilyMembersStillRespectMaxCount(): void
    {
        $useCase = new FindSimilarTerms();

        $result = $useCase->rankByCoverage(
            self::candidatesWithLemmas([
                'buy' => [1, ''],
                'buying' => [1, 'buy'],
                'buys' => [1, 'buy'],
            ]),
            'bought',
            2,
            0.33,
            0.3,
            'buy'
        );

        $this->assertCount(2, $result);
    }

    public function testAnUnknownLemmaLeavesRankingUntouched(): void
    {
        $useCase = new FindSimilarTerms();

        $terms = ['great' => [1, ''], 'idea' => [1, ''], 'greatriver' => [1, '']];
        $withoutLemma = $useCase->rankByCoverage(
            self::candidatesWithLemmas($terms),
            'greatidea',
            3,
            0.33
        );

        $this->assertSame([1, 2, 3], $withoutLemma);
    }

    public function testAnUnrelatedLemmaAdmitsNobody(): void
    {
        $useCase = new FindSimilarTerms();

        // A lemma nothing in the vocabulary shares must not widen the pool
        $result = $useCase->rankByCoverage(
            self::candidatesWithLemmas(['xylophone' => [1, 'xylophone']]),
            'bought',
            3,
            0.33,
            0.3,
            'buy'
        );

        $this->assertSame([], $result);
    }

    // =========================================================================
    // SQL candidate prefilter (#277)
    // =========================================================================

    /**
     * Decide what the candidate SQL would admit, without a database.
     *
     * Mirrors what MySQL does with the clause: the LIKE tests are substring
     * tests on a lowercase column, and their sum is compared to the threshold.
     *
     * @param array{sql: string, bindings: list<mixed>} $filter    The filter
     * @param string                                    $textLc    Candidate term
     * @param string                                    $lemmaLc   Candidate lemma
     *
     * @return bool
     */
    private static function admits(array $filter, string $textLc, string $lemmaLc = ''): bool
    {
        $bindings = $filter['bindings'];
        $offset = 0;

        // Family conditions come first when the searched term has a lemma
        if (str_starts_with($filter['sql'], '(WoLemmaLC = ?')) {
            $family = (string) $bindings[0];
            if ($lemmaLc === $family || $textLc === $family) {
                return true;
            }
            $offset = 2;
        }

        if (!str_contains($filter['sql'], 'LIKE')) {
            return false;
        }

        $shared = 0;
        $patterns = array_slice($bindings, $offset, count($bindings) - $offset - 1);
        foreach ($patterns as $pattern) {
            $pair = str_replace(['\\%', '\\_', '\\\\'], ['%', '_', '\\'], trim((string) $pattern, '%'));
            if (str_contains($textLc, $pair)) {
                $shared++;
            }
        }

        return $shared >= (int) $bindings[count($bindings) - 1];
    }

    public function testTheFilterAdmitsEveryTermTheRankingWouldKeep(): void
    {
        $useCase = new FindSimilarTerms();

        $vocabulary = [
            'geschwindigkeit' => 'geschwindigkeit',
            'begrenzung' => 'begrenzung',
            'geschwindigkeitsmesser' => 'geschwindigkeit',
            'beschwerde' => 'beschwerde',
            'schwindel' => 'schwindel',
            'katze' => 'katze',
            'hund' => 'hund',
            'unbegrenzt' => 'begrenzen',
        ];
        $searched = 'geschwindigkeitsbegrenzung';

        $candidates = [];
        $id = 1;
        foreach ($vocabulary as $textLc => $lemmaLc) {
            $candidates[] = ['id' => $id, 'textLc' => $textLc, 'status' => 1, 'lemmaLc' => $lemmaLc];
            $id++;
        }

        // Everything the ranking picks must survive the SQL that feeds it
        $kept = $useCase->rankByCoverage($candidates, $searched, count($candidates), 0.33);
        $this->assertNotEmpty($kept, 'the ranking must keep something for this to prove anything');

        $filter = $useCase->buildCandidateFilter($searched, 0.33, '');
        $this->assertNotNull($filter);

        foreach ($kept as $keptId) {
            $candidate = $candidates[$keptId - 1];
            $this->assertTrue(
                self::admits($filter, $candidate['textLc'], $candidate['lemmaLc']),
                "the prefilter dropped {$candidate['textLc']}, which the ranking keeps"
            );
        }
    }

    public function testTheFilterRejectsATermWithNothingInCommon(): void
    {
        $useCase = new FindSimilarTerms();

        $filter = $useCase->buildCandidateFilter('geschwindigkeit', 0.33, '');
        $this->assertNotNull($filter);

        $this->assertFalse(self::admits($filter, 'hund'));
        $this->assertTrue(self::admits($filter, 'geschwindigkeitsmesser'));
    }

    public function testTheFilterAdmitsTheWordFamilyWhateverItLooksLike(): void
    {
        $useCase = new FindSimilarTerms();

        // "bought" and "buy" share no letter pair at all — only the lemma finds them
        $filter = $useCase->buildCandidateFilter('bought', 0.33, 'buy');
        $this->assertNotNull($filter);

        $this->assertTrue(self::admits($filter, 'buys', 'buy'));
        $this->assertTrue(self::admits($filter, 'buy', ''));
        $this->assertFalse(self::admits($filter, 'xylophone', 'xylophone'));
    }

    public function testTheRequiredOverlapGrowsWithTheTermLength(): void
    {
        $useCase = new FindSimilarTerms();

        // ceil(0.33 * pairs / 2), so a longer term demands more shared pairs
        $short = $useCase->buildCandidateFilter('cat', 0.33, '');
        $long = $useCase->buildCandidateFilter('geschwindigkeit', 0.33, '');
        $this->assertNotNull($short);
        $this->assertNotNull($long);

        $this->assertSame(1, $short['bindings'][count($short['bindings']) - 1]);
        $this->assertSame(3, $long['bindings'][count($long['bindings']) - 1]);
    }

    public function testATermTooShortForALetterPairFiltersToNothing(): void
    {
        $useCase = new FindSimilarTerms();

        // No letter pair and no family: it scores zero against every term
        $this->assertNull($useCase->buildCandidateFilter('a', 0.33, ''));
        $this->assertNotNull($useCase->buildCandidateFilter('a', 0.33, 'a'));
    }

    public function testLikeWildcardsInATermAreEscaped(): void
    {
        $useCase = new FindSimilarTerms();

        $filter = $useCase->buildCandidateFilter('100%_off', 0.33, '');
        $this->assertNotNull($filter);

        foreach ($filter['bindings'] as $binding) {
            if (!is_string($binding)) {
                continue;
            }
            $inner = trim($binding, '%');
            $this->assertDoesNotMatchRegularExpression(
                '/(?<!\\\\)[%_]/',
                $inner,
                "unescaped wildcard in LIKE pattern {$binding}"
            );
        }
    }
}
