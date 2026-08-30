<?php

/**
 * Text Tag Service
 *
 * Extracted from TagsFacade — handles text and archived-text tag
 * associations, HTML rendering, batch operations, and select options.
 *
 * PHP version 8.2
 *
 * @category Lwt
 * @package  Lwt\Modules\Tags\Application\Services
 * @author   HugoFara <git@hugofara.net>
 * @license  Unlicense <http://unlicense.org/>
 * @link     https://hugofara.github.io/lwt/developer/api
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Lwt\Modules\Tags\Application\Services;

use Lwt\Shared\Infrastructure\Http\InputValidator;
use Lwt\Shared\Infrastructure\Database\Connection;
use Lwt\Shared\Infrastructure\Database\QueryBuilder;
use Lwt\Shared\Infrastructure\Database\UserScopedQuery;
use Lwt\Shared\UI\Helpers\FormHelper;
use Lwt\Modules\Tags\Domain\TagAssociationInterface;
use Lwt\Modules\Tags\Domain\TagRepositoryInterface;
use Lwt\Modules\Tags\Infrastructure\MySqlTextTagRepository;
use Lwt\Modules\Tags\Infrastructure\MySqlTextTagAssociation;
use Lwt\Modules\Tags\Infrastructure\MySqlArchivedTextTagAssociation;

/**
 * Service for text and archived-text tag operations.
 *
 * Manages text-tag associations, HTML rendering of tag lists,
 * batch add/remove operations, and select option generation.
 *
 * @since 3.0.0
 */
class TextTagService
{
    private static ?TagRepositoryInterface $repository = null;
    private static ?TagAssociationInterface $textAssociation = null;
    private static ?TagAssociationInterface $archivedTextAssociation = null;

    /**
     * Get the text tag repository.
     *
     * @return TagRepositoryInterface
     */
    public static function getRepository(): TagRepositoryInterface
    {
        if (self::$repository === null) {
            self::$repository = new MySqlTextTagRepository();
        }
        return self::$repository;
    }

    /**
     * Get the text tag association handler.
     *
     * @return TagAssociationInterface
     */
    public static function getTextAssociation(): TagAssociationInterface
    {
        if (self::$textAssociation === null) {
            self::$textAssociation = new MySqlTextTagAssociation(self::getRepository());
        }
        return self::$textAssociation;
    }

    /**
     * Get the archived-text tag association handler.
     *
     * @return TagAssociationInterface
     */
    public static function getArchivedTextAssociation(): TagAssociationInterface
    {
        if (self::$archivedTextAssociation === null) {
            self::$archivedTextAssociation = new MySqlArchivedTextTagAssociation(self::getRepository());
        }
        return self::$archivedTextAssociation;
    }

    // =====================
    // ASSOCIATION METHODS
    // =====================

    /**
     * Save tags for a text.
     *
     * @param int      $textId   Text ID
     * @param string[] $tagNames Tag names
     *
     * @return void
     */
    public static function saveTextTags(int $textId, array $tagNames): void
    {
        self::getTextAssociation()->setTagsByName($textId, $tagNames);
    }

    /**
     * Save tags for an archived text.
     *
     * @param int      $textId   Archived text ID
     * @param string[] $tagNames Tag names
     *
     * @return void
     */
    public static function saveArchivedTextTags(int $textId, array $tagNames): void
    {
        self::getArchivedTextAssociation()->setTagsByName($textId, $tagNames);
    }

    /**
     * Save tags for a text from form input.
     *
     * @param int        $textId   Text ID
     * @param array|null $textTags Optional tags array. If null, reads from request.
     *
     * @return void
     */
    public static function saveTextTagsFromForm(int $textId, ?array $textTags = null): void
    {
        if ($textTags === null) {
            $textTags = InputValidator::getArray('TextTags');
        }

        if (
            empty($textTags)
            || !isset($textTags['TagList'])
            || !is_array($textTags['TagList'])
        ) {
            self::getTextAssociation()->setTagsByName($textId, []);
            return;
        }

        /** @var array<int|string, scalar> $tagList */
        $tagList = $textTags['TagList'];
        $tagNames = self::flattenTagList($tagList);
        self::saveTextTags($textId, $tagNames);
    }

