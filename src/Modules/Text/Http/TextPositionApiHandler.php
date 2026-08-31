<?php

/**
 * Text Position API Handler
 *
 * Handles text position, audio position, display mode, and bulk word status
 * operations. Extracted from TextApiHandler.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Text\Http
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Text\Http;

use Lwt\Shared\Infrastructure\Database\QueryBuilder;
use Lwt\Shared\Infrastructure\Database\Settings;
use Lwt\Modules\Vocabulary\Application\Services\WordDiscoveryService;

/**
 * Handler for text position, audio, display mode, and bulk status operations.
 */
class TextPositionApiHandler
{
    private WordDiscoveryService $discoveryService;

    public function __construct(?WordDiscoveryService $discoveryService = null)
    {
        $this->discoveryService = $discoveryService ?? new WordDiscoveryService();
    }

    /**
     * Save the reading position of the text.
     *
     * @param int $textid   Text ID
     * @param int $position Position in text to save
     *
     * @return void
     */
    public function saveTextPosition(int $textid, int $position): void
    {
        QueryBuilder::table('texts')
            ->where('TxID', '=', $textid)
            ->updatePrepared(['TxPosition' => $position]);
    }

    /**
     * Upper bound for a stored audio position, in seconds.
     *
     * 24h is well past any single-text recording while still small enough
     * that storing nonsense (Number.MAX_VALUE from a buggy player, an
     * Infinity coerced to float) can't overflow downstream consumers.
     */
    public const MAX_AUDIO_POSITION = 86400.0;

    /**
     * Save the audio position in the text.
     *
     * @param int   $textid        Text ID
     * @param float $audioposition Audio position in seconds
     *
     * @return void
     */
    public function saveAudioPosition(int $textid, float $audioposition): void
    {
        QueryBuilder::table('texts')
            ->where('TxID', '=', $textid)
            ->updatePrepared(['TxAudioPosition' => self::sanitizePosition($audioposition)]);
    }

    /**
     * Coerce a client-supplied audio position into a safe FLOAT.
     *
     * Browser players occasionally emit NaN (seeking before metadata
     * loaded), Infinity (live streams), or negative values (rewind past
     * zero). The DB column is FLOAT but UI assumes a sane second-offset,
     * so clamp to [0, MAX_AUDIO_POSITION] and reject non-finite values.
     */
    private static function sanitizePosition(float $position): float
    {
        if (!is_finite($position) || $position < 0.0) {
            return 0.0;
        }
        if ($position > self::MAX_AUDIO_POSITION) {
            return self::MAX_AUDIO_POSITION;
        }
        return $position;
    }

    /**
     * Format response for setting text position.
     *
     * @param int $textId   Text ID
     * @param int $position Position
     *
     * @return array{text: string}
     */
    public function formatSetTextPosition(int $textId, int $position): array
    {
        $this->saveTextPosition($textId, $position);
        return ["text" => "Reading position set"];
    }

    /**
     * Format response for setting audio position.
     *
     * @param int   $textId   Text ID
     * @param float $position Audio position in seconds
     *
     * @return array{audio: string}
     */
    public function formatSetAudioPosition(int $textId, float $position): array
    {
        $this->saveAudioPosition($textId, $position);
        return ["audio" => "Audio position set"];
    }

    /**
     * Set display mode settings for a text.
     *
     * @param int       $textId       Text ID
     * @param int|null  $annotations  Annotation mode (0=none, 1=translations, 2=romanization, 3=both)
     * @param bool|null $romanization Whether to show romanization
     * @param bool|null $translation  Whether to show translation
     *
     * @return array{updated: bool, error?: string}
     */
    public function setDisplayMode(int $textId, ?int $annotations, ?bool $romanization, ?bool $translation): array
    {
        $exists = QueryBuilder::table('texts')
            ->where('TxID', '=', $textId)
            ->existsPrepared();

        if (!$exists) {
            return ['updated' => false, 'error' => 'Text not found'];
        }

        if ($annotations !== null) {
            Settings::savePerUser('set-text-h-annotations', (string)$annotations);
        }

        if ($romanization !== null) {
            Settings::savePerUser('set-display-romanization', $romanization ? '1' : '0');
        }

        if ($translation !== null) {
            Settings::savePerUser('set-display-translation', $translation ? '1' : '0');
        }

        return ['updated' => true];
    }

    /**
     * Format response for setting display mode.
     *
     * @param int   $textId Text ID
     * @param array $params Display mode parameters
     *
     * @return array{updated: bool, error?: string}
     */
    public function formatSetDisplayMode(int $textId, array $params): array
    {
        $annotations = isset($params['annotations']) ? (int)$params['annotations'] : null;
        $romanization = isset($params['romanization'])
            ? filter_var($params['romanization'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            : null;
        $translation = isset($params['translation'])
            ? filter_var($params['translation'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            : null;

        return $this->setDisplayMode($textId, $annotations, $romanization, $translation);
    }

    /**
     * Mark all unknown words in a text as well-known.
     *
     * @param int $textId Text ID
     *
     * @return array{count: int, words?: array}
     */
    public function markAllWellKnown(int $textId): array
    {
        list($count, $wordsData) = $this->discoveryService->markAllWordsWithStatus($textId, 99);
        return [
            'count' => $count,
            'words' => $wordsData
        ];
    }

    /**
     * Mark all unknown words in a text as ignored.
     *
     * @param int $textId Text ID
     *
     * @return array{count: int, words?: array}
     */
    public function markAllIgnored(int $textId): array
    {
        list($count, $wordsData) = $this->discoveryService->markAllWordsWithStatus($textId, 98);
        return [
            'count' => $count,
            'words' => $wordsData
        ];
    }

    /**
     * Format response for marking all words as well-known.
     *
     * @param int $textId Text ID
     *
     * @return array{count: int, words?: array}
     */
    public function formatMarkAllWellKnown(int $textId): array
    {
        return $this->markAllWellKnown($textId);
    }

    /**
     * Format response for marking all words as ignored.
     *
     * @param int $textId Text ID
     *
     * @return array{count: int, words?: array}
     */
    public function formatMarkAllIgnored(int $textId): array
    {
        return $this->markAllIgnored($textId);
    }
}
