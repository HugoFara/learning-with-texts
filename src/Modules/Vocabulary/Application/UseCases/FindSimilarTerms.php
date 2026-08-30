<?php

/**
 * Find Similar Terms Use Case
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Vocabulary\Application\UseCases
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Vocabulary\Application\UseCases;

use Lwt\Shared\Infrastructure\Database\QueryBuilder;
use Lwt\Shared\Infrastructure\Database\Settings;
use Lwt\Modules\Vocabulary\Application\Services\SimilarityCalculator;
use Lwt\Modules\Vocabulary\Domain\LemmatizerInterface;
use Lwt\Modules\Vocabulary\Infrastructure\Lemmatizers\DictionaryLemmatizer;
use Lwt\Shared\UI\Helpers\IconHelper;

/**
 * Use case for finding similar terms.
 *
 * @since 3.0.0
 */
class FindSimilarTerms
{
    /**
     * Most candidates ever pulled into PHP for a single lookup.
     *
     * A vocabulary built by hand never comes close; an imported one runs to
     * hundreds of thousands of terms, and profiling every one of them exhausts
     * the memory limit before it ever gets to the ranking. Past this many the
     * selection is arbitrary, which is a far better failure than a fatal error.
     */
    private const MAX_CANDIDATES = 10000;

    /**
     * Most letter pairs of the searched term used to build the prefilter.
     *
     * Each pair costs one LIKE in the candidate query, so a pathologically long
     * term is truncated. Testing a subset only ever admits more candidates than
     * testing all of them, so the filter stays a superset either way.
     */
    private const MAX_FILTER_PAIRS = 40;

    private SimilarityCalculator $calculator;

    /**
     * Lemmatizer used to place an unsaved term in a word family.
     */
    private ?LemmatizerInterface $lemmatizer;

    /**
     * Constructor.
     *
     * @param SimilarityCalculator|null $calculator Similarity calculator
     * @param LemmatizerInterface|null  $lemmatizer Lemmatizer for the searched term
     */
    public function __construct(
        ?SimilarityCalculator $calculator = null,
        ?LemmatizerInterface $lemmatizer = null
    ) {
        $this->calculator = $calculator ?? new SimilarityCalculator();
        $this->lemmatizer = $lemmatizer;
    }

    /**
     * The lemmatizer, built on first use.
     *
     * Deliberately the dictionary one: this runs on every lookup, and the NLP
     * lemmatizer would put a network round-trip in that path. Terms already in
     * the vocabulary carry the lemma their configured lemmatizer produced when
     * they were saved, so an install on spaCy still gets word families here —
     * only a term that has never been saved falls back to this.
     *
     * @return LemmatizerInterface
     */
    private function getLemmatizer(): LemmatizerInterface
    {
        if ($this->lemmatizer === null) {
            $this->lemmatizer = new DictionaryLemmatizer();
        }
        return $this->lemmatizer;
    }

    /**
     * Find similar terms for a given language and term.
     *
     * @param int    $languageId     Language ID
     * @param string $comparedTerm   Term to compare with
     * @param int    $maxCount       Maximum number of terms to return
     * @param float  $minRanking     Minimum similarity ranking (0-1)
     * @param float  $phoneticWeight Weight for phonetic similarity (0-1)
     *
     * @return list<int> Word IDs, most useful first
     */
    public function execute(
        int $languageId,
        string $comparedTerm,
        int $maxCount,
        float $minRanking,
        float $phoneticWeight = 0.3
    ): array {
        if ($maxCount <= 0) {
            return [];
        }

        $comparedTermLc = mb_strtolower($comparedTerm, 'UTF-8');
        $lemmaLc = $this->resolveLemma($languageId, $comparedTermLc);

        $candidates = [];
        foreach ($this->fetchCandidates($languageId, $comparedTermLc, $minRanking, $lemmaLc) as $record) {
            $candidates[] = [
                'id' => (int) $record["WoID"],
                'textLc' => (string) $record["WoTextLC"],
                'status' => (int) $record["WoStatus"],
                'lemmaLc' => (string) ($record["WoLemmaLC"] ?? ''),
            ];
        }

        return $this->rankByCoverage(
            $candidates,
            $comparedTermLc,
            $maxCount,
            $minRanking,
            $phoneticWeight,
            $lemmaLc
        );
    }

