<?php

declare(strict_types=1);

namespace Lwt\Tests\Core\Entity;

use DateTimeImmutable;
use InvalidArgumentException;
use Lwt\Modules\Dictionary\Infrastructure\Translation\GoogleTranslateClient;
use Lwt\Modules\Language\Domain\Language;
use Lwt\Modules\Vocabulary\Domain\Term;
use Lwt\Modules\Text\Domain\Text;
use Lwt\Modules\Language\Domain\ValueObject\LanguageId;
use Lwt\Modules\Vocabulary\Domain\ValueObject\TermId;
use Lwt\Modules\Vocabulary\Domain\ValueObject\TermStatus;
use Lwt\Modules\Text\Domain\ValueObject\TextId;
use PHPUnit\Framework\TestCase;

/**
 * Comprehensive tests for PHP classes: Language, Term, Text, GoogleTranslate
 *
 * Tests rich domain model entities, value objects, and core methods
 */
class ClassesTest extends TestCase
{
    // =========================================================================
    // Value Object Tests
    // =========================================================================

    public function testLanguageIdFromInt(): void
    {
        $id = LanguageId::fromInt(42);
        $this->assertEquals(42, $id->toInt());
        $this->assertFalse($id->isNew());
    }

    public function testLanguageIdNew(): void
    {
        $id = LanguageId::new();
        $this->assertEquals(0, $id->toInt());
        $this->assertTrue($id->isNew());
    }

