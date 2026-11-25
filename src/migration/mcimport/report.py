"""דוח הרצה: מרקדאון אחד שנותן לאנשים לראות מה הועבר, מה ממתין, ולמה."""
import datetime
from collections import Counter


def _table(rows, headers):
    out = ["| " + " | ".join(headers) + " |", "|" + "|".join("---" for _ in headers) + "|"]
    for r in rows:
        out.append("| " + " | ".join(str(x) for x in r) + " |")
    return "\n".join(out)


def _stat_rows(stats, prefix):
    return [(k[len(prefix):], "{:,}".format(v)) for k, v in sorted(stats.items()) if k.startswith(prefix)]


def build_report(policy, as_of, sec_cls, secs_by_id, sec_article_counts, stat_users, stat_writers, stat_articles,
                 stat_forum, slug_collisions, writer_dups, outputs):
    L = []
    L.append("# דוח ייבוא MCity")
    L.append("")
    L.append("נוצר: %s | תאריך חישוב מנויים: %s" % (datetime.datetime.now().strftime("%Y-%m-%d %H:%M"), as_of))
    L.append("")
    L.append("## מדיניות שהופעלה")
    L.append(_table([(k, v) for k, v in vars(policy).items()], ["פרמטר", "ערך"]))
    L.append("")
    L.append("רשומה עם hold לא נכתבת לאתר. כל hold הוא החלטה פתוחה, לא שגיאה.")
    L.append("")

    L.append("## כתבות")
    s = stat_articles
    L.append(_table([
        ("נקראו מהדאמפ", "{:,}".format(s["seen"])),
        ("הועברו לביניים", "{:,}".format(s["migrated"])),
        ("מוכנות לכתיבה (בלי hold)", "{:,}".format(s["ready"])),
        ("לא מועברות: דומיין מוחרג בלבד", "{:,}".format(s["not_migrated_excluded_domain"])),
        ("לא מועברות: בלי דומיין", "{:,}".format(s["not_migrated_no_domain"])),
        ("מתפרסמות / טיוטה", "{:,} / {:,}".format(s["status_publish"], s["status_draft"])),
        ("עירוניות / ארציות", "{:,} / {:,}".format(s["scope_city"], s["scope_national"])),
        ("התנגשויות slug", "{:,}".format(s["slug_collisions"])),
    ], ["נושא", "כמות"]))
    L.append("")
    L.append("### הוסרו לפי החלטת הלקוח (לא נכתבות, לא ממתינות)")
    exc = _stat_rows(s, "excluded_") + [("פורום: " + k, v) for k, v in _stat_rows(stat_forum, "excluded_")]
    L.append(_table(exc, ["סיבה", "כמות"]))
    L.append("")
    L.append("### hold (ממתין להכרעה)")
    L.append(_table(_stat_rows(s, "hold_"), ["סיבה", "כתבות"]))
    L.append("")
    L.append("תוכן שיווקי: " + ", ".join("%s=%s" % (k[10:], "{:,}".format(v)) for k, v in sorted(s.items()) if k.startswith("marketing_")))
    L.append("(agency = קידום קישורים של סוכנויות; expert = כתבות בשם מומחה משלם, רובן שאלות ותשובות)")
    L.append("")
    L.append("### דגלים (לידיעה, לא עוצרים)")
    L.append(_table(_stat_rows(s, "flag_"), ["דגל", "כתבות"]))
    L.append("")
    L.append("### מקור הכותב")
    L.append(_table(_stat_rows(s, "author_"), ["מקור", "כתבות"]))
    L.append("")

    L.append("## מדורים")
    by = {}
    for sid, c in sec_cls.items():
        k = (c["kind"], c["confidence"])
        b = by.setdefault(k, [0, 0])
        b[0] += 1
        b[1] += sec_article_counts.get(sid, 0)
    L.append(_table([(k[0], k[1], v[0], "{:,}".format(v[1])) for k, v in sorted(by.items())],
                    ["סוג", "ודאות", "מדורים", "קישורי כתבות"]))
    L.append("")
    unrev = sorted(((sid, c) for sid, c in sec_cls.items() if c["kind"] == "unreviewed"),
                   key=lambda x: -sec_article_counts.get(x[0], 0))
    L.append("### מדורים שלא סווגו (%d). לסיווג: להוסיף שורה ב-mappings/section_overrides.csv" % len(unrev))
    L.append(_table([(sid, secs_by_id[sid]["name"], sec_article_counts.get(sid, 0)) for sid, _ in unrev], ["מזהה", "שם", "כתבות"]))
    L.append("")
    prop = [(sid, secs_by_id[sid]["name"], c["kind"], c["reason"]) for sid, c in sec_cls.items() if c["confidence"] == "proposed"]
    L.append("### סיווגים שהם הצעה ודורשים אישור (%d)" % len(prop))
    L.append(_table(prop, ["מזהה", "שם", "סוג", "סיבה"]))
    L.append("")

    L.append("## מומחים")
    u = stat_users
    L.append(_table([
        ("סה\"כ", u["experts"]), ("מוכנים (בלי hold)", u["ready"]),
        ("לא פעילים", u["hold_inactive_expert"]), ("מנוי שפג", u["hold_expired_subscription"]),
        ("לא מומחים (תחום 92/99/100)", u["hold_not_an_expert"]), ("נדל\"ן", u["hold_realestate_expert"]),
        ("אימייל ייחודי", u["email_unique"]), ("אימייל משותף (מרוקן)", u["email_shared"]),
        ("אימייל חסר או פגום", u["email_missing"] + u["email_invalid"]),
    ], ["נושא", "כמות"]))
    L.append("")
    L.append("## כותבים")
    L.append(_table([(k, "{:,}".format(v)) for k, v in sorted(stat_writers.items())], ["נושא", "כמות"]))
    L.append("")
    L.append("כותבים עם שם כפול (לא מאוחדים, לבדיקה): %d קבוצות" % len(writer_dups))
    L.append("")
    L.append("## פורום")
    L.append(_table(_stat_rows(stat_forum, "bucket_") + _stat_rows(stat_forum, "hold_")
                    + [("תשובות פעילות", stat_forum["answers_active"]), ("תשובות יתומות", stat_forum["answers_orphan"]),
                       ("תשובות של מומחה שלא קיים", stat_forum["answers_expert_missing"])], ["נושא", "כמות"]))
    L.append("")
    L.append("## קבצי פלט")
    L.append(_table([(k, "{:,}".format(v)) for k, v in outputs.items()], ["קובץ", "שורות"]))
    L.append("")
    if slug_collisions:
        L.append("## התנגשויות slug (דוגמאות)")
        L.append(_table([(c["id"], c["slug"], c["reason"]) for c in slug_collisions[:15]], ["כתבה", "slug", "סיבה"]))
    return "\n".join(L) + "\n"
