"""תמונות: רשימת קבצים צפויה מהדאמפ, ובדיקת נוכחות מול תיקיית התמונות שתתקבל.

לא קורא, לא ממיר ולא מזיז תמונות. רק רשימה ובדיקה (os.walk + stat), כך שתיקייה של 10GB נבדקת בשניות.
הרשימה לא כוללת מידע אישי (שמות קבצים בלבד). ההמרה והעלאה לאתר יידונו אחרי שהמעצב יגדיר פורמטים וגדלים.
"""
import csv
import os
import re
import sqlite3
from collections import Counter, defaultdict

_IMG_SRC_RE = re.compile(r"<img\b[^>]*?\bsrc\s*=\s*[\"']([^\"']+)[\"']", re.I)
IMG_EXT = {".jpg", ".jpeg", ".png", ".gif", ".webp", ".bmp", ".jfif", ".svg", ".tif", ".tiff"}


def build_manifest(db_path):
    """מחזיר רשימת (קובץ, מקור, מספר שימושים). מקורות: article_main, article_inline, expert, forum_question, forum_answer."""
    db = sqlite3.connect("file:%s?mode=ro" % db_path, uri=True)
    uses = defaultdict(Counter)
    for src, sql in (("article_main", "select image from rt_articles"),
                     ("expert", "select image from rt_forum_experts"),
                     ("forum_question", "select image from rt_forum_questions"),
                     ("forum_answer", "select image from rt_forum_answers")):
        for (v,) in db.execute(sql):
            v = (v or "").strip()
            if v:
                uses[os.path.basename(v)][src] += 1
    for (c,) in db.execute("select content from rt_articles where content like '%<img%'"):
        for u in _IMG_SRC_RE.findall(c or ""):
            if "/files_media/" in u:
                uses[os.path.basename(u.split("?")[0])]["article_inline"] += 1
    db.close()
    return [(name, "+".join(sorted(c)), sum(c.values())) for name, c in sorted(uses.items())]


def write_manifest(rows, path):
    with open(path, "w", encoding="utf-8", newline="") as f:
        w = csv.writer(f)
        w.writerow(["file", "sources", "uses"])
        w.writerows(rows)


def read_manifest(path):
    with open(path, encoding="utf-8", newline="") as f:
        return [(r["file"], r["sources"], int(r["uses"])) for r in csv.DictReader(f)]


def check_folder(folder, manifest):
    """השוואת תיקייה למניפסט. הסריקה רקורסיבית, והתאמה לפי שם קובץ (גם בלי רגישות לאותיות)."""
    index = defaultdict(list)           # שם באותיות קטנות -> [נתיבים]
    total_bytes, n_files, zero, non_img = 0, 0, [], Counter()
    ext_bytes = Counter()
    for root, _dirs, files in os.walk(folder):
        for fn in files:
            p = os.path.join(root, fn)
            try:
                size = os.stat(p).st_size
            except OSError:
                continue
            n_files += 1
            total_bytes += size
            ext = os.path.splitext(fn)[1].lower()
            ext_bytes[ext] += size
            if size == 0:
                zero.append(p)
            if ext not in IMG_EXT:
                non_img[ext or "(בלי סיומת)"] += 1
            index[fn.lower()].append(p)
    found, missing, case_only, by_source = 0, [], 0, defaultdict(lambda: [0, 0])
    expected = set()
    for name, sources, _uses in manifest:
        expected.add(name.lower())
        hit = index.get(name.lower())
        for s in sources.split("+"):
            by_source[s][0] += 1
        if hit:
            found += 1
            if name not in [os.path.basename(h) for h in hit]:
                case_only += 1
        else:
            missing.append((name, sources))
            for s in sources.split("+"):
                by_source[s][1] += 1
    extra = [n for n in index if n not in expected]
    return {
        "files_in_folder": n_files, "bytes": total_bytes, "expected": len(manifest), "found": found,
        "missing": missing, "case_only_matches": case_only,
        "duplicates_by_name": sum(1 for v in index.values() if len(v) > 1),
        "zero_byte": zero, "non_image_ext": dict(non_img), "extra_not_in_manifest": len(extra),
        "by_source": {k: {"expected": v[0], "missing": v[1]} for k, v in by_source.items()},
        "bytes_by_ext": dict(ext_bytes.most_common(8)),
    }


def format_check(r):
    gb = r["bytes"] / 1024 ** 3
    L = ["# בדיקת תיקיית תמונות", "",
         "קבצים בתיקייה: {:,} ({:.2f} GB)".format(r["files_in_folder"], gb),
         "צפויים לפי הדאמפ: {:,} | נמצאו: {:,} | חסרים: {:,}".format(r["expected"], r["found"], len(r["missing"])),
         "בתיקייה ולא בדאמפ: {:,} | שמות כפולים בתת-תיקיות שונות: {:,} | אפס בייט: {:,} | התאמה רק באותיות: {:,}".format(
             r["extra_not_in_manifest"], r["duplicates_by_name"], len(r["zero_byte"]), r["case_only_matches"]), "",
         "## לפי מקור", "| מקור | צפויים | חסרים |", "|---|---|---|"]
    for k, v in sorted(r["by_source"].items()):
        L.append("| %s | {:,} | {:,} |".format(v["expected"], v["missing"]) % k)
    if r["non_image_ext"]:
        L += ["", "## קבצים שאינם תמונה", ", ".join("%s: %d" % kv for kv in r["non_image_ext"].items())]
    if r["missing"]:
        L += ["", "## חסרים (עד 30 ראשונים)"] + ["- %s (%s)" % m for m in r["missing"][:30]]
    return "\n".join(L) + "\n"