    public function testLanguageIdRejectsZero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        LanguageId::fromInt(0);
    }

    public function testLanguageIdRejectsNegative(): void
    {
        $this->expectException(InvalidArgumentException::class);
        LanguageId::fromInt(-1);
    }

    public function testLanguageIdEquality(): void
    {
        $id1 = LanguageId::fromInt(5);
        $id2 = LanguageId::fromInt(5);
        $id3 = LanguageId::fromInt(6);

        $this->assertTrue($id1->equals($id2));
        $this->assertFalse($id1->equals($id3));
    }

    public function testTermStatusValues(): void
    {
        $this->assertEquals(1, TermStatus::new()->toInt());
        $this->assertEquals(5, TermStatus::learned()->toInt());
        $this->assertEquals(98, TermStatus::ignored()->toInt());
        $this->assertEquals(99, TermStatus::wellKnown()->toInt());
    }

    public function testTermStatusAdvance(): void
    {
        $status = TermStatus::new();
        $this->assertEquals(1, $status->toInt());

        $status = $status->advance();
        $this->assertEquals(2, $status->toInt());

        $status = $status->advance();
        $this->assertEquals(3, $status->toInt());

        $status = $status->advance();
        $this->assertEquals(4, $status->toInt());

        $status = $status->advance();
        $this->assertEquals(5, $status->toInt());

        // Should not advance past 5
        $status = $status->advance();
        $this->assertEquals(5, $status->toInt());
    }

    public function testTermStatusDecrease(): void
    {
        $status = TermStatus::learned();
        $this->assertEquals(5, $status->toInt());

        $status = $status->decrease();
        $this->assertEquals(4, $status->toInt());

        // Continue to 1
        $status = $status->decrease()->decrease()->decrease();
        $this->assertEquals(1, $status->toInt());

        // Should not decrease past 1
        $status = $status->decrease();
        $this->assertEquals(1, $status->toInt());
    }

    public function testTermStatusIsKnown(): void
    {
        $this->assertFalse(TermStatus::new()->isKnown());
        $this->assertFalse(TermStatus::fromInt(4)->isKnown());
        $this->assertTrue(TermStatus::learned()->isKnown());
        $this->assertTrue(TermStatus::wellKnown()->isKnown());
        $this->assertFalse(TermStatus::ignored()->isKnown());
    }

    public function testTermStatusIsLearning(): void
    {
        $this->assertTrue(TermStatus::new()->isLearning());
        $this->assertTrue(TermStatus::fromInt(2)->isLearning());
        $this->assertTrue(TermStatus::fromInt(4)->isLearning());
        $this->assertFalse(TermStatus::learned()->isLearning());
        $this->assertFalse(TermStatus::ignored()->isLearning());
    }

    public function testTermStatusIsSpecial(): void
    {
        $this->assertFalse(TermStatus::new()->isSpecial());
        $this->assertFalse(TermStatus::learned()->isSpecial());
        $this->assertTrue(TermStatus::ignored()->isSpecial());
        $this->assertTrue(TermStatus::wellKnown()->isSpecial());
    }

    public function testTermStatusLabels(): void
    {
        $this->assertEquals('New', TermStatus::new()->label());
        $this->assertEquals('Learning (2)', TermStatus::fromInt(2)->label());
        $this->assertEquals('Learned', TermStatus::learned()->label());
        $this->assertEquals('Ignored', TermStatus::ignored()->label());
        $this->assertEquals('Well Known', TermStatus::wellKnown()->label());
    }

    public function testTermStatusFromIntRejectsInvalid(): void
    {
        $this->expectException(InvalidArgumentException::class);
        TermStatus::fromInt(50);
    }

    // =========================================================================
    // Language Entity Tests
    // =========================================================================

    public function testLanguageCreate(): void
    {
        $lang = Language::create(
            'English',
            'https://en.wiktionary.org/wiki/###',
            '.!?',
            'a-zA-Z'
        );

        $this->assertTrue($lang->id()->isNew());
        $this->assertEquals('English', $lang->name());
        $this->assertEquals('https://en.wiktionary.org/wiki/###', $lang->dict1Uri());
        $this->assertEquals('.!?', $lang->regexpSplitSentences());
        $this->assertEquals('a-zA-Z', $lang->regexpWordCharacters());
        $this->assertEquals(100, $lang->textSize());
        $this->assertFalse($lang->rightToLeft());
        $this->assertTrue($lang->showRomanization());
    }

    public function testLanguageCreateRejectsEmptyName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Language::create('', 'http://dict.test', '.!?', 'a-z');
    }

    public function testLanguageCreateTrimsWhitespace(): void
    {
        $lang = Language::create('  English  ', 'http://dict.test', '.!?', 'a-z');
        $this->assertEquals('English', $lang->name());
    }

    public function testLanguageReconstitute(): void
    {
        $lang = Language::reconstitute(
            42,
            'German',
            'http://dict1.test',
            'http://dict2.test',
            'http://translate.test',
            false,  // dict1PopUp
            false,  // dict2PopUp
            false,  // translatorPopUp
            'de',   // sourceLang
            'en',   // targetLang
            'Template: %s',
            150,
            'ß=ss',
            '.!?',
            'Dr.|Mr.',
            'a-zA-ZäöüÄÖÜß',
            false,
            false,
            false,
            'http://tts.test',
            true
        );

        $this->assertEquals(42, $lang->id()->toInt());
        $this->assertFalse($lang->id()->isNew());
        $this->assertEquals('German', $lang->name());
        $this->assertEquals('http://dict1.test', $lang->dict1Uri());
        $this->assertEquals('http://dict2.test', $lang->dict2Uri());
        $this->assertEquals('http://translate.test', $lang->translatorUri());
        $this->assertEquals(150, $lang->textSize());
        $this->assertEquals('ß=ss', $lang->characterSubstitutions());
    }

    public function testLanguageRename(): void
    {
        $lang = Language::create('English', '', '.!?', 'a-z');
        $lang->rename('British English');
        $this->assertEquals('British English', $lang->name());
    }

    public function testLanguageRenameRejectsEmpty(): void
    {
        $lang = Language::create('English', '', '.!?', 'a-z');
        $this->expectException(InvalidArgumentException::class);
        $lang->rename('   ');
    }

    public function testLanguageConfigureDictionaries(): void
    {
        $lang = Language::create('English', '', '.!?', 'a-z');
        $lang->configureDictionaries('http://primary.test', 'http://secondary.test');

        $this->assertEquals('http://primary.test', $lang->dict1Uri());
        $this->assertEquals('http://secondary.test', $lang->dict2Uri());
    }

    public function testLanguageConfigureTextParsing(): void
    {
        $lang = Language::create('English', '', '.!?', 'a-z');
        $lang->configureTextParsing('.!?;', 'Dr.|Mr.', 'a-zA-Z', 'oe=ö');

        $this->assertEquals('.!?;', $lang->regexpSplitSentences());
        $this->assertEquals('Dr.|Mr.', $lang->exceptionsSplitSentences());
        $this->assertEquals('a-zA-Z', $lang->regexpWordCharacters());
        $this->assertEquals('oe=ö', $lang->characterSubstitutions());
    }

    public function testLanguageConfigureCjkMode(): void
    {
        $lang = Language::create('Chinese', '', '.!?', '\x{4E00}-\x{9FFF}');
        $lang->configureCjkMode(true, true);

        $this->assertTrue($lang->removeSpaces());
        $this->assertTrue($lang->splitEachChar());
        $this->assertTrue($lang->isCjkStyle());
    }

    public function testLanguageSetRightToLeft(): void
    {
        $lang = Language::create('Arabic', '', '.!?', '\x{0600}-\x{06FF}');
        $lang->setRightToLeft(true);

        $this->assertTrue($lang->rightToLeft());
        $this->assertEquals(' dir="rtl" ', $lang->getDirectionAttribute());
    }

    public function testLanguageSetTextSize(): void
    {
        $lang = Language::create('English', '', '.!?', 'a-z');
        $lang->setTextSize(150);
        $this->assertEquals(150, $lang->textSize());
    }

    public function testLanguageSetTextSizeRejectsInvalid(): void
    {
        $lang = Language::create('English', '', '.!?', 'a-z');
        $this->expectException(InvalidArgumentException::class);
        $lang->setTextSize(400);
    }

    public function testLanguageGetDictionaryUrl(): void
    {
        $lang = Language::create('English', 'http://dict.test/lwt_term', '.!?', 'a-z');
        $url = $lang->getDictionaryUrl('hello');
        $this->assertEquals('http://dict.test/hello', $url);
    }

    public function testLanguageExportJsDict(): void
    {
        $lang = Language::reconstitute(
            42,
            'Test',
            'http://dict1',
            'http://dict2',
            'http://trans',
            false,
            false,
            false,
            null,
            null,  // popup and lang fields
            'Template',
            100,
            'a=b',
            '.!?',
            'Dr.',
            'a-z',
            true,
            false,
            true,
            'http://tts',
            true
        );

        $json = $lang->exportJsDict();
        $this->assertJson($json);

        $decoded = json_decode($json, true);
        $this->assertEquals(42, $decoded['lgid']);
        $this->assertEquals('http://dict1', $decoded['dict1uri']);
        $this->assertTrue($decoded['removespaces']);
        $this->assertTrue($decoded['rightoleft']);
    }

    public function testLanguageHasMethods(): void
    {
        $lang = Language::create('English', 'http://dict1', '.!?', 'a-z');

        $this->assertFalse($lang->hasSecondaryDictionary());
        $this->assertFalse($lang->hasTranslator());
        $this->assertFalse($lang->hasExportTemplate());
        $this->assertFalse($lang->hasTts());

        $lang->configureDictionaries('http://dict1', 'http://dict2');
        $lang->configureTranslator('http://translate');
        $lang->setExportTemplate('Template');
        $lang->configureTts('http://tts');

        $this->assertTrue($lang->hasSecondaryDictionary());
        $this->assertTrue($lang->hasTranslator());
        $this->assertTrue($lang->hasExportTemplate());
        $this->assertTrue($lang->hasTts());
    }

    // =========================================================================
    // Term Entity Tests
    // =========================================================================

    public function testTermCreate(): void
    {
        $langId = LanguageId::fromInt(1);
        $term = Term::create($langId, 'Hello', 'Hola');

        $this->assertTrue($term->id()->isNew());
        $this->assertEquals(1, $term->languageId()->toInt());
        $this->assertEquals('Hello', $term->text());
        $this->assertEquals('hello', $term->textLowercase());
        $this->assertEquals('Hola', $term->translation());
        $this->assertEquals(1, $term->status()->toInt());
        $this->assertEquals(1, $term->wordCount());
    }

    public function testTermCreateRejectsEmptyText(): void
    {
        $langId = LanguageId::fromInt(1);
        $this->expectException(InvalidArgumentException::class);
        Term::create($langId, '   ');
    }

    public function testTermCreateMultiWord(): void
    {
        $langId = LanguageId::fromInt(1);
        $term = Term::create($langId, 'good morning');

        $this->assertEquals('good morning', $term->text());
        $this->assertEquals(2, $term->wordCount());
        $this->assertTrue($term->isMultiWord());
    }

    public function testTermReconstitute(): void
    {
        $term = Term::reconstitute(
            123,
            1,
            'Hello',
            'hello',
            null,
            null,
            3,
            'Hola',
            'Hello world.',
            'My notes',
            'heh-lo',
            1,
            new DateTimeImmutable('2024-01-01'),
            new DateTimeImmutable('2024-01-02')
        );

        $this->assertEquals(123, $term->id()->toInt());
        $this->assertEquals(1, $term->languageId()->toInt());
        $this->assertEquals('Hello', $term->text());
        $this->assertEquals(3, $term->status()->toInt());
        $this->assertEquals('Hola', $term->translation());
        $this->assertEquals('Hello world.', $term->sentence());
        $this->assertEquals('My notes', $term->notes());
        $this->assertEquals('heh-lo', $term->romanization());
    }

    public function testTermAdvanceStatus(): void
    {
        $langId = LanguageId::fromInt(1);
        $term = Term::create($langId, 'test');

        $this->assertEquals(1, $term->status()->toInt());

        $term->advanceStatus();
        $this->assertEquals(2, $term->status()->toInt());

        $term->advanceStatus();
        $this->assertEquals(3, $term->status()->toInt());
    }

    public function testTermDecreaseStatus(): void
    {
        $term = Term::reconstitute(
            1,
            1,
            'test',
            'test',
            null,
            null,
            3,
            '',
            '',
            '',
            '',
            1,
            new DateTimeImmutable(),
            new DateTimeImmutable(),
            0,
            0,
            0
        );

        $term->decreaseStatus();
        $this->assertEquals(2, $term->status()->toInt());

        $term->decreaseStatus();
        $this->assertEquals(1, $term->status()->toInt());

        // Should not go below 1
        $term->decreaseStatus();
        $this->assertEquals(1, $term->status()->toInt());
    }

    public function testTermMarkAsLearned(): void
    {
        $langId = LanguageId::fromInt(1);
        $term = Term::create($langId, 'test');

        $term->markAsLearned();
        $this->assertEquals(5, $term->status()->toInt());
        $this->assertTrue($term->isKnown());
    }

    public function testTermIgnore(): void
    {
        $langId = LanguageId::fromInt(1);
        $term = Term::create($langId, 'test');

        $term->ignore();
        $this->assertEquals(98, $term->status()->toInt());
        $this->assertTrue($term->isIgnored());
    }

    public function testTermMarkAsWellKnown(): void
    {
        $langId = LanguageId::fromInt(1);
        $term = Term::create($langId, 'test');

        $term->markAsWellKnown();
        $this->assertEquals(99, $term->status()->toInt());
        $this->assertTrue($term->isKnown());
    }

    public function testTermUpdateTranslation(): void
    {
        $langId = LanguageId::fromInt(1);
        $term = Term::create($langId, 'test');

        $term->updateTranslation('prueba');
        $this->assertEquals('prueba', $term->translation());
        $this->assertTrue($term->hasTranslation());
    }

    public function testTermHasTranslation(): void
    {
        $langId = LanguageId::fromInt(1);
        $term = Term::create($langId, 'test');

        $this->assertFalse($term->hasTranslation());

        $term->updateTranslation('*');
        $this->assertFalse($term->hasTranslation());

        $term->updateTranslation('translation');
        $this->assertTrue($term->hasTranslation());
    }

    public function testTermUpdateSentenceAndRomanization(): void
    {
        $langId = LanguageId::fromInt(1);
        $term = Term::create($langId, 'test');

        $term->updateSentence('This is a test.');
        $term->updateRomanization('test-ro');

        $this->assertSame('This is a test.', $term->sentence());
        $this->assertSame('test-ro', $term->romanization());
    }

    public function testTermNeedsReview(): void
    {
        $langId = LanguageId::fromInt(1);
        $term = Term::create($langId, 'test');

        $this->assertTrue($term->needsReview()); // Status 1

        $term->markAsLearned();
        $this->assertFalse($term->needsReview()); // Status 5

        $langId2 = LanguageId::fromInt(1);
        $term2 = Term::create($langId2, 'test2');
        $term2->ignore();
        $this->assertFalse($term2->needsReview()); // Status 98
    }

    public function testTermStatusChangedAtUpdates(): void
    {
        $langId = LanguageId::fromInt(1);
        $term = Term::create($langId, 'test');
        $originalTime = $term->statusChangedAt();

        // Small delay to ensure time difference
        usleep(10000);

        $term->advanceStatus();
        $this->assertGreaterThan($originalTime, $term->statusChangedAt());
    }

    // =========================================================================
    // Text Entity Tests
    // =========================================================================

    public function testTextCreate(): void
    {
        $langId = LanguageId::fromInt(1);
        $text = Text::create($langId, 'My First Text', 'This is the content.');

        $this->assertTrue($text->id()->isNew());
        $this->assertEquals(1, $text->languageId()->toInt());
        $this->assertEquals('My First Text', $text->title());
        $this->assertEquals('This is the content.', $text->text());
        $this->assertEquals(0, $text->position());
        $this->assertEquals(0.0, $text->audioPosition());
    }

    public function testTextCreateRejectsEmptyTitle(): void
    {
        $langId = LanguageId::fromInt(1);
        $this->expectException(InvalidArgumentException::class);
        Text::create($langId, '   ', 'Content');
    }

    public function testTextCreateRejectsEmptyContent(): void
    {
        $langId = LanguageId::fromInt(1);
        $this->expectException(InvalidArgumentException::class);
        Text::create($langId, 'Title', '   ');
    }

    public function testTextReconstitute(): void
    {
        $text = Text::reconstitute(
            456,
            1,
            'Test Title',
            'Test content.',
            '<annotated>content</annotated>',
            'http://audio.mp3',
            'http://source.com',
            50,
            12.5
        );

        $this->assertEquals(456, $text->id()->toInt());
        $this->assertEquals(1, $text->languageId()->toInt());
        $this->assertEquals('Test Title', $text->title());
        $this->assertEquals('Test content.', $text->text());
        $this->assertEquals('<annotated>content</annotated>', $text->annotatedText());
        $this->assertEquals('http://audio.mp3', $text->mediaUri());
        $this->assertEquals('http://source.com', $text->sourceUri());
        $this->assertEquals(50, $text->position());
        $this->assertEquals(12.5, $text->audioPosition());
    }

    public function testTextRename(): void
    {
        $langId = LanguageId::fromInt(1);
        $text = Text::create($langId, 'Original Title', 'Content');

        $text->rename('New Title');
        $this->assertEquals('New Title', $text->title());
    }

    public function testTextRenameRejectsEmpty(): void
    {
        $langId = LanguageId::fromInt(1);
        $text = Text::create($langId, 'Title', 'Content');

        $this->expectException(InvalidArgumentException::class);
        $text->rename('   ');
    }

    public function testTextUpdateContent(): void
    {
        $text = Text::reconstitute(
            1,
            1,
            'Title',
            'Original',
            '<annotated/>',
            '',
            '',
            0,
            0
        );

        $this->assertTrue($text->isAnnotated());

        $text->updateContent('New content');
        $this->assertEquals('New content', $text->text());
        $this->assertFalse($text->isAnnotated()); // Annotations invalidated
    }

    public function testTextSetMediaUri(): void
    {
        $langId = LanguageId::fromInt(1);
        $text = Text::create($langId, 'Title', 'Content');

        $text->setMediaUri('http://audio.mp3');
        $this->assertEquals('http://audio.mp3', $text->mediaUri());
        $this->assertTrue($text->hasMedia());
    }

    public function testTextUpdatePosition(): void
    {
        $langId = LanguageId::fromInt(1);
        $text = Text::create($langId, 'Title', 'Content');

        $text->updatePosition(100);
        $this->assertEquals(100, $text->position());

        // Negative should become 0
        $text->updatePosition(-5);
        $this->assertEquals(0, $text->position());
    }

    public function testTextUpdateAudioPosition(): void
    {
        $langId = LanguageId::fromInt(1);
        $text = Text::create($langId, 'Title', 'Content');

        $text->updateAudioPosition(30.5);
        $this->assertEquals(30.5, $text->audioPosition());

        // Negative should become 0
        $text->updateAudioPosition(-5.0);
        $this->assertEquals(0.0, $text->audioPosition());
    }

    public function testTextResetProgress(): void
    {
        $text = Text::reconstitute(
            1,
            1,
            'Title',
            'Content',
            '',
            '',
            '',
            50,
            30.5
        );

        $text->resetProgress();
        $this->assertEquals(0, $text->position());
        $this->assertEquals(0.0, $text->audioPosition());
    }

    public function testTextHasStartedReading(): void
    {
        $langId = LanguageId::fromInt(1);
        $text = Text::create($langId, 'Title', 'Content');

        $this->assertFalse($text->hasStartedReading());

        $text->updatePosition(10);
        $this->assertTrue($text->hasStartedReading());

        $text->resetProgress();
        $text->updateAudioPosition(5.0);
        $this->assertTrue($text->hasStartedReading());
    }

    public function testTextIsYouTubeMedia(): void
    {
        $langId = LanguageId::fromInt(1);
        $text = Text::create($langId, 'Title', 'Content');

        $text->setMediaUri('https://www.youtube.com/watch?v=abc123');
        $this->assertTrue($text->isYouTubeMedia());

        $text->setMediaUri('https://youtu.be/abc123');
        $this->assertTrue($text->isYouTubeMedia());

        $text->setMediaUri('http://example.com/video.mp4');
        $this->assertFalse($text->isYouTubeMedia());
    }

    public function testTextIsLocalMedia(): void
    {
        $langId = LanguageId::fromInt(1);
        $text = Text::create($langId, 'Title', 'Content');

        $this->assertFalse($text->isLocalMedia()); // No media

        $text->setMediaUri('media/audio.mp3');
        $this->assertTrue($text->isLocalMedia());

        $text->setMediaUri('http://example.com/audio.mp3');
        $this->assertFalse($text->isLocalMedia());

        $text->setMediaUri('https://example.com/audio.mp3');
        $this->assertFalse($text->isLocalMedia());
    }

    public function testTextWordCount(): void
    {
        $langId = LanguageId::fromInt(1);
        $text = Text::create($langId, 'Title', 'This is a test sentence with seven words.');

        $this->assertEquals(8, $text->wordCount());
    }

    public function testTextCharacterCount(): void
    {
        $langId = LanguageId::fromInt(1);
        $text = Text::create($langId, 'Title', 'Hello');

        $this->assertEquals(5, $text->characterCount());

        // Test with Unicode
        $text2 = Text::create($langId, 'Title', '日本語');
        $this->assertEquals(3, $text2->characterCount());
    }

    // =========================================================================
    // GoogleTranslate Tests (unchanged functionality)
    // =========================================================================

    public function testGoogleTranslateGetDomain(): void
    {
        $domain = GoogleTranslateClient::getDomain('com');
        $this->assertEquals('com', $domain);

        $domain = GoogleTranslateClient::getDomain('de');
        $this->assertEquals('de', $domain);

        // Empty should return random valid domain
        $domain = GoogleTranslateClient::getDomain('');
        $this->assertNotEmpty($domain);

        // Invalid should return random valid domain
        $domain = GoogleTranslateClient::getDomain('invalid');
        $this->assertNotEquals('invalid', $domain);
    }

    public function testGoogleTranslateArrayIunique(): void
    {
        $input = ['Hello', 'HELLO', 'hello', 'World', 'WORLD'];
        $result = GoogleTranslateClient::arrayIunique($input);

        $this->assertLessThanOrEqual(2, count($result));
        $this->assertContains('Hello', $result);
    }

    public function testGoogleTranslateConstructorAndSetters(): void
    {
        $translator = new GoogleTranslateClient('en', 'es');
        $this->assertInstanceOf(GoogleTranslateClient::class, $translator);

        $result = $translator->setLangFrom('de');
        $this->assertInstanceOf(GoogleTranslateClient::class, $result);

        $result = $translator->setLangTo('fr');
        $this->assertInstanceOf(GoogleTranslateClient::class, $result);
    }

    public function testGoogleTranslateLastResultProperty(): void
    {
        $translator = new GoogleTranslateClient('en', 'es');
        $this->assertEquals('', $translator->lastResult);
    }

    // =========================================================================
    // Entity ID Setting Tests
    // =========================================================================

    public function testLanguageSetIdOnNewEntity(): void
    {
        $lang = Language::create('English', '', '.!?', 'a-z');
        $lang->setId(LanguageId::fromInt(42));

        $this->assertEquals(42, $lang->id()->toInt());
        $this->assertFalse($lang->id()->isNew());
    }

    public function testLanguageSetIdOnPersistedEntityFails(): void
    {
        $lang = Language::reconstitute(
            1,
            'English',
            '',
            '',
            '',
            false,
            false,
            false,
            null,
            null,  // popup and lang fields
            '',
            100,
            '',
            '.!?',
            '',
            'a-z',
            false,
            false,
            false,
            '',
            true
        );

        $this->expectException(\LogicException::class);
        $lang->setId(LanguageId::fromInt(42));
    }

    public function testTermSetIdOnNewEntity(): void
    {
        $langId = LanguageId::fromInt(1);
        $term = Term::create($langId, 'test');
        $term->setId(TermId::fromInt(42));

        $this->assertEquals(42, $term->id()->toInt());
    }

    public function testTermSetIdOnPersistedEntityFails(): void
    {
        $term = Term::reconstitute(
            1,
            1,
            'test',
            'test',
            null,
            null,
            1,
            '',
            '',
            '',
            '',
            1,
            new DateTimeImmutable(),
            new DateTimeImmutable(),
            0,
            0,
            0
        );

        $this->expectException(\LogicException::class);
        $term->setId(TermId::fromInt(42));
    }

    public function testTextSetIdOnNewEntity(): void
    {
        $langId = LanguageId::fromInt(1);
        $text = Text::create($langId, 'Title', 'Content');
        $text->setId(TextId::fromInt(42));

        $this->assertEquals(42, $text->id()->toInt());
    }

    public function testTextSetIdOnPersistedEntityFails(): void
    {
        $text = Text::reconstitute(
            1,
            1,
            'Title',
            'Content',
            '',
            '',
            '',
            0,
            0
        );

        $this->expectException(\LogicException::class);
        $text->setId(TextId::fromInt(42));
    }
}
