<?php

/**
 * Unit tests for Language module use cases.
 *
 * Tests CreateLanguage and UpdateLanguage use cases. Pure logic methods
 * are tested directly; methods relying on static database calls are
 * tested for structure and contracts only.
 *
 * PHP version 8.2
 *
 * @category Testing
 * @package  Lwt\Tests\Modules\Language\Application\UseCases
 * @license  Unlicense <http://unlicense.org/>
 * @since    3.0.0
 */

declare(strict_types=1);

namespace Tests\Modules\Language\Application\UseCases;

use Lwt\Modules\Language\Application\UseCases\CreateLanguage;
use Lwt\Modules\Language\Application\UseCases\ReparseLanguageTexts;
use Lwt\Modules\Language\Application\UseCases\UpdateLanguage;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the Language module use cases.
 *
 * Tests business logic in isolation. Methods relying on static
 * QueryBuilder/InputValidator calls are tested for structure
 * and pure helper logic. No database access required.
 *
 * @since 3.0.0
 */
class LanguageUseCasesTest extends TestCase
{
    // =========================================================================
    // CreateLanguage tests
    // =========================================================================

    /**
     * Test that CreateLanguage can be instantiated.
     */
    public function testCreateLanguageCanBeInstantiated(): void
    {
        $useCase = new CreateLanguage();
        $this->assertInstanceOf(CreateLanguage::class, $useCase);
    }

    /**
     * Test getLanguageDataFromRequest returns array with all expected keys.
     *
     * This tests the method structure when no $_REQUEST data is set.
     * InputValidator::getString returns '' by default for missing keys,
     * and InputValidator::has returns false.
     */
    public function testGetLanguageDataFromRequestReturnsExpectedKeys(): void
    {
        // Clear any existing request data
        $originalRequest = $_REQUEST;
        $_REQUEST = [];

        try {
            $useCase = new CreateLanguage();
            $data = $useCase->getLanguageDataFromRequest();

            $this->assertIsArray($data);

            $expectedKeys = [
                'LgName', 'LgDict1URI', 'LgDict2URI', 'LgGoogleTranslateURI',
                'LgDict1PopUp', 'LgDict2PopUp', 'LgGoogleTranslatePopUp',
                'LgSourceLang', 'LgTargetLang', 'LgExportTemplate',
                'LgTextSize', 'LgCharacterSubstitutions',
                'LgRegexpSplitSentences', 'LgExceptionsSplitSentences',
                'LgRegexpWordCharacters', 'LgParserType', 'LgRemoveSpaces',
                'LgSplitEachChar', 'LgRightToLeft', 'LgTTSVoiceAPI',
                'LgShowRomanization', 'LgLocalDictMode',
            ];

            foreach ($expectedKeys as $key) {
                $this->assertArrayHasKey($key, $data, "Missing key: {$key}");
            }
        } finally {
            $_REQUEST = $originalRequest;
        }
    }

    /**
     * Test getLanguageDataFromRequest returns correct types for boolean fields.
     */
    public function testGetLanguageDataFromRequestBooleanFields(): void
    {
        $originalRequest = $_REQUEST;
        $_REQUEST = [];

        try {
            $useCase = new CreateLanguage();
            $data = $useCase->getLanguageDataFromRequest();

            // Boolean fields should be false when not in request
            $this->assertFalse($data['LgDict1PopUp']);
            $this->assertFalse($data['LgDict2PopUp']);
            $this->assertFalse($data['LgGoogleTranslatePopUp']);
            $this->assertFalse($data['LgRemoveSpaces']);
            $this->assertFalse($data['LgSplitEachChar']);
            $this->assertFalse($data['LgRightToLeft']);
            $this->assertFalse($data['LgShowRomanization']);
        } finally {
            $_REQUEST = $originalRequest;
        }
    }

