"""סיווג מדורים ישנים (rt_sections) ליעד חדש. לפי מסמך 10 סעיף 3.

kind:
  topic          נושא קבוע מתשעת הנושאים (topic = slug)
  news           חדשות עיר או אזור: נושא news. sub_area=True לתת-אזור שמאבד פירוט (נשמר ב-legacy_section)
  consumption    צרכנות מקומית: נושא local-consumption
  expert         מדור על שם מומחה (forum_expert_id): לא נוצר מדור, מזהה את המומחה (expert_id)
  expert_dup     מדור נוסף של אותו מומחה (למשל 'שם - 2'): מתאחד איתו
  section        מדור נושא עניין שיישאר taxonomy section
  marketing      תוכן שיווקי או שותפים (מדיניות לקוח)
  realestate     נדל"ן (לא מועבר, הוכרע ברמת הדומיין והנושא)
  elections      בחירות (לא מועבר)
  junk           בדיקה או זבל
  unreviewed     לא סווג, נכתב לדוח לבדיקה ידנית (אפשר לסווג ב-section_overrides.csv)

confidence: auto (כלל ברור), proposed (הצעה שדורשת אישור), review (לא סווג).
"""
import re

from .textutil import norm_name

SUBAREA_IDS = {361, 427, 428, 429, 433, 444, 445, 449, 460, 474, 475, 476, 509, 539}

JUNK_NAMES = {"abc", "וו", "hvuro dkhkh", "מתכנת בודק", "לא שייך למדור"}

MARKETING_EXPLICIT = re.compile(r"תוכן שיווקי|buypost|best link|mega links|seo rush|linkshak|ofir", re.I)
MARKETING_PROPOSED = re.compile(r"פאי דיגיטל|לינקשייק|webclub|digital boost|גל ביז|zoomat|go top", re.I)

TOPIC_EXACT = {
    "גוף ונפש": ("body-and-soul", "auto"),
    "הורים וילדים": ("parents-and-children", "auto"),
    "יחסים": ("relationships", "auto"),
    "תרבות ופנאי": ("culture-and-leisure", "auto"),
    "תרבות ופנאי ראשון": ("culture-and-leisure", "auto"),
    "בריאות": ("body-and-soul", "proposed"),
}

INTEREST_NAMES = {
    "בית המשפט", "האיש שבא לסעוד", "חברה וקהילה", "מתכונים", "פרשת השבוע", "ספורט", "חינוך", "טבע",
    "טיולים", "מורשת", "הורסקופ", "וידיאו", "כלכלה עסקים וקריירה", "סיפורים קצרים מהחיים",
    "מכתבים למערכת", "ביטוח", "בעלי חיים", "משכנתאות", "קלינאות תקשורת",
}

_REALESTATE_RE = re.compile(r"נדל[\"״”]?ן")
_DUP_SUFFIX_RE = re.compile(r"\s*[-,]?\s*2$")


def _row(kind, confidence, reason, **kw):
    d = {"kind": kind, "confidence": confidence, "reason": reason, "topic": None, "expert_id": None, "sub_area": False}
    d.update(kw)
    return d


def classify_one(sec, overrides=None):
    """sec: dict עם id, name, name2, active, forum_expert_id, domains (רשימת מספרים)."""
    sid = sec["id"]
    name = norm_name(sec.get("name"))
    fe = int(sec.get("forum_expert_id") or 0)
    doms = set(sec.get("domains") or [])

    if overrides and sid in overrides:
        o = overrides[sid]
        return _row(o["kind"], "auto", "override: " + (o.get("note") or ""),
                    topic=o.get("topic") or None, expert_id=int(o["expert_id"]) if o.get("expert_id") else None)
    if name.lower() in JUNK_NAMES:
        return _row("junk", "auto", "שם בדיקה")
    if "בחירות" in name or (doms and doms <= {17}):
        return _row("elections", "auto", "בחירות")
    if _REALESTATE_RE.search(name) and fe == 0:
        return _row("realestate", "auto", "נדל\"ן")
    if MARKETING_EXPLICIT.search(name):
        return _row("marketing", "auto", "תוכן שיווקי לפי שם")
    if MARKETING_PROPOSED.search(name):
        return _row("marketing", "proposed", "שם שותף או קידום, לא וודאי")
    if name.startswith("חדשות") or name.startswith("תדשות"):
        return _row("news", "auto", "חדשות", topic="news", sub_area=sid in SUBAREA_IDS)
    if "צרכנות" in name:
        return _row("consumption", "auto", "צרכנות", topic="local-consumption")
    if name in TOPIC_EXACT:
        t, conf = TOPIC_EXACT[name]
        return _row("topic", conf, "שם זהה לנושא", topic=t)
    if fe > 0:
        return _row("expert", "auto", "forum_expert_id", expert_id=fe)
    if name in INTEREST_NAMES:
        return _row("section", "auto", "נושא עניין")
    return _row("unreviewed", "review", "לא סווג")


def classify_all(sections, overrides=None):
    """sections: רשימת dict. מחזיר {id: סיווג}. כולל איחוד מדורי '- 2' לאותו מומחה."""
    out = {s["id"]: classify_one(s, overrides) for s in sections}
    experts_by_name = [(norm_name(s["name"]), int(s["forum_expert_id"])) for s in sections if int(s.get("forum_expert_id") or 0) > 0]
    for s in sections:
        c = out[s["id"]]
        if c["kind"] != "unreviewed":
            continue
        name = norm_name(s["name"])
        base = _DUP_SUFFIX_RE.sub("", name).strip(" -,")
        if base == name or not base:
            continue
        cands = {fe for n, fe in experts_by_name if n == base or n.startswith(base + " ") or n.startswith(base + ",")}
        if len(cands) == 1:
            c.update(kind="expert_dup", confidence="auto", reason="מדור נוסף של אותו מומחה", expert_id=cands.pop())
    return out
