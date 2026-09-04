#!/usr/bin/env python3
"""Drive a real Anki collection, so the .apkg round trip is checked against Anki
rather than against LWT's own reader.

Why this exists
---------------
Every other test of the .apkg feature has LWT on both ends: LWT writes a file,
LWT reads it back, and the assertions pass because the two halves agree with
each other. They agreed perfectly while the round trip was broken. Anki 26.08
exports a zstd-compressed collection by default and leaves a one-note stub
behind for older clients; LWT read the stub, found one unrecognised note, and
reported a successful import. Nine thousand unit tests, a Cypress suite, Psalm
and phpcs were all green. Real Anki showed it in about a minute.

So this is the oracle: the one piece of the test suite that is not LWT talking
to itself. It is deliberately opt-in -- it needs Anki's Python library, which is
a large dependency nobody should have to install to run `composer test` -- and
`bin/lwt-apkg-oracle.php` drives it.

Setup
-----
    python3 -m venv .venv-anki
    .venv-anki/bin/pip install anki
    ANKI_PYTHON=.venv-anki/bin/python php bin/lwt-apkg-oracle.php

Commands
--------
    check       Report whether the Anki library is importable, and its version.
    roundtrip   Import an .apkg into a fresh collection, optionally answer a
                card, and export it again. Everything about how -- which import
                and export options -- is a flag, because the options are the
                thing being tested: Anki's defaults are what break the loop.

Exit codes: 0 success, 3 Anki unavailable, 1 anything else.
"""

from __future__ import annotations

import argparse
import json
import os
import sys
import tempfile
import time

ANKI_MISSING = 3


def _load_anki():
    """Import Anki, or exit 3 saying how to get it.

    `anki.collection` has to be imported before `anki.scheduler.v3`, or the two
    deadlock on a circular import; importing Collection first is what keeps
    that from biting anyone who extends this file.
    """
    try:
        from anki.collection import Collection  # noqa: F401
        import anki.buildinfo
    except Exception as exc:  # pragma: no cover - environment dependent
        print(
            "anki python library not importable (%s).\n"
            "Install it in a virtualenv:\n"
            "    python3 -m venv .venv-anki && .venv-anki/bin/pip install anki\n"
            "then re-run with ANKI_PYTHON=.venv-anki/bin/python." % exc,
            file=sys.stderr,
        )
        raise SystemExit(ANKI_MISSING)
    return anki.buildinfo.version


def cmd_check(_args: argparse.Namespace) -> int:
    print(_load_anki())
    return 0


def _collection_state(col) -> dict:
    """Everything the PHP side wants to assert about, in one JSON-able dict."""
    notes = []
    for nid in col.find_notes(""):
        note = col.get_note(nid)
        cards = []
        for card in note.cards():
            cards.append(
                {
                    "ord": card.ord,
                    "type": card.type,
                    "queue": card.queue,
                    "ivl": card.ivl,
                    "reps": card.reps,
                    "lapses": card.lapses,
                    "due": card.due,
                    "revlog": col.db.scalar(
                        "select count() from revlog where cid = ?", card.id
                    )
                    or 0,
                }
            )
        notes.append(
            {
                "guid": note.guid,
                "fields": list(note.fields),
                "tags": list(note.tags),
                "cards": cards,
            }
        )
    notes.sort(key=lambda n: n["guid"])
    return {
        "notes": notes,
        "note_count": len(notes),
        "revlog_count": col.db.scalar("select count() from revlog") or 0,
    }


def _answer_one(col, ease: int) -> dict:
    """Answer the first card Anki offers, at the given ease.

    Two things here are not optional. The deck has to be selected first: the
    scheduler serves the *current* deck, which after an import is still Default
    while every imported card sits in the imported deck, so `getCard()` returns
    None and the check looks like a scheduling bug rather than a missing line.
    And `start_timer()` has to run before `answerCard`, which reads the elapsed
    time and raises a TypeError against an unstarted card.
    """
    deck_id = col.db.scalar("select did from cards order by id limit 1")
    if deck_id:
        col.decks.select(deck_id)

    card = col.sched.getCard()
    if card is None:
        return {"answered": False, "reason": "nothing due"}
    card.start_timer()
    col.sched.answerCard(card, ease)
    return {
        "answered": True,
        "ease": ease,
        "card_id": card.id,
        "guid": col.get_note(card.nid).guid,
        "at": int(time.time()),
    }


