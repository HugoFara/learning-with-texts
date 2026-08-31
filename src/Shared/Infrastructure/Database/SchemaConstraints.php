<?php

/**
 * \file
 * \brief The foreign keys the current schema is supposed to have.
 *
 * PHP version 8.2
 *
 * @category Database
 * @package  Lwt
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.4.1
 */

declare(strict_types=1);

namespace Lwt\Shared\Infrastructure\Database;

/**
 * The declared foreign keys of the current schema.
 *
 * Foreign keys are created by the migration that introduces the table, and a
 * migration runs once. When an upgrade dropped the constraints to make way for
 * schema changes and did not put them all back — every release before 3.4.0 did
 * exactly that (#272) — the ones belonging to already-applied migrations were
 * gone for good. Half of them were missing on a 3.3.0 database (#273).
 *
 * Recovering that needs a statement of what *should* exist, which is what this
 * list is. Migrations::reconcileForeignKeys() adds whatever is absent.
 *
 * Only the current schema is listed. Constraints on tables that later
 * migrations renamed away (`textitems2`, `newsfeeds`, `texttags`, …) are the
 * business of those migrations, not of this list.
 *
 * SchemaConstraintsTest reads the same definitions back out of
 * db/schema/baseline.sql and db/migrations/*.sql and fails if the two disagree,
 * so a new table with a foreign key cannot quietly skip this list.
 *
 * @since 3.4.1
 */
