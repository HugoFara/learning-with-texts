<?php

/**
 * Reparse Language Texts Use Case
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Language\Application\UseCases
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Language\Application\UseCases;

use Lwt\Shared\Infrastructure\Database\Maintenance;
use Lwt\Shared\Infrastructure\Database\QueryBuilder;
use Lwt\Shared\Infrastructure\Database\TextParsing;

/**
 * Use case for reparsing all texts for a language.
 *
 * @since 3.0.0
 */
class ReparseLanguageTexts
{
    /**
     * Refresh (reparse) all texts for a language.
     *
     * @param int $id Language ID
     *
     * @return array{sentencesDeleted: int, textItemsDeleted: int, sentencesAdded: int, textItemsAdded: int}
     */
    public function execute(int $id): array
    {
        $sentencesDeleted = QueryBuilder::table('sentences')
            ->where('SeLgID', '=', $id)
            ->delete();
        $textItemsDeleted = QueryBuilder::table('word_occurrences')
            ->where('Ti2LgID', '=', $id)
            ->delete();
        Maintenance::adjustAutoIncrement('sentences', 'SeID');

        $rows = QueryBuilder::table('texts')
            ->select(['TxID', 'TxText'])
            ->where('TxLgID', '=', $id)
            ->orderBy('TxID')
            ->getPrepared();
        foreach ($rows as $record) {
            $txtid = (int)$record["TxID"];
            $txttxt = (string)$record["TxText"];
            TextParsing::parseAndSave($txttxt, $id, $txtid);
        }

        $sentencesAdded = QueryBuilder::table('sentences')
            ->where('SeLgID', '=', $id)
            ->count();
        $textItemsAdded = QueryBuilder::table('word_occurrences')
            ->where('Ti2LgID', '=', $id)
            ->count();

        return [
            'sentencesDeleted' => $sentencesDeleted,
            'textItemsDeleted' => $textItemsDeleted,
            'sentencesAdded' => $sentencesAdded,
            'textItemsAdded' => $textItemsAdded
        ];
    }

    /**
     * Refresh (reparse) all texts for a language and return stats.
     *
     * @param int $id Language ID
     *
     * @return array{sentencesDeleted: int, textItemsDeleted: int, sentencesAdded: int, textItemsAdded: int}
     */
    public function refreshTexts(int $id): array
    {
        $sentencesDeleted = QueryBuilder::table('sentences')
            ->where('SeLgID', '=', $id)
            ->delete();
        $textItemsDeleted = QueryBuilder::table('word_occurrences')
            ->where('Ti2LgID', '=', $id)
            ->delete();
        Maintenance::adjustAutoIncrement('sentences', 'SeID');

        $rows = QueryBuilder::table('texts')
            ->select(['TxID', 'TxText'])
            ->where('TxLgID', '=', $id)
            ->orderBy('TxID')
            ->getPrepared();
        foreach ($rows as $record) {
            $txtid = (int)$record["TxID"];
            $txttxt = (string)$record["TxText"];
            TextParsing::parseAndSave($txttxt, $id, $txtid);
        }

        $sentencesAdded = QueryBuilder::table('sentences')
            ->where('SeLgID', '=', $id)
            ->count();
        $textItemsAdded = QueryBuilder::table('word_occurrences')
            ->where('Ti2LgID', '=', $id)
            ->count();

        return [
            'sentencesDeleted' => $sentencesDeleted,
            'textItemsDeleted' => $textItemsDeleted,
            'sentencesAdded' => $sentencesAdded,
            'textItemsAdded' => $textItemsAdded
        ];
    }

    /**
     * Reparse all texts for a language (internal use).
     *
     * @param int $id Language ID
     *
     * @return int Number of reparsed texts
     */
    public function reparseTexts(int $id): int
    {
        QueryBuilder::table('sentences')
            ->where('SeLgID', '=', $id)
            ->delete();
        QueryBuilder::table('word_occurrences')
            ->where('Ti2LgID', '=', $id)
            ->delete();
        Maintenance::adjustAutoIncrement('sentences', 'SeID');
        QueryBuilder::table('words')
            ->where('WoLgID', '=', $id)
            ->updatePrepared(['WoWordCount' => 0]);
        Maintenance::initWordCount();

        $rows = QueryBuilder::table('texts')
            ->select(['TxID', 'TxText'])
            ->where('TxLgID', '=', $id)
            ->orderBy('TxID')
            ->getPrepared();
        $count = 0;
        foreach ($rows as $record) {
            $txtid = (int)$record["TxID"];
            $txttxt = (string)$record["TxText"];
            TextParsing::parseAndSave($txttxt, $id, $txtid);
            $count++;
        }

        return $count;
    }
}
