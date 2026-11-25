"""python -m mcimport run --db old.db --out DIR [--as-of YYYYMMDD] [--limit N] [--policy k=v ...] [--only users,articles,forum]

קורא את הדאמפ (SQLite, קריאה בלבד) וכותב קבצי JSONL + דוח. לא נוגע באתר.
הפלט מכיל מידע אישי ולכן חייב להיות מחוץ לריפו.
"""
import argparse
import datetime
import hashlib
import json
import os
import sqlite3
import sys
from collections import Counter

from . import __version__
from .articles import build_articles, load_sections
from .config import Policy, load_domains, load_overrides
from .forum import build_forum
from .report import build_report
from .textutil import norm_name
from .users import build_experts, build_staff_and_writers

REPO_ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__)))))


def _write_jsonl(path, rows):
    h = hashlib.sha256()
    with open(path, "w", encoding="utf-8", newline="\n") as f:
        for r in rows:
            line = json.dumps(r, ensure_ascii=False, sort_keys=True) + "\n"
            f.write(line)
            h.update(line.encode("utf-8"))
    return h.hexdigest()


def run(db_path, out_dir, as_of=None, limit=None, policy=None, only=None, overrides_path=None, domains_path=None):
    policy = policy or Policy()
    out_abs = os.path.abspath(out_dir)
    if out_abs == REPO_ROOT or out_abs.startswith(REPO_ROOT + os.sep):
        raise SystemExit("הפלט מכיל מידע אישי ולכן אסור שיהיה בתוך הריפו: " + out_abs)
    only = set(only or ["users", "articles", "forum"])
    as_of = as_of or datetime.date.today().strftime("%Y%m%d")
    os.makedirs(out_abs, exist_ok=True)

    db = sqlite3.connect("file:%s?mode=ro" % db_path, uri=True)
    dm = load_domains(domains_path)
    secs, sec_cls = load_sections(db, load_overrides(overrides_path))
    secs_by_id = {s["id"]: s for s in secs}

    experts, st_users = build_experts(db, dm, policy, as_of)
    experts_by_id = {r["legacy_id"]: r for r in experts}
    staff_writers, widx, st_writers, wdups = build_staff_and_writers(db, policy)
    arts, st_art, collisions = build_articles(db, dm, sec_cls, secs_by_id, experts_by_id, widx, policy, limit=limit)
    art_ids = {a["legacy_id"] for a in arts}
    forum, st_forum = build_forum(db, experts_by_id, art_ids, policy)

    sec_counts = Counter()
    for (raw,) in db.execute("select sections from rt_articles"):
        from .articles import _ints
        for s in _ints(raw):
            sec_counts[s] += 1

    sec_rows = []
    for sid, c in sorted(sec_cls.items()):
        row = dict(c, id=sid, name=secs_by_id[sid]["name"].strip(), articles=sec_counts.get(sid, 0))
        if c["kind"] == "unreviewed":
            n = norm_name(row["name"])
            # רמז בלבד: התאמת שם למומחה. לא מופעל אוטומטית כי יש שמות כפולים.
            row["name_match_expert_ids"] = sorted(i for i, e in experts_by_id.items()
                                                  if e["display_name"] and (e["display_name"] == n or n.startswith(e["display_name"] + " ")))
        sec_rows.append(row)
    db.close()
    files = {}
    if "users" in only:
        files["users.jsonl"] = experts + staff_writers
    if "articles" in only:
        files["articles.jsonl"] = arts
    if "forum" in only:
        files["forum.jsonl"] = forum
    files["sections.jsonl"] = sec_rows

    hashes, counts = {}, {}
    for name, rows in files.items():
        hashes[name] = _write_jsonl(os.path.join(out_abs, name), rows)
        counts[name] = len(rows)
    rep = build_report(policy, as_of, sec_cls, secs_by_id, sec_counts, st_users, st_writers, st_art, st_forum,
                       collisions, wdups, counts)
    with open(os.path.join(out_abs, "report.md"), "w", encoding="utf-8") as f:
        f.write(rep)
    manifest = {"version": __version__, "as_of": as_of, "limit": limit, "policy": vars(policy), "sha256": hashes, "counts": counts}
    with open(os.path.join(out_abs, "manifest.json"), "w", encoding="utf-8") as f:
        json.dump(manifest, f, ensure_ascii=False, indent=2, sort_keys=True)
    return manifest, rep


def main(argv=None):
    ap = argparse.ArgumentParser(prog="mcimport")
    sub = ap.add_subparsers(dest="cmd", required=True)
    r = sub.add_parser("run")
    r.add_argument("--db", required=True)
    r.add_argument("--out", required=True)
    r.add_argument("--as-of")
    r.add_argument("--limit", type=int)
    r.add_argument("--policy", action="append", default=[])
    r.add_argument("--only")
    m = sub.add_parser("images-manifest", help="רשימת קבצי התמונות הצפויים מהדאמפ (CSV)")
    m.add_argument("--db", required=True)
    m.add_argument("--out", required=True, help="נתיב קובץ CSV")
    c = sub.add_parser("images-check", help="בדיקת נוכחות מול תיקיית תמונות (לא קורא ולא ממיר)")
    c.add_argument("--folder", required=True)
    c.add_argument("--manifest", required=True)
    c.add_argument("--report")
    a = ap.parse_args(argv)
    if a.cmd == "images-manifest":
        from . import images
        rows = images.build_manifest(a.db)
        images.write_manifest(rows, a.out)
        print("קבצים צפויים: {:,} -> {}".format(len(rows), a.out))
        return 0
    if a.cmd == "images-check":
        from . import images
        text = images.format_check(images.check_folder(a.folder, images.read_manifest(a.manifest)))
        if a.report:
            with open(a.report, "w", encoding="utf-8") as f:
                f.write(text)
        print(text)
        return 0
    manifest, _ = run(a.db, a.out, a.as_of, a.limit, Policy.from_pairs(a.policy), a.only.split(",") if a.only else None)
    print(json.dumps(manifest["counts"], ensure_ascii=False))
    print("report:", os.path.join(os.path.abspath(a.out), "report.md"))
    return 0


if __name__ == "__main__":
    sys.exit(main())
