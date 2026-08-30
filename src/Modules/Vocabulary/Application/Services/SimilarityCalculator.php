<?php

/**
 * Similarity Calculator - Core similarity algorithms
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Vocabulary\Application\Services
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Vocabulary\Application\Services;

/**
 * Service class for calculating term similarity.
 *
 * Contains algorithms for phonetic normalization and similarity ranking
 * using the Sørensen–Dice coefficient.
 *
 * @since 3.0.0
 */
class SimilarityCalculator
{
    /**
     * Weight multiplier for learned words (status 5).
     */
    public const STATUS_WEIGHT_LEARNED = 1.3;

    /**
     * Weight multiplier for words in progress (status 2-4).
     */
    public const STATUS_WEIGHT_IN_PROGRESS = 1.15;

    /**
     * Weight multiplier for new words (status 1).
     */
    public const STATUS_WEIGHT_NEW = 1.0;

    /**
     * Weight multiplier for well-known words (status 99).
     */
    public const STATUS_WEIGHT_WELL_KNOWN = 1.25;

    /**
     * Weight multiplier for ignored words (status 98).
     */
    public const STATUS_WEIGHT_IGNORED = 0.5;

    /**
     * Phonetic character mapping for normalization.
     * Maps similar-sounding characters to a common representation.
     *
     * @var array<string, string>
     */
    private static array $phoneticMap = [
        // Vowel groups
        'a' => 'a', 'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
        'å' => 'a', 'ā' => 'a', 'ă' => 'a', 'ą' => 'a', 'æ' => 'ae',
        'e' => 'e', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ē' => 'e',
        'ĕ' => 'e', 'ė' => 'e', 'ę' => 'e', 'ě' => 'e',
        'i' => 'i', 'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ĩ' => 'i',
        'ī' => 'i', 'ĭ' => 'i', 'į' => 'i', 'ı' => 'i', 'y' => 'i',
        'o' => 'o', 'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
        'ō' => 'o', 'ŏ' => 'o', 'ő' => 'o', 'ø' => 'o', 'œ' => 'oe',
        'u' => 'u', 'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ũ' => 'u',
        'ū' => 'u', 'ŭ' => 'u', 'ů' => 'u', 'ű' => 'u', 'ų' => 'u',
        // Consonant groups - similar sounds
        'b' => 'b', 'p' => 'p',
        'c' => 'k', 'k' => 'k', 'q' => 'k', 'ç' => 's', 'ć' => 'c', 'č' => 'c',
        'd' => 'd', 't' => 't', 'ð' => 'd', 'þ' => 't',
        'f' => 'f', 'v' => 'v', 'ph' => 'f',
        'g' => 'g', 'ğ' => 'g', 'ģ' => 'g', 'j' => 'j',
        'h' => 'h',
        'l' => 'l', 'ł' => 'l', 'ľ' => 'l', 'ĺ' => 'l', 'ļ' => 'l',
        'm' => 'm', 'n' => 'n', 'ñ' => 'n', 'ń' => 'n', 'ň' => 'n', 'ņ' => 'n',
        'r' => 'r', 'ŕ' => 'r', 'ř' => 'r', 'ŗ' => 'r',
        's' => 's', 'z' => 's', 'ś' => 's', 'š' => 's', 'ş' => 's', 'ź' => 's',
        'ż' => 's', 'ž' => 's', 'ß' => 'ss',
        'w' => 'w',
        'x' => 'ks',
    ];

    /**
     * Normalize a string for phonetic comparison.
     *
     * Applies phonetic transformations to make similar-sounding words
     * more likely to match.
     *
     * @param string $str Input string (should be lowercase)
     *
     * @return string Phonetically normalized string
     */
    public function phoneticNormalize(string $str): string
    {
        $result = '';
        $length = mb_strlen($str, 'UTF-8');
        $prevChar = '';

        for ($i = 0; $i < $length; $i++) {
            $char = mb_substr($str, $i, 1, 'UTF-8');

            // Check for multi-character mappings first (like 'ph' -> 'f')
            if ($i < $length - 1) {
                $twoChars = mb_substr($str, $i, 2, 'UTF-8');
                if (isset(self::$phoneticMap[$twoChars])) {
                    $mapped = self::$phoneticMap[$twoChars];
                    if ($mapped !== $prevChar) {
                        $result .= $mapped;
                        $prevChar = $mapped;
                    }
                    $i++;
                    continue;
                }
            }

            // Single character mapping
            $mapped = self::$phoneticMap[$char] ?? $char;

            // Skip consecutive duplicate characters
            if ($mapped !== $prevChar) {
                $result .= $mapped;
                $prevChar = $mapped;
            }
        }

        return $result;
    }