    /**
     * Read the terms worth ranking, rather than the whole vocabulary.
     *
     * This used to select every term of the language and let rankByCoverage()
     * throw most of them away. That is affordable for the few thousand terms a
     * reader adds by hand, but a vocabulary seeded from a dictionary import runs
     * to hundreds of thousands, and materialising it exhausted the memory limit
     * on every word click. The admission test is moved into SQL instead, so the
     * rows that come back are the ones that stood a chance.
     *
     * @param int    $languageId     Language ID
     * @param string $comparedTermLc Lowercased term
     * @param float  $minRanking     Minimum similarity ranking (0-1)
     * @param string $lemmaLc        The term's lemma, or an empty string
     *
     * @return array<int, array<string, mixed>> Candidate rows
     */
    private function fetchCandidates(
        int $languageId,
        string $comparedTermLc,
        float $minRanking,
        string $lemmaLc
    ): array {
        $query = QueryBuilder::table('words')
            ->select(['WoID', 'WoTextLC', 'WoStatus', 'WoLemmaLC'])
            ->where('WoLgID', '=', $languageId)
            ->where('WoTextLC', '<>', $comparedTermLc)
            ->limit(self::MAX_CANDIDATES);

        // A non-positive threshold admits every term whatever it looks like, so
        // there is nothing to narrow the scan with and the cap is the only bound.
        if ($minRanking > 0) {
            $filter = $this->buildCandidateFilter($comparedTermLc, $minRanking, $lemmaLc);
            if ($filter === null) {
                return [];
            }
            $query->whereRaw($filter['sql'], $filter['bindings']);
        }

        // Order the scan by how much of the term each candidate covers, so that
        // a vocabulary large enough to hit the cap loses its weakest matches
        // rather than whichever ones happen to have the lowest WoID.
        $overlap = $this->buildOverlapExpression($comparedTermLc);
        if ($overlap !== null) {
            $query->selectRaw(
                'WoID, WoTextLC, WoStatus, WoLemmaLC, ' . $overlap['sql'] . ' AS shared_pairs',
                $overlap['bindings']
            )->orderBy('shared_pairs', 'DESC');
        }

        $rows = $query->getPrepared();

        // That ordering decides which rows survive the cap and nothing else.
        // rankByCoverage() breaks its ties on whichever candidate it saw first,
        // so the rows go back into WoID order before it sees them and a
        // vocabulary that never reaches the cap ranks exactly as it used to.
        usort($rows, fn(array $a, array $b): int => (int) $a['WoID'] <=> (int) $b['WoID']);

        return $rows;
    }

    /**
     * SQL counting the letter pairs a term shares with the searched one.
     *
     * A term pair never spans a space, so it belongs to a candidate's pair set
     * exactly when it is a substring of the candidate — which makes the size of
     * the intersection a sum of LIKE tests.
     *
     * @param string $comparedTermLc Lowercased term
     *
     * @return array{sql: string, bindings: list<mixed>, pairCount: int}|null Null
     *         when the term is too short to have a letter pair
     */
    public function buildOverlapExpression(string $comparedTermLc): ?array
    {
        $pairs = array_slice(
            $this->calculator->wordLetterPairs($comparedTermLc),
            0,
            self::MAX_FILTER_PAIRS
        );
        if ($pairs === []) {
            return null;
        }

        $tests = [];
        $bindings = [];
        foreach ($pairs as $pair) {
            $tests[] = '(WoTextLC LIKE ?)';
            $bindings[] = '%' . addcslashes($pair, '\\%_') . '%';
        }

        return [
            'sql' => '(' . implode(' + ', $tests) . ')',
            'bindings' => $bindings,
            'pairCount' => count($pairs),
        ];
    }

