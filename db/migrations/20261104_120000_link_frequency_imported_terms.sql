-- Link the terms a starter-vocabulary import created to the occurrences they
-- match.
--
-- FrequencyImportService inserted its words with WoWordCount at 0, "not
-- counted yet", and never touched word_occurrences. Text parsing only links
-- terms counted as 1, so the words stayed unknown in every text, those parsed
-- after the import included. This is the same failure as dictionary imports
-- (see 20260830_120000_link_dictionary_imported_terms.sql).
--
-- The import fixes its own rows now. This repairs the installs that already
-- ran it. The counts are filled in by Migrations::computeMissingWordCounts(),
-- which runs after this file, because they come from each language's parsing
-- rules. Linking does not need them, so the order does not matter here.
--
-- Narrowed to languages holding a term that was never counted. The join cannot
-- use an index on word_occurrences, and on an install with nothing to repair
-- this touches no occurrence at all.
--
-- Single-word occurrences only, and no user filter, for the reasons given in
-- the dictionary migration.

UPDATE word_occurrences o
JOIN words w
  ON LOWER(o.Ti2Text) = w.WoTextLC AND o.Ti2LgID = w.WoLgID
SET o.Ti2WoID = w.WoID
WHERE o.Ti2WoID IS NULL
  AND o.Ti2WordCount = 1
  AND o.Ti2LgID IN (SELECT DISTINCT WoLgID FROM words WHERE WoWordCount = 0);