    /**
     * Save tags for an archived text from form input.
     *
     * @param int $textId Archived text ID
     *
     * @return void
     */
    public static function saveArchivedTextTagsFromForm(int $textId): void
    {
        $textTags = InputValidator::getArray('TextTags');

        if (
            empty($textTags)
            || !isset($textTags['TagList'])
            || !is_array($textTags['TagList'])
        ) {
            self::getArchivedTextAssociation()->setTagsByName($textId, []);
            return;
        }

        /** @var array<int|string, scalar> $tagList */
        $tagList = $textTags['TagList'];
        $tagNames = self::flattenTagList($tagList);
        self::saveArchivedTextTags($textId, $tagNames);
    }

    /**
     * Flatten Tagify-style form input into individual tag names.
     *
     * Tagify serializes multiple tags into a single comma-joined string
     * posted under one TextTags[TagList][] field. Split on commas so each
     * tag is created/linked individually; otherwise two tags whose
     * combined length exceeds MAX_TEXT_LENGTH throw on validation.
     *
     * @param array<int|string, scalar> $tagList
     *
     * @return list<string>
     */
    private static function flattenTagList(array $tagList): array
    {
        $tagNames = [];
        foreach ($tagList as $entry) {
            foreach (explode(',', (string) $entry) as $part) {
                $part = trim($part);
                if ($part !== '') {
                    $tagNames[] = $part;
                }
            }
        }
        return $tagNames;
    }

    // =====================
    // HTML RENDERING
    // =====================

    /**
     * Get HTML list of tags for a text.
     *
     * @param int $textId Text ID
     *
     * @return string HTML UL element
     */
    public static function getTextTagsHtml(int $textId): string
    {
        $html = '<ul id="texttags" class="respinput">';

        if ($textId > 0) {
            $tagNames = self::getTextAssociation()->getTagTextsForItem($textId);
            foreach ($tagNames as $name) {
                $html .= '<li>' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</li>';
            }
        }

        return $html . '</ul>';
    }

    /**
     * Get HTML list of tags for an archived text.
     *
     * @param int $textId Archived text ID
     *
     * @return string HTML UL element
     */
    public static function getArchivedTextTagsHtml(int $textId): string
    {
        $html = '<ul id="text_tag_map" class="respinput">';

        if ($textId > 0) {
            $tagNames = self::getArchivedTextAssociation()->getTagTextsForItem($textId);
            foreach ($tagNames as $name) {
                $html .= '<li>' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</li>';
            }
        }

        return $html . '</ul>';
    }

    // =====================
    // BATCH OPERATIONS
    // =====================

    /**
     * Add a tag to multiple texts.
     *
     * @param string $tagText Tag text to add
     * @param int[]  $ids     Array of text IDs
     *
     * @return array{count: int, error: ?string} Result with count and optional error
     */
    public static function addTagToTexts(string $tagText, array $ids): array
    {
        if (empty($ids)) {
            return ['count' => 0, 'error' => null];
        }

        $tagId = self::getOrCreateTextTag($tagText);
        if ($tagId === null) {
            return ['count' => 0, 'error' => 'Failed to create tag'];
        }

        $bindings = [$tagId];
        $inClause = Connection::buildPreparedInClause($ids, $bindings);
        $sql = 'SELECT TxID FROM texts
            LEFT JOIN text_tag_map ON TxID = TtTxID AND TtT2ID = ?
            WHERE TtT2ID IS NULL AND TxID IN ' . $inClause
            . UserScopedQuery::forTablePrepared('texts', $bindings, 'texts');
        $rows = Connection::preparedFetchAll($sql, $bindings);

        $count = 0;
        foreach ($rows as $record) {
            Connection::preparedExecute(
                'INSERT IGNORE INTO text_tag_map (TtTxID, TtT2ID) VALUES(?, ?)',
                [(int)$record['TxID'], $tagId]
            );
            $count++;
        }

        return ['count' => $count, 'error' => null];
    }