    /**
     * SQL admitting every term that could clear the threshold.
     *
     * Two ways in, matching the two ways rankByCoverage() admits a candidate.
     * A term of the same word family gets in whatever it scores, exactly as the
     * ranking treats it. Everything else has to share enough letter pairs with
     * the searched term: the Dice coefficient is 2|A∩B| / (|A|+|B|), so clearing
     * a threshold t needs |A∩B| >= t(|A|+|B|)/2, and dropping the candidate's own
     * pair count — unknown until it is read — leaves the weaker t|B|/2 that can
     * be tested in SQL.
     *
     * The bound covers the character half of the score. The phonetic half can in
     * principle carry a candidate over on fewer shared pairs, which is why the
     * loose form of the bound is the one used, but a term whose spelling has
     * almost nothing in common with the searched one is not admitted on
     * pronunciation alone. That is the one way this narrows the old behaviour.
     *
     * @param string $comparedTermLc Lowercased term
     * @param float  $minRanking     Minimum similarity ranking (0-1)
     * @param string $lemmaLc        The term's lemma, or an empty string
     *
     * @return array{sql: string, bindings: list<mixed>}|null Null when no term
     *         can qualify, so the query is not worth running
     */
    public function buildCandidateFilter(
        string $comparedTermLc,
        float $minRanking,
        string $lemmaLc
    ): ?array {
        $conditions = [];
        $bindings = [];

        // Same word family: admitted on the lemma, or on being the lemma
        if ($lemmaLc !== '') {
            $conditions[] = 'WoLemmaLC = ?';
            $conditions[] = 'WoTextLC = ?';
            $bindings[] = $lemmaLc;
            $bindings[] = $lemmaLc;
        }

        $overlap = $this->buildOverlapExpression($comparedTermLc);
        if ($overlap !== null) {
            $conditions[] = $overlap['sql'] . ' >= ?';
            $bindings = array_merge(
                $bindings,
                $overlap['bindings'],
                [self::minimumSharedPairs($minRanking, $overlap['pairCount'])]
            );
        }

        if ($conditions === []) {
            // A term too short to have a letter pair scores zero against
            // everything, and it has no family to fall back on
            return null;
        }

        return [
            'sql' => '(' . implode(' OR ', $conditions) . ')',
            'bindings' => $bindings,
        ];
    }

    /**
     * Letter pairs a candidate must share before it could reach the threshold.
     *
     * @param float $minRanking    Minimum similarity ranking (0-1)
     * @param int   $termPairCount Letter pairs in the searched term
     *
     * @return int Always at least one: nothing scores without an overlap
     */
    private static function minimumSharedPairs(float $minRanking, int $termPairCount): int
    {
        return max(1, (int) ceil($minRanking * $termPairCount / 2));
    }

    /**
     * Work out which word family the searched term belongs to.
     *
     * Prefers the lemma already stored on the term, so whatever lemmatizer the
     * language is configured with is the one that decides. Only a term that is
     * not in the vocabulary yet — the common case when adding a word while
     * reading — is looked up in the dictionary. A word the dictionary does not
     * know is its own lemma, which is what makes searching a base form pull up
     * its inflections.
     *
     * @param int    $languageId     Language ID
     * @param string $comparedTermLc Lowercased term
     *
     * @return string Lowercased lemma, or an empty string when unavailable
     */
    private function resolveLemma(int $languageId, string $comparedTermLc): string
    {
        if ($comparedTermLc === '') {
            return '';
        }

        $language = QueryBuilder::table('languages')
            ->select(['LgSourceLang', 'LgLemmatizerType'])
            ->where('LgID', '=', $languageId)
            ->firstPrepared();
        if ($language === null) {
            return '';
        }

        // The language opted out of lemmatization entirely
        $lemmatizerType = strtolower(trim((string) ($language['LgLemmatizerType'] ?? '')));
        if ($lemmatizerType === 'none') {
            return '';
        }

        $stored = QueryBuilder::table('words')
            ->select(['WoLemmaLC'])
            ->where('WoLgID', '=', $languageId)
            ->where('WoTextLC', '=', $comparedTermLc)
            ->firstPrepared();
        $storedLemma = trim((string) ($stored['WoLemmaLC'] ?? ''));
        if ($storedLemma !== '') {
            return mb_strtolower($storedLemma, 'UTF-8');
        }

        $languageCode = trim((string) ($language['LgSourceLang'] ?? ''));
        if ($languageCode !== '') {
            $lemma = $this->getLemmatizer()->lemmatize($comparedTermLc, $languageCode);
            if ($lemma !== null && trim($lemma) !== '') {
                return mb_strtolower(trim($lemma), 'UTF-8');
            }
        }

        return $comparedTermLc;
    }

