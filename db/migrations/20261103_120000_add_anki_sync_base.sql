-- What LWT and Anki agreed on at the last sync, per term (#264, step 3).
--
-- Syncing fields, tags and suspension between two apps that both edit them is
-- a three-way merge: a side whose value differs from this row changed it. A
-- term deleted in LWT, or a note deleted in Anki, shows up as a row whose
-- other half is gone, which is why there is deliberately no foreign key to
-- words: the row has to outlive its term for one sync.
--
-- A NULL field means "never agreed", as on the first sync of a pair that was
-- already different: it stays a reported conflict until the two sides match.
-- Tags are stored space-separated and sorted, as Anki stores them.

CREATE TABLE IF NOT EXISTS anki_sync_base (
    AsWoID int(10) unsigned NOT NULL,
    AsUsID int(10) unsigned DEFAULT NULL,
    AsNoteID bigint(20) NOT NULL,
    AsCardID bigint(20) NOT NULL,
    AsTranslation varchar(500) DEFAULT NULL,
    AsRomanization varchar(100) DEFAULT NULL,
    AsNotes varchar(1000) DEFAULT NULL,
    AsTags text DEFAULT NULL,
    AsSuspended tinyint(1) DEFAULT NULL,
    -- The status a term had before an Anki suspension made it "ignored", so
    -- unsuspending can give it back.
    AsRestoreStatus tinyint(4) DEFAULT NULL,
    AsSyncedAt datetime NOT NULL,
    PRIMARY KEY (AsWoID),
    -- Per user: two learners may import the same exported deck, note ids and all.
    UNIQUE KEY AsUsIDNoteID (AsUsID, AsNoteID),
    KEY AsNoteID (AsNoteID)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