final class SchemaConstraints
{
    /**
     * Every foreign key the current schema declares.
     *
     * @var array<array{
     *     name: string, table: string, columns: array<string>,
     *     refTable: string, refColumns: array<string>,
     *     onUpdate: string, onDelete: string
     * }>
     */
    public const FOREIGN_KEYS = [
        ['name' => 'fk_books_language', 'table' => 'books', 'columns' => ['BkLgID'],
         'refTable' => 'languages', 'refColumns' => ['LgID'],
         'onUpdate' => 'RESTRICT', 'onDelete' => 'RESTRICT'],
        ['name' => 'fk_books_user', 'table' => 'books', 'columns' => ['BkUsID'],
         'refTable' => 'users', 'refColumns' => ['UsID'],
         'onUpdate' => 'RESTRICT', 'onDelete' => 'CASCADE'],
        ['name' => 'fk_feed_links_news_feed', 'table' => 'feed_links', 'columns' => ['FlNfID'],
         'refTable' => 'news_feeds', 'refColumns' => ['NfID'],
         'onUpdate' => 'RESTRICT', 'onDelete' => 'CASCADE'],
        ['name' => 'fk_languages_user', 'table' => 'languages', 'columns' => ['LgUsID'],
         'refTable' => 'users', 'refColumns' => ['UsID'],
         'onUpdate' => 'RESTRICT', 'onDelete' => 'CASCADE'],
        ['name' => 'fk_local_dict_language', 'table' => 'local_dictionaries', 'columns' => ['LdLgID'],
         'refTable' => 'languages', 'refColumns' => ['LgID'],
         'onUpdate' => 'RESTRICT', 'onDelete' => 'CASCADE'],
        ['name' => 'fk_local_dict_user', 'table' => 'local_dictionaries', 'columns' => ['LdUsID'],
         'refTable' => 'users', 'refColumns' => ['UsID'],
         'onUpdate' => 'RESTRICT', 'onDelete' => 'CASCADE'],
        ['name' => 'fk_entry_dictionary', 'table' => 'local_dictionary_entries', 'columns' => ['LeLdID'],
         'refTable' => 'local_dictionaries', 'refColumns' => ['LdID'],
         'onUpdate' => 'RESTRICT', 'onDelete' => 'CASCADE'],
        ['name' => 'fk_news_feeds_language', 'table' => 'news_feeds', 'columns' => ['NfLgID'],
         'refTable' => 'languages', 'refColumns' => ['LgID'],
         'onUpdate' => 'RESTRICT', 'onDelete' => 'CASCADE'],
        ['name' => 'fk_news_feeds_user', 'table' => 'news_feeds', 'columns' => ['NfUsID'],
         'refTable' => 'users', 'refColumns' => ['UsID'],
         'onUpdate' => 'RESTRICT', 'onDelete' => 'CASCADE'],
        ['name' => 'fk_review_log_word', 'table' => 'review_log', 'columns' => ['RlWoID'],
         'refTable' => 'words', 'refColumns' => ['WoID'],
         'onUpdate' => 'RESTRICT', 'onDelete' => 'CASCADE'],
        ['name' => 'fk_sentences_language', 'table' => 'sentences', 'columns' => ['SeLgID'],
         'refTable' => 'languages', 'refColumns' => ['LgID'],
         'onUpdate' => 'RESTRICT', 'onDelete' => 'CASCADE'],
        ['name' => 'fk_sentences_text', 'table' => 'sentences', 'columns' => ['SeTxID'],
         'refTable' => 'texts', 'refColumns' => ['TxID'],
         'onUpdate' => 'RESTRICT', 'onDelete' => 'CASCADE'],
        ['name' => 'fk_tags_user', 'table' => 'tags', 'columns' => ['TgUsID'],
         'refTable' => 'users', 'refColumns' => ['UsID'],
         'onUpdate' => 'RESTRICT', 'onDelete' => 'CASCADE'],
        ['name' => 'fk_term_schedule_word', 'table' => 'term_schedule', 'columns' => ['TsWoID'],
         'refTable' => 'words', 'refColumns' => ['WoID'],
         'onUpdate' => 'RESTRICT', 'onDelete' => 'CASCADE'],
        ['name' => 'fk_text_tag_map_tag', 'table' => 'text_tag_map', 'columns' => ['TtT2ID'],
         'refTable' => 'text_tags', 'refColumns' => ['T2ID'],
         'onUpdate' => 'RESTRICT', 'onDelete' => 'CASCADE'],
        ['name' => 'fk_text_tag_map_text', 'table' => 'text_tag_map', 'columns' => ['TtTxID'],
         'refTable' => 'texts', 'refColumns' => ['TxID'],
         'onUpdate' => 'RESTRICT', 'onDelete' => 'CASCADE'],
        ['name' => 'fk_text_tags_user', 'table' => 'text_tags', 'columns' => ['T2UsID'],
         'refTable' => 'users', 'refColumns' => ['UsID'],
         'onUpdate' => 'RESTRICT', 'onDelete' => 'CASCADE'],
        ['name' => 'fk_texts_book', 'table' => 'texts', 'columns' => ['TxBkID'],
         'refTable' => 'books', 'refColumns' => ['BkID'],
         'onUpdate' => 'RESTRICT', 'onDelete' => 'CASCADE'],
        ['name' => 'fk_texts_language', 'table' => 'texts', 'columns' => ['TxLgID'],
         'refTable' => 'languages', 'refColumns' => ['LgID'],
         'onUpdate' => 'RESTRICT', 'onDelete' => 'CASCADE'],
        ['name' => 'fk_texts_user', 'table' => 'texts', 'columns' => ['TxUsID'],
         'refTable' => 'users', 'refColumns' => ['UsID'],
         'onUpdate' => 'RESTRICT', 'onDelete' => 'CASCADE'],
        ['name' => 'fk_whisper_jobs_user', 'table' => 'whisper_jobs', 'columns' => ['WjUsID'],
         'refTable' => 'users', 'refColumns' => ['UsID'],
         'onUpdate' => 'RESTRICT', 'onDelete' => 'CASCADE'],
        ['name' => 'fk_word_occurrences_sentence', 'table' => 'word_occurrences', 'columns' => ['Ti2SeID'],
         'refTable' => 'sentences', 'refColumns' => ['SeID'],
         'onUpdate' => 'RESTRICT', 'onDelete' => 'CASCADE'],
        ['name' => 'fk_word_occurrences_text', 'table' => 'word_occurrences', 'columns' => ['Ti2TxID'],
         'refTable' => 'texts', 'refColumns' => ['TxID'],
         'onUpdate' => 'RESTRICT', 'onDelete' => 'CASCADE'],
        ['name' => 'fk_word_occurrences_word', 'table' => 'word_occurrences', 'columns' => ['Ti2WoID'],
         'refTable' => 'words', 'refColumns' => ['WoID'],
         'onUpdate' => 'RESTRICT', 'onDelete' => 'SET NULL'],
        ['name' => 'fk_word_tag_map_tag', 'table' => 'word_tag_map', 'columns' => ['WtTgID'],
         'refTable' => 'tags', 'refColumns' => ['TgID'],
         'onUpdate' => 'RESTRICT', 'onDelete' => 'CASCADE'],
        ['name' => 'fk_word_tag_map_word', 'table' => 'word_tag_map', 'columns' => ['WtWoID'],
         'refTable' => 'words', 'refColumns' => ['WoID'],
         'onUpdate' => 'RESTRICT', 'onDelete' => 'CASCADE'],
        ['name' => 'fk_words_language', 'table' => 'words', 'columns' => ['WoLgID'],
         'refTable' => 'languages', 'refColumns' => ['LgID'],
         'onUpdate' => 'RESTRICT', 'onDelete' => 'CASCADE'],
        ['name' => 'fk_words_user', 'table' => 'words', 'columns' => ['WoUsID'],
         'refTable' => 'users', 'refColumns' => ['UsID'],
         'onUpdate' => 'RESTRICT', 'onDelete' => 'CASCADE'],
    ];

    /**
     * Tables that later migrations renamed away.
     *
     * Their constraints are declared in the schema files but belong to the old
     * shape, so they are deliberately absent from FOREIGN_KEYS.
     *
     * @var array<string>
     */
    public const SUPERSEDED_TABLES = [
        'archivedtexts',
        'archived_texts',
        'archtexttags',
        'archived_text_tag_map',
        'feedlinks',
        'newsfeeds',
        'tags2',
        'texttags',
        'textitems2',
        'wordtags',
    ];
}
