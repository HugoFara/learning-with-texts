-- Link the terms a dictionary import created to the occurrences they match
-- (issue #283).
--
-- createVocabularyFromEntries() inserted one words row per dictionary entry
-- but never touched word_occurrences. The reader decides a word is unknown
-- from Ti2WoID rather than from whether the term exists, so every imported
-- term was invisible to it: the word stayed unmarked, and adding it from the
-- reading view collided with the unique index on (WoTextLC, WoLgID) --
-- HTTP 500 from /terms/full, and a silent no-op from /terms/quick. Because an
-- import covers essentially the whole language, that was more or less every
-- word in a text.
--
-- The import links its own rows now. This repairs the installs that already
-- ran it.
--
-- The terms themselves also need their WoWordCount, which those imports left
-- at 0 -- "not counted yet" rather than a count, matched by neither the
-- reader's WoWordCount = 1 lookup nor expression matching's > 1. That cannot
-- be done here: the count comes from each language's parsing rules, MeCab
-- included. Migrations::computeMissingWordCounts() runs after this file for
-- exactly that reason.
--
-- Narrowed to languages that actually hold a local dictionary. The join cannot
-- use an index -- word_occurrences has no lowercased column, so LOWER(Ti2Text)
-- is computed per row -- and on a large install the unrestricted form would
-- scan the whole occurrence table to fix nothing. Ti2WoID IS NULL keeps it to
-- rows that need it either way.
--
-- Single-word occurrences only: a multi-word occurrence is an expression, and
-- is matched by its own mechanism rather than through Ti2WoID.
--
-- Nothing here filters by user, and it does not need to -- the join pins
-- occurrence and term to the same LgID, and a languages row has exactly one
-- owner.

UPDATE word_occurrences o
JOIN words w
  ON LOWER(o.Ti2Text) = w.WoTextLC AND o.Ti2LgID = w.WoLgID
SET o.Ti2WoID = w.WoID
WHERE o.Ti2WoID IS NULL
  AND o.Ti2WordCount = 1
  AND o.Ti2LgID IN (SELECT LdLgID FROM local_dictionaries);
