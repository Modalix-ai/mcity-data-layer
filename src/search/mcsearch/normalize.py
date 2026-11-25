"""נרמול עברית: אותו קוד באינדוקס ובשאילתה (docs/11, סעיף 5.2).

העיקרון: לעולם לא מחליפים מילה, רק מוסיפים וריאנטים. ככה "בחיפה" נשארת כמו שהיא,
ובנוסף מאונדקסת "חיפה". כך הדיוק לא נפגע וההשלמה משתפרת.
"""
import html
import re

NIQQUD_RE = re.compile("[֑-ֽֿ-ׇ]")   # ניקוד וטעמים, בלי מקף (U+05BE)
QUOTES_RE = re.compile("[׳״'\"’”“‘`]")
TAGS_RE = re.compile(r"<(script|style)\b.*?</\1>|<[^>]+>", re.S | re.I)
SHORTCODE_RE = re.compile(r"\[/?[a-z_][^\]]*\]", re.I)
TOKEN_RE = re.compile(r"[0-9A-Za-zא-ת]+")
FINALS = str.maketrans("ךםןףץ", "כמנפצ")

# ראשי תיבות נפוצים: מורחבים לפני הסרת הגרשיים (כדי ש"עו"ד" לא תהפוך ל"עוד")
ABBREVIATIONS = {
    "עו״ד": "עורך דין", "עוה״ד": "עורך דין", "רו״ח": "רואה חשבון", "רופ״א": "רופא",
    "ד״ר": "דוקטור", "פרופ׳": "פרופסור", "ת״א": "תל אביב", "פ״ת": "פתח תקווה",
    "ר״ג": "רמת גן", "ב״ש": "באר שבע", "ראשל״צ": "ראשון לציון", "בע״מ": "",
}
_ABBR_RE = re.compile("|".join(re.escape(k) for k in sorted(ABBREVIATIONS, key=len, reverse=True)))

STOPWORDS = {"של", "את", "על", "עם", "או", "גם", "כי", "אל", "מן", "זה", "זו", "הוא", "היא", "הם", "הן",
             "אני", "אנו", "לא", "כל", "יש", "אם", "מה", "מי", "עד", "בין", "כך", "אך", "רק", "עוד", "היה"}
STOPWORDS = {w.translate(FINALS) for w in STOPWORDS}

# ריבוי בלתי סדיר ומילון נרדפות (טבלה קטנה וניתנת לעריכה, ראו סעיף 5.2 סעיף 8)
SYNONYMS = {
    "עורכי": ["עורך"], "עורכות": ["עורך"], "רואי": ["רואה"], "רופאי": ["רופא"], "רופאות": ["רופא"],
    "רופאה": ["רופא"], "מורי": ["מורה"], "מורות": ["מורה"], "מטפלי": ["מטפל"], "מטפלות": ["מטפל"], "יועצי": ["יועץ"],
    "יועצות": ["יועצת", "יועץ"], "מתווכי": ["מתווך"], "מתווכים": ["מתווך"], "מנהלי": ["מנהל"],
    "משכנתא": ["משכנתה", "משכנתאות"], "משכנתאות": ["משכנתא"], "ביטוח": ["ביטוחים"],
}
SYNONYMS = {k.translate(FINALS): [v.translate(FINALS) for v in vs] for k, vs in SYNONYMS.items()}

_PREFIX_LETTERS = "הושכלבמ"


def strip_markup(text):
    """HTML, entities וקודי קיצור ← טקסט פשוט."""
    if not text:
        return ""
    t = html.unescape(text)
    t = TAGS_RE.sub(" ", t)
    t = SHORTCODE_RE.sub(" ", t)
    return html.unescape(t)


def clean(text):
    """ניקוד, ראשי תיבות, גרשיים, אותיות סופיות ואותיות קטנות ← טקסט מנורמל (עדיין מחרוזת)."""
    t = html.unescape(text or "")
    t = t.replace("־", " ").replace("‐", " ").replace("–", " ").replace("—", " ")
    t = NIQQUD_RE.sub("", t)
    t = t.replace('"', "״").replace("'", "׳").replace("”", "״").replace("“", "״").replace("’", "׳")
    t = _ABBR_RE.sub(lambda m: " " + ABBREVIATIONS[m.group(0)] + " ", t)
    t = QUOTES_RE.sub("", t)
    return t.lower().translate(FINALS)


def prefix_variants(tok):
    """וריאנטים בלי קידומת (ו)(ה/ש/כ/ל/ב/מ)(ה). רק למילים של 4 תווים ומעלה, ושארית של 3 ומעלה."""
    if len(tok) < 4 or not ("א" <= tok[0] <= "ת"):
        return []
    out = []
    i = 0
    if tok[i] == "ו":
        i += 1
        out.append(tok[i:])
    if i < len(tok) and tok[i] in _PREFIX_LETTERS:
        out.append(tok[i + 1:])
        if i + 1 < len(tok) and tok[i + 1] == "ה" and tok[i] != "ה":
            out.append(tok[i + 2:])
    if i == 0 and tok[0] == "ה":
        pass
    seen, res = set(), []
    for v in out:
        if len(v) >= 3 and v != tok and v not in seen:
            seen.add(v)
            res.append(v)
    return res


def suffix_variants(tok):
    """ריבוי (ים/ות) ונקבה (ת/ית) ← צורת הבסיס, כוריאנט. רק למילים ארוכות, ותמיד בנוסף למילה המקורית.
    נקבה: "עורכת" ← "עורכ" ("עורך"), "פסיכולוגית" ← "פסיכולוג". לא מורידים ה (כדי ש"חיפה" לא תהפוך ל"חיפ")."""
    if len(tok) >= 5 and (tok.endswith("ים") or tok.endswith("ות")):
        return [tok[:-2]]
    if len(tok) >= 6 and tok.endswith("ית"):
        return [tok[:-2], tok[:-1]]
    if len(tok) >= 5 and tok.endswith("ת"):
        return [tok[:-1]]
    return []


def variants(tok):
    """כל הוריאנטים של מילה מנורמלת (בלי המילה עצמה)."""
    res = []
    for v in prefix_variants(tok) + suffix_variants(tok) + SYNONYMS.get(tok, []):
        res.append(v)
        for w in suffix_variants(v) + SYNONYMS.get(v, []):
            res.append(w)
    seen, out = {tok}, []
    for v in res:
        if v not in seen:
            seen.add(v)
            out.append(v)
    return out


def tokens(text, drop_stopwords=True):
    """מילים מנורמלות, בלי וריאנטים."""
    toks = TOKEN_RE.findall(clean(text))
    if drop_stopwords:
        kept = [t for t in toks if t not in STOPWORDS]
        return kept or toks
    return toks


def index_text(text):
    """הטקסט שנכנס לאינדקס: כל מילה, ואחריה הוריאנטים שלה."""
    out = []
    for t in tokens(text):
        out.append(t)
        out.extend(variants(t))
    return " ".join(out)


def query_groups(query):
    """שאילתה ← רשימת קבוצות. כל קבוצה: המילה ואחריה וריאנטים (OR בתוך קבוצה, AND בין קבוצות)."""
    return [[t] + variants(t) for t in tokens(query)]
