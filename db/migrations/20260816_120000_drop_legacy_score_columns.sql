-- Drop the legacy Leitner scoring columns (issue #238, phase 2b).
--
-- words.WoTodayScore / WoTomorrowScore / WoRandom were a cache of a formula
-- over WoStatus and WoStatusChanged: base(status) - decay(status) * days, with
-- WoRandom shuffling terms that tied. Everything that read them now reads the
-- term's due date instead -- term_schedule.TsDue where a term has been graded,
-- and the same status interval the legacy formula implied where it has not, so
-- no term changes when it goes (see ScheduleSql).
--
-- Nothing here is recoverable from the columns themselves, and nothing needs to
-- be: the values were derived, never entered. A term's schedule after this
-- migration is exactly what it was before it.
--
-- The daily UPDATE that recomputed all three across the whole words table on
-- the first request after midnight goes with them.

ALTER TABLE words
    DROP COLUMN IF EXISTS WoTodayScore,
    DROP COLUMN IF EXISTS WoTomorrowScore,
    DROP COLUMN IF EXISTS WoRandom;