    /**
     * Remove a tag from multiple texts.
     *
     * @param string $tagText Tag text to remove
     * @param int[]  $ids     Array of text IDs
     *
     * @return array{count: int, error: ?string} Result with count and optional error
     */
    public static function removeTagFromTexts(string $tagText, array $ids): array
    {
        if (empty($ids)) {
            return ['count' => 0, 'error' => null];
        }

        $tagBindings = [$tagText];
        $tagScope = UserScopedQuery::forTablePrepared('text_tags', $tagBindings);
        /** @var int|string|null $tagIdRaw */
        $tagIdRaw = Connection::preparedFetchValue(
            'SELECT T2ID FROM text_tags WHERE T2Text = ?' . $tagScope,
            $tagBindings,
            'T2ID'
        );

        if ($tagIdRaw === null) {
            return ['count' => 0, 'error' => "Tag {$tagText} not found"];
        }
        $tagId = (int) $tagIdRaw;

        $bindings = [];
        $inClause = Connection::buildPreparedInClause($ids, $bindings);
        $sql = 'SELECT TxID FROM texts WHERE TxID IN ' . $inClause
            . UserScopedQuery::forTablePrepared('texts', $bindings, 'texts');
        $rows = Connection::preparedFetchAll($sql, $bindings);

        $count = 0;
        foreach ($rows as $record) {
            $count++;
            QueryBuilder::table('text_tag_map')
                ->where('TtTxID', '=', (int)$record['TxID'])
                ->where('TtT2ID', '=', $tagId)
                ->delete();
        }

        return ['count' => $count, 'error' => null];
    }

    /**
     * Add a tag to multiple archived texts.
     *
     * @param string $tagText Tag text to add
     * @param int[]  $ids     Array of archived text IDs
     *
     * @return array{count: int, error: ?string} Result with count and optional error
     */
    public static function addTagToArchivedTexts(string $tagText, array $ids): array
    {
        if (empty($ids)) {
            return ['count' => 0, 'error' => null];
        }

        $tagId = self::getOrCreateTextTag($tagText);
        if ($tagId === null) {
            return ['count' => 0, 'error' => 'Failed to create tag'];
        }

        $bindings = [$tagId];
        $inClause = Connection::buildPreparedInClause($ids, $bindings);
        $sql = 'SELECT TxID FROM texts
            LEFT JOIN text_tag_map ON TxID = TtTxID AND TtT2ID = ?
            WHERE TtT2ID IS NULL AND TxArchivedAt IS NOT NULL AND TxID IN ' . $inClause
            . UserScopedQuery::forTablePrepared('texts', $bindings, 'texts');
        $rows = Connection::preparedFetchAll($sql, $bindings);

        $count = 0;
        foreach ($rows as $record) {
            Connection::preparedExecute(
                'INSERT IGNORE INTO text_tag_map (TtTxID, TtT2ID) VALUES(?, ?)',
                [(int)$record['TxID'], $tagId]
            );
            $count++;
        }

        return ['count' => $count, 'error' => null];
    }

    /**
     * Remove a tag from multiple archived texts.
     *
     * @param string $tagText Tag text to remove
     * @param int[]  $ids     Array of archived text IDs
     *
     * @return array{count: int, error: ?string} Result with count and optional error
     */
    public static function removeTagFromArchivedTexts(
        string $tagText,
        array $ids
    ): array {
        if (empty($ids)) {
            return ['count' => 0, 'error' => null];
        }

        $tagBindings = [$tagText];
        $tagScope = UserScopedQuery::forTablePrepared('text_tags', $tagBindings);
        /** @var int|string|null $tagIdRaw */
        $tagIdRaw = Connection::preparedFetchValue(
            'SELECT T2ID FROM text_tags WHERE T2Text = ?' . $tagScope,
            $tagBindings,
            'T2ID'
        );

        if ($tagIdRaw === null) {
            return ['count' => 0, 'error' => "Tag {$tagText} not found"];
        }
        $tagId = (int) $tagIdRaw;

        $bindings = [];
        $inClause = Connection::buildPreparedInClause($ids, $bindings);
        $sql = 'SELECT TxID FROM texts WHERE TxArchivedAt IS NOT NULL AND TxID IN ' . $inClause
            . UserScopedQuery::forTablePrepared('texts', $bindings, 'texts');
        $rows = Connection::preparedFetchAll($sql, $bindings);

        $count = 0;
        foreach ($rows as $record) {
            $count++;
            QueryBuilder::table('text_tag_map')
                ->where('TtTxID', '=', (int)$record['TxID'])
                ->where('TtT2ID', '=', $tagId)
                ->delete();
        }

        return ['count' => $count, 'error' => null];
    }