    /**
     * Pick the terms that between them explain the most of the compared term.
     *
     * Ranking each candidate against the whole term independently — what this
     * used to do — makes a compound's siblings crowd out its parts: every word
     * sharing "geschwindigkeit" scores on that shared half, so the term that
     * would explain the *other* half never makes the list. So the picks are
     * made one at a time, and after each one the term shrinks to the part still
     * unexplained. A candidate that only repeats an earlier pick then scores
     * near zero, and a short term covering fresh ground wins on merit.
     *
     * The first pick is unchanged: with nothing covered yet, the score is the
     * plain pairwise similarity. Admission to the pool uses that same pairwise
     * score against the minimum ranking, so this changes which candidates are
     * chosen and in what order, never which ones were eligible.
     *
     * Terms of the same word family are the exception, and come first. Letter
     * pairs cannot reach an irregular form — "bought" and "buy" share none at
     * all, and no amount of tuning would have found them — so a shared lemma
     * admits a candidate whatever it scores, and ranks it above the terms that
     * merely look alike.
     *
     * @param list<array{id: int, textLc: string, status: int, lemmaLc?: string}> $candidates     Candidates
     * @param string                                                              $comparedTermLc Lowercased term
     * @param int                                                                 $maxCount       Maximum to return
     * @param float                                                               $minRanking     Minimum (0-1)
     * @param float                                                               $phoneticWeight Phonetic (0-1)
     * @param string                                                              $lemmaLc        Term's lemma
     *
     * @return list<int> Word IDs, most useful first
     */
    public function rankByCoverage(
        array $candidates,
        string $comparedTermLc,
        int $maxCount,
        float $minRanking,
        float $phoneticWeight = 0.3,
        string $lemmaLc = ''
    ): array {
        if ($maxCount <= 0) {
            return [];
        }

        $term = $this->calculator->profile($comparedTermLc);

        $pool = [];
        foreach ($candidates as $candidate) {
            $profile = $this->calculator->profile($candidate['textLc']);
            $baseSimilarity = $this->calculator->getResidualCombinedRanking(
                $profile,
                $term,
                $phoneticWeight
            );
            $isFamily = $this->sharesWordFamily($candidate, $lemmaLc);

            // The threshold reads the unweighted score, as it always has
            if (!$isFamily && $baseSimilarity < $minRanking) {
                continue;
            }

            $statusWeight = $this->calculator->getStatusWeight($candidate['status']);
            $pool[] = [
                'id' => $candidate['id'],
                'profile' => $profile,
                'family' => $isFamily,
                'weight' => $statusWeight,
                'weighted' => $baseSimilarity * $statusWeight,
            ];
        }

        $remaining = $term;
        $picked = [];
        $wanted = min($maxCount, count($pool));

        for ($i = 0; $i < $wanted; $i++) {
            $bestIndex = null;
            $bestFamily = false;
            $bestGain = -1.0;
            $bestWeighted = -1.0;
            $bestStatus = -1.0;

            foreach ($pool as $index => $entry) {
                $gain = $this->calculator->getResidualCombinedRanking(
                    $entry['profile'],
                    $remaining,
                    $phoneticWeight
                ) * $entry['weight'];

                // Family first; then whichever explains the most of what is
                // left. Once the term is fully explained every gain is zero, so
                // the pairwise score and then the status decide the remainder.
                if ($bestIndex === null) {
                    $isBetter = true;
                } elseif ($entry['family'] !== $bestFamily) {
                    $isBetter = $entry['family'];
                } elseif (abs($gain - $bestGain) > 1e-9) {
                    $isBetter = $gain > $bestGain;
                } elseif (abs($entry['weighted'] - $bestWeighted) > 1e-9) {
                    $isBetter = $entry['weighted'] > $bestWeighted;
                } else {
                    $isBetter = $entry['weight'] > $bestStatus;
                }

                if ($isBetter) {
                    $bestIndex = $index;
                    $bestFamily = $entry['family'];
                    $bestGain = $gain;
                    $bestWeighted = $entry['weighted'];
                    $bestStatus = $entry['weight'];
                }
            }

            if ($bestIndex === null) {
                break;
            }

            $picked[] = $pool[$bestIndex]['id'];
            $remaining = $remaining->minus($pool[$bestIndex]['profile']);
            unset($pool[$bestIndex]);
        }

        return $picked;
    }