def _set_due_date(col, days: str) -> dict:
    """Push every card out by hand, the way "Set due date" does in the browser.

    This writes a revlog row with no grade on it -- ease 0, kind Manual -- which
    is the case LWT has to tell apart from a real answer: replaying it as a
    grade would apply a review the learner never gave, and ignoring it loses a
    date they deliberately chose.
    """
    card_ids = list(col.find_cards(""))
    col.sched.set_due_date(card_ids, days)
    return {
        "cards": len(card_ids),
        "days": days,
        "at": int(time.time()),
        "manual_revlog_rows": col.db.scalar(
            "select count() from revlog where ease = 0 and type in (4, 5)"
        )
        or 0,
    }


def cmd_roundtrip(args: argparse.Namespace) -> int:
    _load_anki()
    from anki.collection import Collection
    from anki.import_export_pb2 import (
        ExportAnkiPackageOptions,
        ImportAnkiPackageOptions,
        ImportAnkiPackageRequest,
    )

    report: dict = {
        "import_with_scheduling": args.import_scheduling,
        "export_legacy": args.legacy,
        "export_with_scheduling": args.export_scheduling,
    }

    workdir = tempfile.mkdtemp(prefix="lwt_anki_oracle_")
    col_path = os.path.join(workdir, "collection.anki2")
    col = Collection(col_path)
    try:
        col.import_anki_package(
            ImportAnkiPackageRequest(
                package_path=args.input,
                options=ImportAnkiPackageOptions(
                    merge_notetypes=True,
                    update_notes=0,
                    update_notetypes=0,
                    with_scheduling=args.import_scheduling,
                    with_deck_configs=False,
                ),
            )
        )
        report["after_import"] = _collection_state(col)

        if args.answer:
            report["answer"] = _answer_one(col, args.answer)
            report["after_answer"] = _collection_state(col)

        if args.set_due_date:
            report["set_due_date"] = _set_due_date(col, args.set_due_date)
            report["after_set_due_date"] = _collection_state(col)

        col.export_anki_package(
            out_path=args.output,
            options=ExportAnkiPackageOptions(
                with_scheduling=args.export_scheduling,
                with_deck_configs=False,
                with_media=False,
                legacy=args.legacy,
            ),
            limit=None,
        )
        report["output_bytes"] = os.path.getsize(args.output)
    finally:
        col.close()

    if args.report:
        with open(args.report, "w", encoding="utf-8") as handle:
            json.dump(report, handle, indent=2)
    else:
        json.dump(report, sys.stdout, indent=2)
        print()
    return 0


def main(argv: list[str]) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest="command", required=True)

    sub.add_parser("check", help="report the Anki library version").set_defaults(
        func=cmd_check
    )

    rt = sub.add_parser("roundtrip", help="import, optionally answer, re-export")
    rt.add_argument("--in", dest="input", required=True, help="source .apkg")
    rt.add_argument("--out", dest="output", required=True, help="destination .apkg")
    rt.add_argument("--report", help="write the JSON report here instead of stdout")
    rt.add_argument(
        "--answer",
        type=int,
        default=0,
        metavar="EASE",
        help="answer one card at this ease (1 Again .. 4 Easy)",
    )
    rt.add_argument(
        "--set-due-date",
        metavar="DAYS",
        default="",
        help='push every card out by hand, as Anki\'s "Set due date" does',
    )
    rt.add_argument(
        "--no-import-scheduling",
        dest="import_scheduling",
        action="store_false",
        help="import the way Anki does by default, dropping the schedule",
    )
    rt.add_argument(
        "--no-export-scheduling",
        dest="export_scheduling",
        action="store_false",
        help="export without scheduling information",
    )
    rt.add_argument(
        "--modern",
        dest="legacy",
        action="store_false",
        help="export the way Anki does by default: zstd, schema 18",
    )
    rt.set_defaults(
        func=cmd_roundtrip, import_scheduling=True, export_scheduling=True, legacy=True
    )

    args = parser.parse_args(argv)
    return args.func(args)


if __name__ == "__main__":
    raise SystemExit(main(sys.argv[1:]))
