<?php

declare(strict_types=1);

namespace Lwt\Tests\Shared\I18n;

use Lwt\Shared\I18n\Translator;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Unit tests for the Translator service, focused on getAllTranslations() —
 * the flat bundle the API ships to a configurable client.
 *
 * Uses a throwaway on-disk locale fixture so assertions are deterministic and
 * independent of the shipped locale/ files.
 */
#[CoversClass(Translator::class)]
class TranslatorTest extends TestCase
{
    private string $localePath = '';

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/lwt-i18n-' . uniqid('', true);
        $this->localePath = $base;

        // English: canonical, complete set across two namespaces.
        $this->writeNamespace('en', 'common', [
            'save' => 'Save',
            'cancel' => 'Cancel',
            'only_en' => 'English only',
        ]);
        $this->writeNamespace('en', 'navbar', [
            'texts' => 'Texts',
        ]);

        // Spanish: partial — overrides one key, leaves others to fall back.
        $this->writeNamespace('es', 'common', [
            'save' => 'Guardar',
        ]);
        $this->writeNamespace('es', 'navbar', [
            'texts' => 'Textos',
        ]);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->localePath);
    }

    public function testReturnsFlatNamespacePrefixedKeysForEnglish(): void
    {
        $t = new Translator($this->localePath, 'en');

        $all = $t->getAllTranslations('en');

        $this->assertSame('Save', $all['common.save']);
        $this->assertSame('Cancel', $all['common.cancel']);
        $this->assertSame('Texts', $all['navbar.texts']);
    }

    public function testMergesEnglishFallbackForMissingLocaleKeys(): void
    {
        $t = new Translator($this->localePath, 'es');

        $all = $t->getAllTranslations('es');

        // Overridden in Spanish.
        $this->assertSame('Guardar', $all['common.save']);
        $this->assertSame('Textos', $all['navbar.texts']);
        // Absent in Spanish — falls back to English.
        $this->assertSame('Cancel', $all['common.cancel']);
        $this->assertSame('English only', $all['common.only_en']);
    }

    public function testNamespaceFilterLimitsThePayload(): void
    {
        $t = new Translator($this->localePath, 'en');

        $all = $t->getAllTranslations('en', ['navbar']);

        $this->assertArrayHasKey('navbar.texts', $all);
        $this->assertArrayNotHasKey('common.save', $all);
    }

    public function testUnknownLocaleFallsBackToEnglish(): void
    {
        $t = new Translator($this->localePath, 'en');

        $all = $t->getAllTranslations('xx');

        $this->assertSame('Save', $all['common.save']);
    }

    public function testNamespaceTraversalAttemptIsNeutralised(): void
    {
        $t = new Translator($this->localePath, 'en');

        $all = $t->getAllTranslations('en', ['../../etc/passwd', 'common']);

        // Bogus namespace contributes nothing; the valid one still resolves.
        $this->assertSame('Save', $all['common.save']);
        foreach (array_keys($all) as $key) {
            $this->assertStringStartsWith('common.', $key);
        }
    }

    public function testLocaleTraversalAttemptFallsBackToEnglish(): void
    {
        $t = new Translator($this->localePath, 'en');

        $all = $t->getAllTranslations('../../../etc');

        // Falls back to English rather than reading outside the locale dir.
        $this->assertSame('Save', $all['common.save']);
    }

    public function testUnknownNamespaceYieldsEmptyMap(): void
    {
        $t = new Translator($this->localePath, 'en');

        $this->assertSame([], $t->getAllTranslations('en', ['does-not-exist']));
    }

    /**
     * @param array<string, string> $strings
     */
    private function writeNamespace(string $locale, string $namespace, array $strings): void
    {
        $dir = $this->localePath . '/' . $locale;
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents(
            $dir . '/' . $namespace . '.json',
            (string) json_encode($strings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function testLeftToRightLocalesReportLtr(): void
    {
        $t = new Translator($this->localePath, 'en');
        $this->assertSame('ltr', $t->getTextDirection());
        foreach (['de', 'es', 'fr', 'it', 'ja', 'pt', 'ru', 'zh', 'zh-Hant'] as $code) {
            $this->assertSame('ltr', $t->getTextDirection($code), $code);
        }
    }

    public function testRightToLeftLocalesReportRtl(): void
    {
        $t = new Translator($this->localePath, 'en');
        foreach (['ar', 'fa', 'he', 'ur', 'ckb', 'ar-EG', 'fa_IR', 'HE'] as $code) {
            $this->assertSame('rtl', $t->getTextDirection($code), $code);
        }
    }

    public function testDirectionFollowsTheActiveLocale(): void
    {
        $t = new Translator($this->localePath, 'en');
        $t->setLocale('ar');
        $this->assertSame('rtl', $t->getTextDirection());
    }

    public function testOnlyThePrimarySubtagDecides(): void
    {
        // "hr" is Croatian, not Hebrew; a prefix match would get these wrong
        $t = new Translator($this->localePath, 'en');
        $this->assertSame('ltr', $t->getTextDirection('hr'));
        $this->assertSame('ltr', $t->getTextDirection('arn'));
    }

    public function testPluralPicksTheLocaleCategory(): void
    {
        $this->writeNamespace('ar', 'common', [
            'terms_zero' => 'لا مصطلحات',
            'terms_one' => 'مصطلح واحد',
            'terms_two' => 'مصطلحان',
            'terms_few' => '{count} مصطلحات',
            'terms_many' => '{count} مصطلحًا',
            'terms_other' => '{count} مصطلح',
        ]);
        $t = new Translator($this->localePath, 'ar');

        $this->assertSame('لا مصطلحات', $t->translatePlural('common.terms', 0));
        $this->assertSame('مصطلحان', $t->translatePlural('common.terms', 2));
        $this->assertSame('5 مصطلحات', $t->translatePlural('common.terms', 5));
        $this->assertSame('11 مصطلحًا', $t->translatePlural('common.terms', 11));
        $this->assertSame('100 مصطلح', $t->translatePlural('common.terms', 100));
    }

    public function testPluralFallsBackToOtherThenEnglish(): void
    {
        $this->writeNamespace('en', 'common', [
            'terms_one' => '{count} term',
            'terms_other' => '{count} terms',
            'notes_one' => '{count} note',
            'notes_other' => '{count} notes',
        ]);
        $this->writeNamespace('ru', 'common', [
            'terms_one' => '{count} термин',
            'terms_other' => '{count} терминов',
        ]);
        $t = new Translator($this->localePath, 'ru');

        // "few" is missing, so "other" stands in
        $this->assertSame('3 терминов', $t->translatePlural('common.terms', 3));
        $this->assertSame('21 термин', $t->translatePlural('common.terms', 21));
        // Russian lacks the key entirely: English, with the English category
        $this->assertSame('1 note', $t->translatePlural('common.notes', 1));
        $this->assertSame('4 notes', $t->translatePlural('common.notes', 4));
        $this->assertSame('common.missing', $t->translatePlural('common.missing', 1));
    }

    public function testPluralLetsTheCallerFormatTheCount(): void
    {
        $this->writeNamespace('en', 'common', [
            'terms_one' => '{count} term in {lang}',
            'terms_other' => '{count} terms in {lang}',
        ]);
        $t = new Translator($this->localePath, 'en');

        $this->assertSame(
            '1,200 terms in French',
            $t->translatePlural('common.terms', 1200, ['count' => '1,200', 'lang' => 'French'])
        );
    }
}
