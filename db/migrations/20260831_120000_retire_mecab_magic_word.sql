-- Retire the MECAB magic word from LgRegexpWordCharacters (issue #288).
--
-- LgRegexpWordCharacters is the field that should hold a word-characters
-- regex. It could instead hold the literal MECAB, which said two unrelated
-- things at once: tokenize this language with MeCab, and this language has no
-- spaces between its words. Both now have a field that means them --
-- LgParserType and LgRemoveSpaces -- so the marker can hand its two jobs over
-- and give the field back to the regex it was always supposed to hold.
--
-- Order matters: the first two statements select on the marker, so the one
-- that overwrites it has to come last.
--
-- Every statement is idempotent. Each is restricted to rows that still hold
-- the marker, so a second run matches nothing.
--
-- The readers were converted before this file was written. Anything that
-- chooses a tokenizer now asks LgParserType through ParserSelection, falling
-- back to the marker only for a row this migration has not reached; anything
-- that asks about spacing reads LgRemoveSpaces through WordSpacing. Clearing
-- the field without that would have left MeCab languages silently parsed by
-- the standard regex tokenizer -- the text would still open, and every word in
-- it would be wrong.

-- 1. The parser choice. The 20251223 backfill already wrote this for rows that
--    had no parser type at the time; this also covers a row created since, or
--    one whose type was cleared, and makes the file self-contained.
UPDATE languages
SET LgParserType = 'mecab'
WHERE UPPER(TRIM(LgRegexpWordCharacters)) = 'MECAB'
  AND COALESCE(LgParserType, '') = '';

-- 2. The spacing fact, which nothing else recorded. A MeCab language is
--    Japanese, written without spaces between words: the marker was the only
--    thing saying so, and five sites in SentenceService asked it.
UPDATE languages
SET LgRemoveSpaces = 1
WHERE UPPER(TRIM(LgRegexpWordCharacters)) = 'MECAB';

-- 3. A real regex back in the field. This is the value langdefs.json gives the
--    Japanese preset, so a migrated language ends up matching a freshly
--    created one: CJK ideographs, compatibility ideographs, hiragana and
--    katakana, and the katakana phonetic extensions. The backslashes are
--    doubled because MySQL drops an unrecognised escape, and \x would arrive
--    as a bare x.
UPDATE languages
SET LgRegexpWordCharacters =
    '\\x{4E00}-\\x{9FFF}\\x{F900}-\\x{FAFF}\\x{3040}-\\x{30FF}\\x{31F0}-\\x{31FF}'
WHERE UPPER(TRIM(LgRegexpWordCharacters)) = 'MECAB';
