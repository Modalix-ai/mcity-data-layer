"""שורת פקודה: build, search, report. הפלט (אינדקס ודוח) נכתב מחוץ למאגר."""
import argparse
import json
import os
import sqlite3
import statistics
import sys
import time

from . import index as ix

HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.abspath(os.path.join(HERE, "..", "..", ".."))
DOMAINS = os.path.join(REPO, "src", "migration", "mappings", "domains.json")


def _refuse_inside_repo(path):
    full = os.path.abspath(path)
    if full == REPO or full.startswith(REPO + os.sep):
        sys.exit("האינדקס והדוח מכילים נתונים אישיים ולא נכנסים למאגר. בחר תיקייה מחוץ ל-%s" % REPO)


def cmd_build(a):
    _refuse_inside_repo(a.out)
    os.makedirs(a.out, exist_ok=True)
    c = ix.build(os.path.join(a.migration_out, "articles.jsonl"), os.path.join(a.migration_out, "users.jsonl"),
                 os.path.join(a.out, "index.db"), a.domains, a.as_of, include_held=a.include_held)
    print(json.dumps(c, ensure_ascii=False))


def cmd_search(a):
    db = sqlite3.connect(os.path.join(a.out, "index.db"))
    rows, fb = ix.search(db, a.query, limit=a.limit, typ=a.type, city=a.city, mode=a.mode)
    for r in rows:
        print("%s | %-7s | %s | %s | %s" % (r[0], r[1], r[2], r[3], r[4]))
    if fb:
        print("(fallback ל-OR)")


def _keys(db, q, limit=10):
    rows, _ = ix.search(db, q, limit=limit)
    return [r[0] for r in rows]


def cmd_report(a):
    _refuse_inside_repo(a.out)
    db = sqlite3.connect(os.path.join(a.out, "index.db"))
    qs = json.load(open(os.path.join(HERE, "queries.json"), encoding="utf-8"))
    L = ["# דוח אב-טיפוס חיפוש (Q0)", ""]
    n_docs = db.execute("select count(*) from docs").fetchone()[0]
    by = dict(db.execute("select type, count(*) from docs group by type").fetchall())
    L += ["מסמכים באינדקס: %d (%s)" % (n_docs, ", ".join("%s %d" % kv for kv in sorted(by.items()))), ""]
    L += ["## 1. שוויון בין ניסוחים", "",
          "**כל התוצאות** = מספר המסמכים שנמצאו (לא רק 10 הראשונים). **רק ב-A / רק ב-B** = מסמכים שנמצאו בניסוח אחד בלבד. "
          "0 ו-0 = אותה קבוצת תוצאות. **חפיפה ב-10** = דמיון בין 10 התוצאות הראשונות (הסדר יכול להשתנות כי "
          "המילה המקורית מקבלת עדיפות קלה).", "",
          "| A | B | הערה | כל התוצאות A | כל התוצאות B | רק ב-A | רק ב-B | חפיפה ב-10 |", "|---|---|---|---:|---:|---:|---:|---:|"]
    for p in qs["pairs"]:
        fa, fb_ = set(_keys(db, p["a"], 100000)), set(_keys(db, p["b"], 100000))
        ka, kb = _keys(db, p["a"]), _keys(db, p["b"])
        ov = len(set(ka) & set(kb)) / max(1, len(set(ka) | set(kb)))
        L.append("| %s | %s | %s | %d | %d | %d | %d | %d%% |" % (
            p["a"], p["b"], p["note"], len(fa), len(fb_), len(fa - fb_), len(fb_ - fa), round(ov * 100)))
    L += ["", "## 2. תוצאות לדוגמה (לבדיקה ידנית ולהצגה ללקוח)", ""]
    times = []
    for q in qs["lists"]:
        t0 = time.perf_counter()
        rows, fb = ix.search(db, q, limit=5)
        times.append((time.perf_counter() - t0) * 1000)
        L.append("**%s**%s" % (q, " (fallback ל-OR)" if fb else ""))
        L.append("")
        if not rows:
            L.append("- אין תוצאות")
        for r in rows:
            L.append("- [%s] %s%s" % (r[1], r[2], (" , " + r[3]) if r[3] else ""))
        L.append("")
    L += ["## 3. עמידות (שאילתות מוזרות לא שוברות כלום)", ""]
    bad = 0
    for q in qs["robustness"]:
        try:
            rows, fb = ix.search(db, q, limit=3)
            L.append("- `%s` ← %d תוצאות" % (q.replace("`", "'"), len(rows)))
        except Exception as e:   # noqa: BLE001
            bad += 1
            L.append("- `%s` ← **שגיאה: %s**" % (q, e))
    L += ["", "שגיאות: %d" % bad, "", "## 4. זמני תגובה (על שאילתות הרשימה, מקומי)", ""]
    times.sort()
    L.append("חציון %.1f ms, אחוזון 95: %.1f ms, מקסימום %.1f ms (יעד ב-docs/11: מתחת ל-300 ms)" % (
        statistics.median(times), times[int(len(times) * 0.95) - 1], times[-1]))
    os.makedirs(a.out, exist_ok=True)
    path = os.path.join(a.out, "search_report.md")
    open(path, "w", encoding="utf-8").write("\n".join(L) + "\n")
    print("report:", path)


def main(argv=None):
    ap = argparse.ArgumentParser(prog="mcsearch")
    sub = ap.add_subparsers(dest="cmd", required=True)
    b = sub.add_parser("build")
    b.add_argument("--migration-out", required=True, help="תיקיית הפלט של mcimport (articles.jsonl, users.jsonl)")
    b.add_argument("--out", required=True)
    b.add_argument("--domains", default=DOMAINS)
    b.add_argument("--as-of", required=True, help="YYYYMMDD, לבדיקת סיום מנוי")
    b.add_argument("--include-held", action="store_true")
    b.set_defaults(fn=cmd_build)
    s = sub.add_parser("search")
    s.add_argument("query")
    s.add_argument("--out", required=True)
    s.add_argument("--limit", type=int, default=10)
    s.add_argument("--type")
    s.add_argument("--city")
    s.add_argument("--mode", default="auto", choices=("and", "auto"))
    s.set_defaults(fn=cmd_search)
    r = sub.add_parser("report")
    r.add_argument("--out", required=True)
    r.set_defaults(fn=cmd_report)
    a = ap.parse_args(argv)
    a.fn(a)


if __name__ == "__main__":
    main()