    /**
     * Test getLanguageDataFromRequest with populated request data.
     */
    public function testGetLanguageDataFromRequestWithData(): void
    {
        $originalRequest = $_REQUEST;
        $_REQUEST = [
            'LgName' => 'French',
            'LgDict1URI' => 'https://dict.example.com/###',
            'LgDict2URI' => '',
            'LgGoogleTranslateURI' => 'https://translate.example.com',
            'LgDict1PopUp' => '1',
            'LgDict2PopUp' => '',
            'LgGoogleTranslatePopUp' => '',
            'LgSourceLang' => 'fr',
            'LgTargetLang' => 'en',
            'LgExportTemplate' => '$w\\t$t',
            'LgTextSize' => '150',
            'LgCharacterSubstitutions' => '',
            'LgRegexpSplitSentences' => '.!?',
            'LgExceptionsSplitSentences' => 'Mr.',
            'LgRegexpWordCharacters' => 'a-zA-Z\x{00C0}-\x{00FF}',
            'LgParserType' => '',
            'LgRemoveSpaces' => '',
            'LgSplitEachChar' => '',
            'LgRightToLeft' => '',
            'LgTTSVoiceAPI' => '',
            'LgShowRomanization' => '',
            'LgLocalDictMode' => '0',
        ];

        try {
            $useCase = new CreateLanguage();
            $data = $useCase->getLanguageDataFromRequest();

            $this->assertSame('French', $data['LgName']);
            $this->assertSame('https://dict.example.com/###', $data['LgDict1URI']);
            $this->assertTrue($data['LgDict1PopUp']);
            $this->assertSame('fr', $data['LgSourceLang']);
            $this->assertSame('en', $data['LgTargetLang']);
            $this->assertSame('150', $data['LgTextSize']);
            $this->assertSame('.!?', $data['LgRegexpSplitSentences']);
            $this->assertSame(0, $data['LgLocalDictMode']);
        } finally {
            $_REQUEST = $originalRequest;
        }
    }

    /**
     * Test getLanguageDataFromRequest defaults for nullable fields.
     */
    public function testGetLanguageDataFromRequestNullableDefaults(): void
    {
        $originalRequest = $_REQUEST;
        $_REQUEST = [];

        try {
            $useCase = new CreateLanguage();
            $data = $useCase->getLanguageDataFromRequest();

            // Source/target lang default to null when empty
            $this->assertNull($data['LgSourceLang']);
            $this->assertNull($data['LgTargetLang']);
            $this->assertNull($data['LgParserType']);
        } finally {
            $_REQUEST = $originalRequest;
        }
    }

    /**
     * Test getLanguageDataFromRequest default for LgTextSize.
     */
    public function testGetLanguageDataFromRequestTextSizeDefault(): void
    {
        $originalRequest = $_REQUEST;
        $_REQUEST = [];

        try {
            $useCase = new CreateLanguage();
            $data = $useCase->getLanguageDataFromRequest();

            $this->assertSame('100', $data['LgTextSize']);
        } finally {
            $_REQUEST = $originalRequest;
        }
    }

    /**
     * Test getLanguageDataFromRequest LgLocalDictMode is int.
     */
    public function testGetLanguageDataFromRequestLocalDictModeIsInt(): void
    {
        $originalRequest = $_REQUEST;
        $_REQUEST = ['LgLocalDictMode' => '2'];

        try {
            $useCase = new CreateLanguage();
            $data = $useCase->getLanguageDataFromRequest();

            $this->assertIsInt($data['LgLocalDictMode']);
            $this->assertSame(2, $data['LgLocalDictMode']);
        } finally {
            $_REQUEST = $originalRequest;
        }
    }

    // =========================================================================
    // UpdateLanguage tests
    // =========================================================================

    /**
     * Test that UpdateLanguage can be instantiated with default reparse use case.
     */
    public function testUpdateLanguageCanBeInstantiatedWithDefaults(): void
    {
        $useCase = new UpdateLanguage();
        $this->assertInstanceOf(UpdateLanguage::class, $useCase);
    }

    /**
     * Test that UpdateLanguage can be instantiated with injected reparse use case.
     */
    public function testUpdateLanguageCanBeInstantiatedWithInjectedReparse(): void
    {
        /** @var ReparseLanguageTexts&MockObject $reparse */
        $reparse = $this->createMock(ReparseLanguageTexts::class);

        $useCase = new UpdateLanguage($reparse);
        $this->assertInstanceOf(UpdateLanguage::class, $useCase);
    }

    /**
     * Test that UpdateLanguage accepts null for reparse use case.
     */
    public function testUpdateLanguageAcceptsNullReparse(): void
    {
        $useCase = new UpdateLanguage(null);
        $this->assertInstanceOf(UpdateLanguage::class, $useCase);
    }
}
