<?php

declare(strict_types=1);

namespace Lwt\Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every write to a table with no owner column must be reviewed.
 *
 * `UserScopedQuery::USER_SCOPED_TABLES` filters ten tables automatically, so a
 * query against any of them is safe by default and nobody has to think about
 * it. The tables below are the opposite: their rows belong to a user, but only
 * through a parent, and nothing filters them. A `WHERE FlNfID = ?` or
 * `whereIn('Ti2WoID', $ids)` on attacker-supplied IDs therefore reaches other
 * users' rows, and — because the *parent* write is usually scoped and silently
 * affects zero rows — the call still looks like it succeeded.
 *
 * That shape has produced four real bugs:
 *   - MySqlArticleRepository::resetErrorsByFeeds()  (feed_links)
 *   - FeedLoadApiHandler::loadFeed()                (feed_links, both ways)
 *   - WordBulkService::deleteMultiple()             (word_occurrences)
 *   - WordListService::deleteByIdList()             (word_occurrences, fixed earlier)
 *
 * So this test does not try to prove each site is safe — it cannot. It pins
 * the *set* of places that write to these tables. A new write fails the test
 * until somebody adds it to the allowlist with a one-line justification, which
 * forces the question "what stops this touching another user's rows?" to be
 * answered while the code is being written rather than during an audit.
 */
class OwnerlessTableWriteTest extends TestCase
{
    /**
     * Tables holding user data with no owner column of their own.
     *
     * Keep in step with `UserScopedQuery::USER_SCOPED_TABLES`: a table belongs
     * in exactly one of the two lists.
     *
     * @var list<string>
     */
    private const OWNERLESS_TABLES = [
        'feed_links',
        'sentences',
        'word_occurrences',
        'text_tag_map',
        'word_tag_map',
        'local_dictionary_entries',
    ];

    /**
     * Write sites verified during the 2026-08 authorization audit.
     *
     * The value states *what confines the write to the caller's own rows*.
     * "It is only called from X" is not a reason — X can gain a caller.
     *
     * @var array<string, string>
     */
    private const VERIFIED = [
        'src/Modules/Feed/Http/FeedArticleApiHandler.php' =>
            'deleteArticles/createTextsFromEdited gate on getFeedById(), which is user-scoped',
        'src/Modules/Feed/Http/FeedLoadApiHandler.php' =>
            'loadFeed() gates on getFeedById() before any feed_links write',
        'src/Modules/Feed/Infrastructure/MySqlArticleRepository.php' =>
            'every mutation appends feedOwnerScope()',
        'src/Modules/Vocabulary/Application/Services/WordListService.php' =>
            'deleteByIdList() narrows through filterOwnedWordIds()',
        'src/Modules/Vocabulary/Application/Services/WordBulkService.php' =>
            'deleteMultiple() narrows through filterOwnedWordIds()',
        'src/Modules/Dictionary/Application/Services/LocalDictionaryService.php' =>
            'entry writes call assertOwnsDictionary(); linkOccurrencesForLanguage() '
            . 'joins on Ti2LgID = WoLgID and a language row has one owner',
        'src/Modules/Text/Application/UseCases/DeleteText.php' =>
            'cascades from a text resolved through the user-scoped texts table',
        'src/Modules/Text/Application/UseCases/ArchiveText.php' =>
            'cascades from a text resolved through the user-scoped texts table',
        'src/Modules/Vocabulary/Application/Services/WordLinkingService.php' =>
            'linkToTextItems() bails unless ownsLanguage(); linkAllTextItems() '
            . 'joins on Ti2LgID = WoLgID and a language row has one owner',
        'src/Modules/Vocabulary/Application/Services/LemmaBatchService.php' =>
            'item IDs come from fetchUnmatchedTextItems(), which joins texts '
            . 'under scope; linkTextItemsByLemmaSql() scopes both words and texts',
    ];

    /**
     * Write sites that predate this invariant and have NOT been individually
     * verified.
     *
     * They are allowed so the test can be introduced without a flag day, and
     * they are listed separately so nobody mistakes "grandfathered" for
     * "checked". Moving an entry up to VERIFIED requires reading the code and
     * writing down the mechanism; the list should only ever shrink.
     *
     * @var list<string>
     */
    private const PRE_EXISTING = [
        'src/Modules/Book/Application/UseCases/CreateBookFromTexts.php',
        'src/Modules/Book/Application/UseCases/ImportEpub.php',
        'src/Modules/Feed/Infrastructure/TextCreationAdapter.php',
        'src/Modules/Language/Application/UseCases/ReparseLanguageTexts.php',
        'src/Modules/Tags/Application/Services/TermTagService.php',
        'src/Modules/Tags/Application/Services/TextTagService.php',
        'src/Modules/Tags/Infrastructure/MySqlArchivedTextTagAssociation.php',
        'src/Modules/Tags/Infrastructure/MySqlTextTagAssociation.php',
        'src/Modules/Tags/Infrastructure/MySqlWordTagAssociation.php',
        'src/Modules/Text/Application/TextFacade.php',
        'src/Modules/Text/Application/UseCases/UpdateText.php',
        'src/Modules/Vocabulary/Application/Services/CompleteImportService.php',
        'src/Modules/Vocabulary/Application/Services/ExpressionService.php',
        'src/Modules/Vocabulary/Application/Services/ImportUtilities.php',
        'src/Modules/Vocabulary/Application/Services/MultiWordService.php',
        'src/Modules/Vocabulary/Application/Services/WordCrudService.php',
        'src/Modules/Vocabulary/Http/TermCrudApiHandler.php',
        'src/Shared/Infrastructure/Database/Migrations.php',
        'src/Shared/Infrastructure/Database/Restore.php',
        'src/Shared/Infrastructure/Database/TokenPersistence.php',
    ];

