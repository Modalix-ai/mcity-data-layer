"""כלי טקסט: ניקוי שמות, slug, ניקוי תוכן HTML, חילוץ קישורים, תאריכים."""
import datetime
import html
import re
from zoneinfo import ZoneInfo

TZ = ZoneInfo("Asia/Jerusalem")

_HEB_TO_LAT = {
    "א": "", "ב": "b", "ג": "g", "ד": "d", "ה": "h", "ו": "v", "ז": "z", "ח": "ch", "ט": "t", "י": "y",
    "כ": "k", "ך": "k", "ל": "l", "מ": "m", "ם": "m", "נ": "n", "ן": "n", "ס": "s", "ע": "", "פ": "p",
    "ף": "f", "צ": "ts", "ץ": "ts", "ק": "k", "ר": "r", "ש": "sh", "ת": "t",
}


def norm_name(s):
    """שם מנורמל להשוואה: בלי ישויות HTML, מרכאות אחידות, רווחים מכווצים."""
    s = html.unescape(s or "")
    s = s.replace("”", '"').replace("“", '"').replace("״", '"').replace("’", "'").replace("׳", "'")
    s = re.sub(r"\s+", " ", s).strip()
    return s


def strip_tags(s):
    s = re.sub(r"<[^>]+>", " ", s or "")
    s = html.unescape(s)
    return re.sub(r"\s+", " ", s).strip()


def slugify(title, policy="hebrew", max_len=80):
    t = norm_name(title).lower()
    if policy == "translit":
        t = "".join(_HEB_TO_LAT.get(ch, ch) for ch in t)
        t = re.sub(r"[^a-z0-9]+", "-", t)
    else:
        t = re.sub(r"[^\w֐-׿]+", "-", t)
        t = t.replace("_", "-")
    t = re.sub(r"-+", "-", t).strip("-")
    if len(t) > max_len:
        cut = t[:max_len]
        t = cut.rsplit("-", 1)[0] if "-" in cut else cut
    return t or "post"


def assign_slugs(pairs, reserved=(), policy="hebrew"):
    """pairs: [(legacy_id, title)] בסדר עולה של מזהה. מחזיר ({id: slug}, [התנגשויות])."""
    reserved = set(reserved)
    used = set()
    out, collisions = {}, []
    for lid, title in pairs:
        base = slugify(title, policy)
        slug = base
        reason = None
        if slug in reserved:
            slug = base + "-1"
            reason = "reserved"
        n = 2
        while slug in used:
            slug = "%s-%d" % (base, n)
            n += 1
            reason = reason or "duplicate"
        used.add(slug)
        out[lid] = slug
        if reason:
            collisions.append({"id": lid, "base": base, "slug": slug, "reason": reason})
    return out, collisions


def to_gmt(local_str):
    """'YYYY-MM-DD HH:MM:SS' בשעון ישראל -> אותו פורמט ב-UTC. ריק או 0000 -> None."""
    if not local_str or local_str.startswith("0000"):
        return None
    d = datetime.datetime.strptime(local_str[:19], "%Y-%m-%d %H:%M:%S").replace(tzinfo=TZ)
    return d.astimezone(datetime.timezone.utc).strftime("%Y-%m-%d %H:%M:%S")


# ---- תוכן ----
_SCRIPT_RE = re.compile(r"<script\b[^>]*>.*?</script>", re.I | re.S)
_UNSUPPORTED_RE = re.compile(r"swfobject|totemticker|fancybox", re.I)
_OBJECT_SWF_RE = re.compile(r"<object\b[^>]*>(?:(?!</object>).)*?\.swf(?:(?!</object>).)*?</object>", re.I | re.S)
_IMG_SRC_RE = re.compile(r"<img\b[^>]*?\bsrc\s*=\s*[\"']([^\"']+)[\"']", re.I)
_INTERNAL_ID_RE = re.compile(r"[?&]id=(\d{4,18})\b")
_YT_RE = re.compile(r"(?:youtube(?:-nocookie)?\.com/(?:embed/|watch\?v=)|youtu\.be/)([\w-]{11})", re.I)


def clean_content(raw):
    """הסרת רכיבים לא נתמכים (מסמך 10 סעיף 7). כל השאר נשמר כמות שהוא."""
    stats = {"scripts_removed": 0, "swf_removed": 0, "other_scripts": 0}
    s = raw or ""

    def _drop(m):
        if _UNSUPPORTED_RE.search(m.group(0)):
            stats["scripts_removed"] += 1
            return ""
        stats["other_scripts"] += 1
        return m.group(0)

    s = _SCRIPT_RE.sub(_drop, s)
    s, n = _OBJECT_SWF_RE.subn("", s)
    stats["swf_removed"] = n
    return s.strip(), stats


def extract_refs(content):
    imgs = _IMG_SRC_RE.findall(content or "")
    return {
        "inline_images": sorted({i for i in imgs if "/files_media/" in i}),
        "external_images": len([i for i in imgs if "/files_media/" not in i]),
        "internal_ids": sorted(set(_INTERNAL_ID_RE.findall(content or ""))),
        "youtube": sorted(set(_YT_RE.findall(content or ""))),
    }