    // =====================
    // SELECT OPTIONS
    // =====================

    /**
     * Get text tag select options HTML for filtering.
     *
     * @param int|string|null $selected Currently selected value
     * @param int|string      $langId   Language ID filter ('' for all)
     *
     * @return string HTML options
     */
    public static function getTextTagSelectOptions(
        int|string|null $selected,
        int|string $langId
    ): string {
        $selected = $selected ?? '';

        $html = '<option value=""' . FormHelper::getSelected($selected, '') . '>';
        $html .= '[Filter off]</option>';

        if ($langId === '') {
            $rows = Connection::preparedFetchAll(
                "SELECT T2ID, T2Text
                FROM texts, text_tags, text_tag_map
                WHERE T2ID = TtT2ID AND TtTxID = TxID
                GROUP BY T2ID
                ORDER BY UPPER(T2Text)",
                []
            );
        } else {
            $rows = Connection::preparedFetchAll(
                "SELECT T2ID, T2Text
                FROM texts, text_tags, text_tag_map
                WHERE T2ID = TtT2ID AND TtTxID = TxID AND TxLgID = ?
                GROUP BY T2ID
                ORDER BY UPPER(T2Text)",
                [$langId]
            );
        }

        $count = 0;
        foreach ($rows as $record) {
            $count++;
            $tagId = (int) $record['T2ID'];
            $tagText = (string) ($record['T2Text'] ?? '');
            $html .= '<option value="' . $tagId . '"' .
                FormHelper::getSelected($selected, $tagId) . '>' .
                htmlspecialchars($tagText, ENT_QUOTES, 'UTF-8') . '</option>';
        }

        if ($count > 0) {
            $html .= '<option disabled="disabled">--------</option>';
            $html .= '<option value="-1"' . FormHelper::getSelected($selected, -1) . '>UNTAGGED</option>';
        }

        return $html;
    }

    /**
     * Get text tag select options with text IDs for word list filtering.
     *
     * @param int|string      $langId   Language ID filter
     * @param int|string|null $selected Currently selected value
     *
     * @return string HTML options
     */
    public static function getTextTagSelectOptionsWithTextIds(
        int|string $langId,
        int|string|null $selected
    ): string {
        $selected = $selected ?? '';
        $untaggedOption = '';

        $html = '<option value="&amp;texttag"' . FormHelper::getSelected($selected, '') . '>';
        $html .= '[Filter off]</option>';

        // text_tag_map / text_tags / texts are joined; only `texts` and
        // `text_tags` carry an UsID column, so scope both. (text_tag_map
        // rows inherit scope via TxID and T2ID.)
        if ($langId) {
            $bindings = [$langId];
            $where = 'WHERE TxLgID = ?'
                . UserScopedQuery::forTablePrepared('texts', $bindings)
                . UserScopedQuery::forTablePrepared('text_tags', $bindings);
        } else {
            $bindings = [];
            $userScope = UserScopedQuery::forTablePrepared('texts', $bindings)
                . UserScopedQuery::forTablePrepared('text_tags', $bindings);
            $where = $userScope === '' ? '' : 'WHERE 1=1' . $userScope;
        }
        $rows = Connection::preparedFetchAll(
            'SELECT IFNULL(T2Text, 1) AS TagName, TtT2ID AS TagID,
            GROUP_CONCAT(TxID ORDER BY TxID) AS TextID
            FROM texts
            LEFT JOIN text_tag_map ON TxID = TtTxID
            LEFT JOIN text_tags ON TtT2ID = T2ID
            ' . $where . '
            GROUP BY UPPER(TagName)',
            $bindings
        );

        foreach ($rows as $record) {
            $tagName = (string) $record['TagName'];
            $textId = (string) $record['TextID'];
            $tagId = (int) $record['TagID'];
            if ($tagName === '1') {
                $untaggedOption = '<option disabled="disabled">--------</option>' .
                    '<option value="' . $textId . '&amp;texttag=-1"' .
                    FormHelper::getSelected($selected, "-1") . '>UNTAGGED</option>';
            } else {
                $html .= '<option value="' . $textId . '&amp;texttag=' .
                    $tagId . '"' . FormHelper::getSelected($selected, $tagId) .
                    '>' . $tagName . '</option>';
            }
        }

        return $html . $untaggedOption;
    }

