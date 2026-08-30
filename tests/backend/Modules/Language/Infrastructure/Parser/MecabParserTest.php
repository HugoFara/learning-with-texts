<?php

/**
 * Unit tests for MecabParser's output parsing.
 *
 * PHP version 8.2
 *
 * @category Testing
 * @package  Lwt\Tests\Modules\Language\Infrastructure\Parser
 * @license  Unlicense <http://unlicense.org/>
 * @since    3.2.2-fork
 */

declare(strict_types=1);

namespace Tests\Backend\Modules\Language\Infrastructure\Parser;

use Lwt\Modules\Language\Domain\Parser\ParserResult;
use Lwt\Modules\Language\Infrastructure\Parser\MecabParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Unit tests for MecabParser::parseMecabOutput().
 *
 * Exercises the pure output-parsing step with synthetic MeCab output, so no
 * MeCab binary is needed.
 *
 * @since 3.2.2-fork
 */
#[CoversClass(MecabParser::class)]
class MecabParserTest extends TestCase
{
    /**
     * Captured MeCab output for "東京は大きいです。" in the parser's
     * "-F %m\t%t\t%h" format, terminated with the EOP sentence marker.
     *
     * @var list<string>
     */
    private const MECAB_LINES = [
        "東京\t2\t46",
        "は\t6\t16",
        "大きい\t2\t10",
        "です\t6\t25",
        "。\t3\t7",
        "EOP\t3\t7",
    ];

    /**
     * Run the protected output parser.
     *
     * @param string $mecabOutput Raw MeCab output
     *
     * @return ParserResult
     */
    private function parseOutput(string $mecabOutput): ParserResult
    {
        $method = new ReflectionMethod(MecabParser::class, 'parseMecabOutput');

        /** @var ParserResult $result */
        $result = $method->invoke(new MecabParser(), $mecabOutput);
        return $result;
    }

    /**
     * Note the trailing '¶': unlike JapaneseTextParser::buildTokensFromMecab(),
     * which drops the final EOP order group, this parser keeps the paragraph
     * marker as a token. Asserted as-is to pin current behaviour.
     */
    #[Test]
    public function parsesMecabOutputIntoTokens(): void
    {
        $result = $this->parseOutput(implode("\n", self::MECAB_LINES) . "\n");

        $this->assertSame(
            ['東京', 'は', '大きい', 'です', '。', '¶'],
            array_map(fn($t) => $t->getText(), $result->getTokens())
        );
    }

    /**
     * MeCab's line terminator comes from MeCab, not from the host PHP runs on,
     * so splitting on PHP_EOL made this host-dependent. Whenever the output's
     * terminator is not the one being split on, the whole output stays a single
     * line and every token collapses — on Windows (PHP_EOL === "\r\n") an
     * LF-terminated output produced no usable tokens at all.
     *
     * The CR-only case reproduces that failure mode on a Linux host, where
     * PHP_EOL is "\n".
     */
    #[Test]
    public function parsingIsIndependentOfLineEndings(): void
    {
        $flatten = fn(ParserResult $r) => array_map(
            fn($t) => [
                $t->getText(),
                $t->isWord(),
                $t->getSentenceIndex(),
                $t->getOrder(),
            ],
            $r->getTokens()
        );

        $lf = $this->parseOutput(implode("\n", self::MECAB_LINES) . "\n");
        $this->assertCount(6, $lf->getTokens());

        foreach (["\r\n" => 'CRLF', "\r" => 'CR-only'] as $eol => $label) {
            $result = $this->parseOutput(implode($eol, self::MECAB_LINES) . $eol);

            $this->assertSame(
                $flatten($lf),
                $flatten($result),
                "$label output should parse identically to LF"
            );
            $this->assertSame(
                $lf->getSentences(),
                $result->getSentences(),
                "$label output should yield the same sentences as LF"
            );
        }
    }

    #[Test]
    public function handlesEmptyOutput(): void
    {
        $result = $this->parseOutput('');

        $this->assertSame([], $result->getTokens());
    }
}
