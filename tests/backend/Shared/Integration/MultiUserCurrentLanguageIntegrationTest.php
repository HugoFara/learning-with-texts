<?php

declare(strict_types=1);

namespace Lwt\Tests\Shared\Integration;

use Lwt\Modules\Language\Http\LanguageApiHandler;
use Lwt\Shared\I18n\Translator;
use Lwt\Shared\I18n\UserLocale;
use Lwt\Shared\Infrastructure\Bootstrap\EnvLoader;
use Lwt\Shared\Infrastructure\Container\Container;
use Lwt\Shared\Infrastructure\Database\Configuration;
use Lwt\Shared\Infrastructure\Database\Connection;
use Lwt\Shared\Infrastructure\Database\Settings;
use Lwt\Shared\Infrastructure\Globals;
use Lwt\Shared\Infrastructure\Http\InputValidator;
use Lwt\Shared\Infrastructure\Language\CurrentLanguage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The current language and interface language of a signed-in user.
 *
 * Production runs in multi-user mode and the suite mostly does not. There,
 * values saved to the global row never reach the reader, and the translator
 * boots before the user is known, so both of these silently did nothing.
 */
#[Group('integration')]
class MultiUserCurrentLanguageIntegrationTest extends TestCase
{
    private const USERNAME = 'multi_user_current_language_test';
    private const OTHER_USERNAME = 'multi_user_current_language_other';

    private static bool $dbConnected = false;
    private static int $userId = 0;
    private static int $otherUserId = 0;
    private static int $languageId = 0;
    private static int $otherLanguageId = 0;
    private static int $foreignLanguageId = 0;

    public static function setUpBeforeClass(): void
    {
        $config = EnvLoader::getDatabaseConfig();
        if (!Globals::getDbConnection()) {
            try {
                Globals::setDbConnection(Configuration::connect(
                    $config['server'],
                    $config['userid'],
                    $config['passwd'],
                    'test_' . $config['dbname'],
                    $config['socket'] ?? ''
                ));
            } catch (\Throwable) {
                return;
            }
        }
        self::$dbConnected = true;

        self::$userId = self::insertUser(self::USERNAME);
        self::$otherUserId = self::insertUser(self::OTHER_USERNAME);
        self::$languageId = self::insertLanguage('CurrentFirst', self::$userId);
        self::$otherLanguageId = self::insertLanguage('CurrentSecond', self::$userId);
        self::$foreignLanguageId = self::insertLanguage('CurrentForeign', self::$otherUserId);
    }

    public static function tearDownAfterClass(): void
    {
        foreach ([self::$userId, self::$otherUserId] as $userId) {
            if ($userId !== 0) {
                Connection::preparedExecute('DELETE FROM settings WHERE StUsID = ?', [$userId]);
                Connection::preparedExecute('DELETE FROM users WHERE UsID = ?', [$userId]);
            }
        }
    }

    private static function insertUser(string $name): int
    {
        Connection::preparedExecute('DELETE FROM users WHERE UsUsername = ?', [$name]);
        return (int) Connection::preparedInsert(
            "INSERT INTO users (UsUsername, UsPasswordHash) VALUES (?, 'x')",
            [$name]
        );
    }