    /**
     * Whether a candidate belongs to the searched term's word family.
     *
     * Matches on the candidate's own lemma, and on the candidate *being* the
     * lemma — a base form usually carries no lemma of its own.
     *
     * @param array{id: int, textLc: string, status: int, lemmaLc?: string} $candidate Candidate
     * @param string                                                        $lemmaLc   Term's lemma
     *
     * @return bool
     */
    private function sharesWordFamily(array $candidate, string $lemmaLc): bool
    {
        if ($lemmaLc === '') {
            return false;
        }

        $candidateLemma = mb_strtolower(trim($candidate['lemmaLc'] ?? ''), 'UTF-8');

        return $candidateLemma === $lemmaLc || $candidate['textLc'] === $lemmaLc;
    }

    /**
     * Format a similar term for display.
     *
     * @param int    $termId  Term ID
     * @param string $compare Similar term to compare with
     *
     * @return string HTML-formatted string
     */
    public function formatTerm(int $termId, string $compare): string
    {
        $record = QueryBuilder::table('words')
            ->select(['WoText', 'WoTranslation', 'WoRomanization'])
            ->where('WoID', '=', $termId)
            ->firstPrepared();
        if ($record !== null) {
            $term = htmlspecialchars((string)($record["WoText"] ?? ''), ENT_QUOTES, 'UTF-8');
            if (stripos($compare, $term) !== false) {
                $term = '<span class="has-text-danger">' . $term . '</span>';
            } else {
                $term = str_replace(
                    $compare,
                    '<span class="has-text-danger"><u>' . $compare . '</u></span>',
                    $term
                );
            }
            $tra = (string) $record["WoTranslation"];
            if ($tra == "*") {
                $tra = "???";
            }
            if (trim((string) $record["WoRomanization"]) !== '') {
                $rom = (string) $record["WoRomanization"];
                $romd = " [$rom]";
            } else {
                $rom = "";
                $romd = "";
            }
            $output = IconHelper::render('check-circle', [
                'class' => 'clickedit',
                'title' => 'Copy → Translation & Romanization Field(s)',
                'data-action' => 'set-trans-roman',
                'data-translation' => htmlspecialchars($tra, ENT_QUOTES, 'UTF-8'),
                'data-romanization' => htmlspecialchars($rom, ENT_QUOTES, 'UTF-8')
            ]) . ' ' .
            $term . htmlspecialchars($romd, ENT_QUOTES, 'UTF-8') . ' — ' . htmlspecialchars($tra, ENT_QUOTES, 'UTF-8') .
            '<br />';
            return $output;
        }
        return "";
    }

    /**
     * Get formatted HTML for similar terms.
     *
     * @param int    $languageId   Language ID
     * @param string $comparedTerm Term to compare with
     *
     * @return string HTML output
     */
    public function getFormattedTerms(int $languageId, string $comparedTerm): string
    {
        $maxCount = (int) Settings::getWithDefault("set-similar-terms-count");
        if ($maxCount <= 0) {
            return '';
        }
        if (trim($comparedTerm) == '') {
            return '&nbsp;';
        }
        $compare = htmlspecialchars($comparedTerm, ENT_QUOTES, 'UTF-8');
        $termarr = $this->execute($languageId, $comparedTerm, $maxCount, 0.33);
        $rarr = [];
        foreach ($termarr as $termid) {
            $similar_term = $this->formatTerm($termid, $compare);
            if ($similar_term != "") {
                $rarr[] = $similar_term;
            }
        }
        if (count($rarr) == 0) {
            return "(none)";
        }
        return implode($rarr);
    }

    /**
     * Get HTML for similar terms table row.
     *
     * @return string HTML output or empty string
     */
    public function getTableRow(): string
    {
        if ((int) Settings::getWithDefault("set-similar-terms-count") > 0) {
            return '<tr>
                <td class="has-text-right">Similar<br />Terms:</td>
                <td><span id="simwords" class="is-size-7">&nbsp;</span></td>
            </tr>';
        }
        return '';
    }
}
