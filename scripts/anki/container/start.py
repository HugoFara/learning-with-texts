"""Start headless Anki with one ready profile, so AnkiConnect comes up unattended.

A first launch would stop at Anki's language and profile dialogs, which nobody
can answer offscreen. Creating the profile beforehand through Anki's own
ProfileManager skips them; after that this is a plain `anki -b BASE -p PROFILE`.
"""

from __future__ import annotations

import os
import sys
from pathlib import Path

BASE = Path(os.environ.get("ANKI_BASE", "/home/anki/anki"))
PROFILE = os.environ.get("ANKI_PROFILE", "User 1")


def ensure_profile() -> None:
    if (BASE / "prefs21.db").exists():
        return

    from aqt.profiles import ProfileManager

    manager = ProfileManager(BASE)
    manager.setupMeta()
    manager.setLang("en_US")
    manager.create(PROFILE)


def set_scheduler() -> None:
    """Switch FSRS on or off when ANKI_FSRS says so (1 / 0); leave it alone otherwise.

    Anki's default is off, and AnkiConnect cannot change it, so tests of the
    FSRS case set it here, before Anki holds the collection open.
    """
    wanted = os.environ.get("ANKI_FSRS")
    if wanted not in ("0", "1"):
        return

    from anki.collection import Collection

    (BASE / PROFILE).mkdir(parents=True, exist_ok=True)
    col = Collection(str(BASE / PROFILE / "collection.anki2"))
    try:
        col.set_config("fsrs", wanted == "1")
    finally:
        col.close()


def main() -> None:
    BASE.mkdir(parents=True, exist_ok=True)
    ensure_profile()
    set_scheduler()

    import aqt

    sys.argv = ["anki", "-b", str(BASE), "-p", PROFILE]
    aqt.run()


if __name__ == "__main__":
    main()