    private static function insertLanguage(string $name, int $userId): int
    {
        return (int) Connection::preparedInsert(
            "INSERT INTO languages (
                LgName, LgDict1URI, LgDict2URI, LgGoogleTranslateURI,
                LgTextSize, LgRegexpSplitSentences, LgRegexpWordCharacters,
                LgRemoveSpaces, LgSplitEachChar, LgRightToLeft, LgShowRomanization,
                LgUsID
            ) VALUES (?, '', '', '', 100, '.!?', 'a-zA-Z', 0, 0, 0, 0, ?)",
            [$name, $userId]
        );
    }

    protected function setUp(): void
    {
        if (!self::$dbConnected) {
            $this->markTestSkipped('Database connection required');
        }
        Globals::setMultiUserEnabled(true);
        Globals::setCurrentUserId(self::$userId);
        Connection::preparedExecute(
            'DELETE FROM settings WHERE StUsID = ?',
            [self::$userId]
        );
        Settings::savePerUser('currentlanguage', (string) self::$languageId);
    }

    protected function tearDown(): void
    {
        unset($_GET['filterlang'], $_REQUEST['filterlang'], $_GET['sort'], $_REQUEST['sort']);
        Globals::setCurrentUserId(null);
        Globals::setMultiUserEnabled(false);
        Container::setInstance(null);
    }

    private function globalValue(string $key): ?string
    {
        $value = Connection::preparedFetchValue(
            'SELECT StValue FROM settings WHERE StKey = ? AND StUsID = 0',
            [$key],
            'StValue'
        );
        return $value === null ? null : (string) $value;
    }

    private function translatorIn(string $locale): Translator
    {
        $translator = new Translator(getcwd() . '/locale', 'en');
        $translator->setLocale($locale);
        $container = new Container();
        $container->instance(Translator::class, $translator);
        Container::setInstance($container);
        return $translator;
    }

    public function testFilterParameterReachesTheNavbarAndNotTheGlobalRow(): void
    {
        $globalBefore = $this->globalValue('currentlanguage');
        $_GET['filterlang'] = $_REQUEST['filterlang'] = (string) self::$otherLanguageId;

        InputValidator::getStringWithDb('filterlang', 'currentlanguage');

        $this->assertSame(self::$otherLanguageId, CurrentLanguage::resolveId());
        $this->assertSame($globalBefore, $this->globalValue('currentlanguage'));
    }

    public function testRememberedSortOrderIsPerUser(): void
    {
        $globalBefore = $this->globalValue('currentwordsort');
        $_GET['sort'] = $_REQUEST['sort'] = '3';

        InputValidator::getIntWithDb('sort', 'currentwordsort', 1);

        $this->assertSame('3', Settings::get('currentwordsort'));
        $this->assertSame($globalBefore, $this->globalValue('currentwordsort'));
    }

    public function testFollowingALanguageMakesItCurrent(): void
    {
        CurrentLanguage::follow(self::$otherLanguageId);

        $this->assertSame(self::$otherLanguageId, CurrentLanguage::resolveId());
    }

    public function testAnotherUsersLanguageCannotBecomeCurrent(): void
    {
        CurrentLanguage::follow(self::$foreignLanguageId);

        // The stored row, since resolveId() would hide a bad write behind its fallback.
        $this->assertSame((string) self::$languageId, Settings::getOwnValue('currentlanguage'));
    }

    public function testCreatingALanguageMakesItCurrent(): void
    {
        $result = (new LanguageApiHandler())->formatCreate(['name' => 'CurrentCreated']);

        $this->assertTrue($result['success']);
        $this->assertSame($result['id'], CurrentLanguage::resolveId());
    }

    public function testSettingTheDefaultLanguageIsPerUser(): void
    {
        $globalBefore = $this->globalValue('currentlanguage');

        (new LanguageApiHandler())->formatSetDefault(self::$otherLanguageId);

        $this->assertSame(self::$otherLanguageId, CurrentLanguage::resolveId());
        $this->assertSame($globalBefore, $this->globalValue('currentlanguage'));
    }

    public function testUsersOwnInterfaceLanguageIsApplied(): void
    {
        $translator = $this->translatorIn('en');
        Settings::savePerUser('app_language', 'de');

        UserLocale::apply();

        $this->assertSame('de', $translator->getLocale());
    }

    public function testUsersChoiceBeatsWhatBootSettledOn(): void
    {
        // Boot applies the lwt_lang cookie, so this stands for a stale one.
        $translator = $this->translatorIn('fr');
        Settings::savePerUser('app_language', 'en');

        UserLocale::apply();

        $this->assertSame('en', $translator->getLocale());
    }

    public function testNoChoiceKeepsTheLocaleFromBoot(): void
    {
        $translator = $this->translatorIn('fr');

        UserLocale::apply();

        $this->assertSame('fr', $translator->getLocale());
    }

    public function testAnotherUsersChoiceIsNotApplied(): void
    {
        $translator = $this->translatorIn('en');
        Settings::saveForUser('app_language', 'de', self::$otherUserId);

        UserLocale::apply();

        $this->assertSame('en', $translator->getLocale());
    }

    public function testUnknownLocaleIsIgnored(): void
    {
        $translator = $this->translatorIn('en');
        Settings::savePerUser('app_language', 'zz');

        UserLocale::apply();

        $this->assertSame('en', $translator->getLocale());
    }
}