    /**
     * Get archived text tag select options HTML for filtering.
     *
     * @param int|string|null $selected Currently selected value
     * @param int|string      $langId   Language ID filter ('' for all)
     *
     * @return string HTML options
     */
    public static function getArchivedTextTagSelectOptions(
        int|string|null $selected,
        int|string $langId
    ): string {
        $selected = $selected ?? '';

        $html = '<option value=""' . FormHelper::getSelected($selected, '') . '>';
        $html .= '[Filter off]</option>';

        if ($langId === '') {
            $bindings = [];
            $sql = "SELECT T2ID, T2Text
                FROM texts, text_tags, text_tag_map
                WHERE T2ID = TtT2ID AND TtTxID = TxID AND TxArchivedAt IS NOT NULL"
                . UserScopedQuery::forTablePrepared('texts', $bindings)
                . UserScopedQuery::forTablePrepared('text_tags', $bindings)
                . " GROUP BY T2ID
                ORDER BY UPPER(T2Text)";
        } else {
            $bindings = [$langId];
            $sql = "SELECT T2ID, T2Text
                FROM texts, text_tags, text_tag_map
                WHERE T2ID = TtT2ID AND TtTxID = TxID AND TxArchivedAt IS NOT NULL AND TxLgID = ?"
                . UserScopedQuery::forTablePrepared('texts', $bindings)
                . UserScopedQuery::forTablePrepared('text_tags', $bindings)
                . " GROUP BY T2ID
                ORDER BY UPPER(T2Text)";
        }
        $rows = Connection::preparedFetchAll($sql, $bindings);

        $count = 0;
        foreach ($rows as $record) {
            $count++;
            $tagId = (int) $record['T2ID'];
            $tagText = (string) ($record['T2Text'] ?? '');
            $html .= '<option value="' . $tagId . '"' .
                FormHelper::getSelected($selected, $tagId) . '>' .
                htmlspecialchars($tagText, ENT_QUOTES, 'UTF-8') . '</option>';
        }

        if ($count > 0) {
            $html .= '<option disabled="disabled">--------</option>';
            $html .= '<option value="-1"' . FormHelper::getSelected($selected, -1) . '>UNTAGGED</option>';
        }

        return $html;
    }

    // =====================
    // HELPERS
    // =====================

    /**
     * Get or create a text tag, returning its ID.
     *
     * @param string $tagText Tag text
     *
     * @return int|null Tag ID or null on failure
     */
    public static function getOrCreateTextTag(string $tagText): ?int
    {
        // Look up by user scope so we never reuse another user's T2ID — that
        // would attach foreign tags to the caller's texts and pollute the
        // foreign user's tag-to-text membership.
        $bindings = [$tagText];
        $userScope = UserScopedQuery::forTablePrepared('text_tags', $bindings);
        /** @var int|string|null $tagIdRaw */
        $tagIdRaw = Connection::preparedFetchValue(
            'SELECT T2ID FROM text_tags WHERE T2Text = ?' . $userScope,
            $bindings,
            'T2ID'
        );

        if ($tagIdRaw === null) {
            QueryBuilder::table('text_tags')->insertPrepared(['T2Text' => $tagText]);
            $bindings = [$tagText];
            $userScope = UserScopedQuery::forTablePrepared('text_tags', $bindings);
            /** @var int|string|null $tagIdRaw */
            $tagIdRaw = Connection::preparedFetchValue(
                'SELECT T2ID FROM text_tags WHERE T2Text = ?' . $userScope,
                $bindings,
                'T2ID'
            );
        }

        return $tagIdRaw !== null ? (int) $tagIdRaw : null;
    }
}
