<?php

/**
 * Find Similar Terms Use Case
 *
 * PHP version 8.1
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
use Lwt\Modules\Vocabulary\Application\Services\LetterPairProfile;
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
        $term = $this->calculator->profile($comparedTermLc);

        // Score all rows as they arrive. Only keep > threshold.
        $pool = [];
        $rows = QueryBuilder::table('words')
            ->select(['WoID', 'WoTextLC', 'WoStatus', 'WoLemmaLC'])
            ->where('WoLgID', '=', $languageId)
            ->where('WoTextLC', '<>', $comparedTermLc)
            ->eachPrepared();

        foreach ($rows as $record) {
            $entry = $this->poolEntry(
                [
                    'id' => (int) $record["WoID"],
                    'textLc' => (string) $record["WoTextLC"],
                    'status' => (int) $record["WoStatus"],
                    'lemmaLc' => (string) ($record["WoLemmaLC"] ?? ''),
                ],
                $term,
                $minRanking,
                $phoneticWeight,
                $lemmaLc
            );
            if ($entry !== null) {
                $pool[] = $entry;
            }
        }

        return $this->selectByCoverage($pool, $term, $maxCount, $phoneticWeight);
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
            $entry = $this->poolEntry($candidate, $term, $minRanking, $phoneticWeight, $lemmaLc);
            if ($entry !== null) {
                $pool[] = $entry;
            }
        }

        return $this->selectByCoverage($pool, $term, $maxCount, $phoneticWeight);
    }

    /**
     * Score one candidate and admit it to the pool, or turn it away.
     *
     * Split out so that {@see execute()} can score rows as they stream in
     * rather than collecting them all first — the scoring itself is unchanged,
     * and both callers share this so the two paths cannot drift apart.
     *
     * @param array{id: int, textLc: string, status: int, lemmaLc?: string} $candidate      Candidate
     * @param LetterPairProfile                                             $term           Searched term's profile
     * @param float                                                         $minRanking     Minimum (0-1)
     * @param float                                                         $phoneticWeight Phonetic (0-1)
     * @param string                                                        $lemmaLc        Term's lemma
     *
     * @return array{id: int, profile: LetterPairProfile, family: bool, weight: float, weighted: float}|null
     *         The pool entry, or null when the candidate does not qualify
     */
    private function poolEntry(
        array $candidate,
        LetterPairProfile $term,
        float $minRanking,
        float $phoneticWeight,
        string $lemmaLc
    ): ?array {
        $profile = $this->calculator->profile($candidate['textLc']);
        $baseSimilarity = $this->calculator->getResidualCombinedRanking(
            $profile,
            $term,
            $phoneticWeight
        );
        $isFamily = $this->sharesWordFamily($candidate, $lemmaLc);

        // The threshold reads the unweighted score, as it always has
        if (!$isFamily && $baseSimilarity < $minRanking) {
            return null;
        }

        $statusWeight = $this->calculator->getStatusWeight($candidate['status']);

        return [
            'id' => $candidate['id'],
            'profile' => $profile,
            'family' => $isFamily,
            'weight' => $statusWeight,
            'weighted' => $baseSimilarity * $statusWeight,
        ];
    }

    /**
     * Pick from an already-scored pool, one at a time, by residual coverage.
     *
     * @param list<array{id: int, profile: LetterPairProfile, family: bool, weight: float, weighted: float}> $pool
     *        Candidates that cleared the threshold
     * @param LetterPairProfile $term           Searched term's profile
     * @param int               $maxCount       Maximum to return
     * @param float             $phoneticWeight Phonetic (0-1)
     *
     * @return list<int> Word IDs, most useful first
     */
    private function selectByCoverage(
        array $pool,
        LetterPairProfile $term,
        int $maxCount,
        float $phoneticWeight
    ): array {
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