    /**
     * Get weight multiplier based on word status.
     *
     * @param int $status Word status (1-5, 98=ignored, 99=well-known)
     *
     * @return float Weight multiplier
     */
    public function getStatusWeight(int $status): float
    {
        return match ($status) {
            5 => self::STATUS_WEIGHT_LEARNED,
            2, 3, 4 => self::STATUS_WEIGHT_IN_PROGRESS,
            99 => self::STATUS_WEIGHT_WELL_KNOWN,
            98 => self::STATUS_WEIGHT_IGNORED,
            default => self::STATUS_WEIGHT_NEW,
        };
    }

    /**
     * Get letter pairs from string.
     *
     * @param string $str Input string
     *
     * @return string[]
     */
    public function letterPairs(string $str): array
    {
        $numPairs = mb_strlen($str) - 1;
        $pairs = [];
        for ($i = 0; $i < $numPairs; $i++) {
            $pairs[$i] = mb_substr($str, $i, 2);
        }
        return $pairs;
    }

    /**
     * Get word letter pairs from string.
     *
     * @param string $str Input string
     *
     * @return string[]
     */
    public function wordLetterPairs(string $str): array
    {
        $allPairs = [];
        $words = explode(' ', $str);
        foreach ($words as $word) {
            $pairsInWord = $this->letterPairs($word);
            foreach ($pairsInWord as $pair) {
                $allPairs[$pair] = $pair;
            }
        }
        return array_values($allPairs);
    }

    /**
     * Similarity ranking of two UTF-8 strings using Sørensen–Dice coefficient.
     *
     * Source http://www.catalysoft.com/articles/StrikeAMatch.html
     * Source http://stackoverflow.com/questions/653157
     *
     * @param string $str1 First string
     * @param string $str2 Second string
     *
     * @return float Similarity ranking (0-1)
     */
    public function getSimilarityRanking(string $str1, string $str2): float
    {
        return $this->dice(
            $this->wordLetterPairs($str1),
            $this->wordLetterPairs($str2)
        );
    }

    /**
     * Sørensen–Dice coefficient of two sets of letter pairs.
     *
     * @param string[] $pairs1 First set of letter pairs
     * @param string[] $pairs2 Second set of letter pairs
     *
     * @return float Similarity ranking (0-1)
     */
    private function dice(array $pairs1, array $pairs2): float
    {
        $union = count($pairs1) + count($pairs2);
        if ($union == 0) {
            return 0;
        }
        $intersection = count(array_intersect($pairs1, $pairs2));
        return 2 * $intersection / $union;
    }

    /**
     * Build the letter-pair profile of a term.
     *
     * Profiling a term once and comparing the profile is equivalent to calling
     * getCombinedSimilarityRanking(), but does not redo the phonetic
     * normalization on every comparison.
     *
     * @param string $str Input string (should be lowercase)
     *
     * @return LetterPairProfile
     */
    public function profile(string $str): LetterPairProfile
    {
        return new LetterPairProfile(
            array_values($this->wordLetterPairs($str)),
            array_values($this->wordLetterPairs($this->phoneticNormalize($str)))
        );
    }

    /**
     * Similarity of a candidate against the part of a term left to explain.
     *
     * Same coefficient as getCombinedSimilarityRanking(), but the term side is
     * whatever is left of it — so a candidate that only repeats what an earlier
     * suggestion already covered scores near zero, however long the overlap
     * with the whole term was. Passing the full term profile as $remaining
     * reproduces getCombinedSimilarityRanking() exactly.
     *
     * @param LetterPairProfile $candidate      Candidate term profile
     * @param LetterPairProfile $remaining      Not-yet-explained part of the term
     * @param float             $phoneticWeight Weight for phonetic similarity (0-1)
     *
     * @return float Combined similarity ranking (0-1)
     */
    public function getResidualCombinedRanking(
        LetterPairProfile $candidate,
        LetterPairProfile $remaining,
        float $phoneticWeight = 0.3
    ): float {
        $charSimilarity = $this->dice($candidate->chars(), $remaining->chars());
        $phoneticSimilarity = $this->dice($candidate->phonetics(), $remaining->phonetics());

        return (1 - $phoneticWeight) * $charSimilarity
            + $phoneticWeight * $phoneticSimilarity;
    }

    /**
     * Combined similarity ranking using character pairs and phonetic matching.
     *
     * @param string $str1           First string (lowercase)
     * @param string $str2           Second string (lowercase)
     * @param float  $phoneticWeight Weight for phonetic similarity (0-1)
     *
     * @return float Combined similarity ranking (0-1)
     */
    public function getCombinedSimilarityRanking(
        string $str1,
        string $str2,
        float $phoneticWeight = 0.3
    ): float {
        $charSimilarity = $this->getSimilarityRanking($str1, $str2);

        $phonetic1 = $this->phoneticNormalize($str1);
        $phonetic2 = $this->phoneticNormalize($str2);
        $phoneticSimilarity = $this->getSimilarityRanking($phonetic1, $phonetic2);

        return (1 - $phoneticWeight) * $charSimilarity
            + $phoneticWeight * $phoneticSimilarity;
    }
}
