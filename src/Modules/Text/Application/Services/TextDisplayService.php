<?php

/**
 * Text Display Service - Business logic for displaying annotated texts
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Text\Application\Services
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Text\Application\Services;

use Lwt\Shared\Infrastructure\Database\Connection;
use Lwt\Shared\Infrastructure\Database\QueryBuilder;
use Lwt\Shared\Infrastructure\Database\Settings;

/**
 * Service class for displaying annotated texts.
 *
 * Handles data retrieval for improved text display views.
 *
 * @category Lwt
 * @package  Lwt\Modules\Text\Application\Services
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */
class TextDisplayService
{
    /**
     * Get header data for a text.
     *
     * @param int $textId Text ID
     *
     * @return array{title: string, audio: string, sourceUri: string|null}|null
     */
    public function getHeaderData(int $textId): ?array
    {
        $record = QueryBuilder::table('texts')
            ->select(['TxTitle', 'TxAudioURI', 'TxSourceURI'])
            ->where('TxID', '=', $textId)
            ->firstPrepared();

        if ($record === null) {
            return null;
        }

        $audio = '';
        if (isset($record['TxAudioURI'])) {
            $audio = trim((string) $record['TxAudioURI']);
        }

        Settings::savePerUser('currenttext', $textId);

        return [
            'title' => (string) $record['TxTitle'],
            'audio' => $audio,
            'sourceUri' => $record['TxSourceURI'] !== null ? (string) $record['TxSourceURI'] : null
        ];
    }

    /**
     * Get text display settings (text size, RTL).
     *
     * @param int $textId Text ID
     *
     * @return array{textSize: int, rtlScript: bool}|null
     */
    public function getTextDisplaySettings(int $textId): ?array
    {
        $record = QueryBuilder::table('texts')
            ->select(['LgTextSize', 'LgRightToLeft'])
            ->join('languages', 'LgID', '=', 'TxLgID')
            ->where('TxID', '=', $textId)
            ->firstPrepared();

        if ($record === null) {
            return null;
        }

        return [
            'textSize' => (int) $record['LgTextSize'],
            'rtlScript' => (bool) $record['LgRightToLeft']
        ];
    }

    /**
     * Get annotated text content.
     *
     * @param int $textId Text ID
     *
     * @return string Annotated text
     */
    public function getAnnotatedText(int $textId): string
    {
        $record = QueryBuilder::table('texts')
            ->select(['TxAnnotatedText'])
            ->where('TxID', '=', $textId)
            ->firstPrepared();

        return $record !== null ? (string) $record['TxAnnotatedText'] : '';
    }

    /**
     * Get audio URI for a text.
     *
     * @param int $textId Text ID
     *
     * @return string|null Audio URI or null
     */
    public function getAudioUri(int $textId): ?string
    {
        $record = QueryBuilder::table('texts')
            ->select(['TxAudioURI'])
            ->where('TxID', '=', $textId)
            ->firstPrepared();

        return $record !== null && $record['TxAudioURI'] !== null
            ? (string) $record['TxAudioURI']
            : null;
    }

    /**
     * Get word romanization by ID.
     *
     * @param int $wordId Word ID
     *
     * @return string Romanization or empty string
     */
    public function getWordRomanization(int $wordId): string
    {
        $record = QueryBuilder::table('words')
            ->select(['WoRomanization'])
            ->where('WoID', '=', $wordId)
            ->firstPrepared();

        return $record !== null && $record['WoRomanization'] !== null
            ? (string) $record['WoRomanization']
            : '';
    }

    /**
     * Parse annotation item into display data.
     *
     * @param string $item Annotation item (tab-separated values)
     *
     * @return array{type: int, text: string, trans: string, rom: string}|null
     */
    public function parseAnnotationItem(string $item): ?array
    {
        $vals = preg_split('/[\t]/u', $item);

        if (!is_array($vals) || count($vals) < 2) {
            return null;
        }

        $type = (int) $vals[0];
        $text = $vals[1];
        $trans = '';
        $rom = '';

        if ($type > -1) {
            // Word with potential annotation
            if (count($vals) > 2 && $vals[2] !== '') {
                $wid = (int) $vals[2];
                $rom = $this->getWordRomanization($wid);
            }
            if (count($vals) > 3) {
                $trans = $vals[3];
            }
            if ($trans === '*') {
                $trans = $text . " "; // <- U+200A HAIR SPACE
            }
        }

        return [
            'type' => $type,
            'text' => $text,
            'trans' => $trans,
            'rom' => $rom
        ];
    }

    /**
     * Parse all annotations from annotated text.
     *
     * @param string $annotatedText Raw annotated text
     *
     * @return array Array of parsed annotation items
     */
    public function parseAnnotations(string $annotatedText): array
    {
        $items = preg_split('/[\n]/u', $annotatedText);
        if ($items === false) {
            return [];
        }
        $parsed = [];

        foreach ($items as $item) {
            $parsedItem = $this->parseAnnotationItem($item);
            if ($parsedItem !== null) {
                $parsed[] = $parsedItem;
            }
        }

        return $parsed;
    }

    /**
     * Save current text ID to settings.
     *
     * @param int $textId Text ID
     *
     * @return void
     */
    public function saveCurrentText(int $textId): void
    {
        Settings::savePerUser('currenttext', $textId);
    }
}