    /**
     * Every write site the invariant currently tolerates.
     *
     * @return list<string>
     */
    private static function allowed(): array
    {
        return array_merge(array_keys(self::VERIFIED), self::PRE_EXISTING);
    }

    /**
     * Every source file containing a write against an ownerless table.
     *
     * @return list<string> Repo-relative paths
     */
    private function findWriteSites(): array
    {
        $root = dirname(__DIR__, 3);
        $pattern = implode('|', self::OWNERLESS_TABLES);

        // QueryBuilder mutations and raw SQL, on one line or split across two.
        $regex = '/('
            . "table\\('($pattern)'\\)"
            . '|(INSERT\s+(IGNORE\s+)?INTO|UPDATE|DELETE\s+FROM)\s+(' . $pattern . ')\b'
            . ')/i';

        $sites = [];
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root . '/src')
        );

        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $contents = (string) file_get_contents($file->getPathname());
            if (!preg_match($regex, $contents)) {
                continue;
            }
            // A file that only ever reads is not a write site.
            if (!$this->containsMutation($contents, $pattern)) {
                continue;
            }
            // getPathname() uses the platform separator, so on Windows every
            // path would read as `src\Modules\…` and match nothing in the
            // allowlist below — which is written, and compared, with slashes.
            $path = str_replace('\\', '/', $file->getPathname());
            $sites[] = str_replace(str_replace('\\', '/', $root) . '/', '', $path);
        }

        sort($sites);
        return $sites;
    }

    /**
     * Does the file mutate one of the tables, rather than only reading it?
     *
     * @param string $contents File contents
     * @param string $pattern  Alternation of table names
     */
    private function containsMutation(string $contents, string $pattern): bool
    {
        // Raw SQL naming the table directly:
        //   DELETE FROM feed_links WHERE ...
        if (preg_match('/(INSERT\s+(IGNORE\s+)?INTO|UPDATE|DELETE\s+FROM)\s+(' . $pattern . ')\b/i', $contents)) {
            return true;
        }

        // Raw SQL with the name interpolated, which is the prevailing idiom:
        //   "INSERT IGNORE INTO " . Globals::table('text_tag_map') . " (...)"
        $interpolated = '/(INSERT\s+(IGNORE\s+)?INTO|UPDATE|DELETE\s+FROM)'
            . "[^;]{0,80}?table\\('($pattern)'\\)/is";
        if (preg_match($interpolated, $contents)) {
            return true;
        }

        // QueryBuilder chains: table('x') ... ->delete()/insert()/update(),
        // with or without the Prepared suffix — both mutate.
        if (preg_match_all("/table\\('($pattern)'\\)(.{0,400})/is", $contents, $matches)) {
            foreach ($matches[2] as $tail) {
                if (preg_match('/->(delete|insert|update)(Prepared)?\s*\(/i', $tail)) {
                    return true;
                }
            }
        }

        return false;
    }

    #[Test]
    public function everyWriteToAnOwnerlessTableIsReviewed(): void
    {
        $unreviewed = array_values(array_diff($this->findWriteSites(), self::allowed()));

        $this->assertSame(
            [],
            $unreviewed,
            "These files write to a table with no owner column, and are not in "
            . "OwnerlessTableWriteTest's allowlist:\n  - "
            . implode("\n  - ", $unreviewed)
            . "\n\nSuch a write is NOT filtered by user: UserScopedQuery does not "
            . "cover these tables, so a WHERE on the child key alone reaches other "
            . "users' rows. Confine the write — resolve the parent through its "
            . "user-scoped table first (getFeedById(), assertOwnsDictionary(), "
            . "filterOwnedWordIds(), or a join to a scoped table) — then add the "
            . "file to VERIFIED with the reason. Do not add it without one."
        );
    }

    #[Test]
    public function theAllowlistHasNoStaleEntries(): void
    {
        // A stale entry is a claim nobody is checking any more, and it makes
        // the list read as more reviewed than it is.
        $stale = array_values(array_diff(self::allowed(), $this->findWriteSites()));

        $this->assertSame(
            [],
            $stale,
            "These files are allowlisted but no longer write to an ownerless "
            . "table; drop them from the allowlist:\n  - " . implode("\n  - ", $stale)
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function ownerlessTables(): array
    {
        $cases = [];
        foreach (self::OWNERLESS_TABLES as $table) {
            $cases[$table] = [$table];
        }
        return $cases;
    }

    #[Test]
    #[DataProvider('ownerlessTables')]
    public function anOwnerlessTableIsNotAlsoClaimedAsUserScoped(string $table): void
    {
        // If a table gains a real owner column it should move to
        // USER_SCOPED_TABLES and leave this list, not sit in both.
        $source = (string) file_get_contents(
            dirname(__DIR__, 3) . '/src/Shared/Infrastructure/Database/UserScopedQuery.php'
        );

        $this->assertDoesNotMatchRegularExpression(
            "/'" . preg_quote($table, '/') . "'\s*=>/",
            $source,
            "'$table' is listed as ownerless here but also appears in "
            . 'UserScopedQuery::USER_SCOPED_TABLES. It belongs in exactly one.'
        );
    }
}
