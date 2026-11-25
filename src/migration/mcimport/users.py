"""משתמשים: מומחים (rt_forum_experts), חשבונות צוות וכתבים. לפי מסמך 10 סעיפים 4 ו-5.

כל רשומה כוללת hold (רשימת סיבות). רשומה עם hold לא ריק לא נכתבת לאתר עד שמישהו מכריע.
הפלט הוא קבצי ביניים בלבד. אין כאן סיסמאות ואין שליחת מיילים: הטוען יוצר סיסמה אקראית וכובה מיילי ברכה.
"""
import html
import re
from collections import Counter, defaultdict

from .config import RESERVED_SLUGS
from .phpser import as_list, decode_field
from .textutil import norm_name, slugify, strip_tags

# תחומי פורום שאינם מומחים אמיתיים (מסמך 10 סעיף 4: מעגלי תמיכה, mcity פנימי)
NON_EXPERT_FIELDS = {92, 99, 100}
REALESTATE_FIELDS = {96, 102}

SYSTEM_NAMES = {"mcity", "מערכת mcity", "דוברות", "מערכת", "mcity team"}
GUEST_NAMES = {"כתב אורח"}
MARKETING_WRITER_RE = re.compile(r"תוכן שיווקי|buypost|best links?|mega links|seo rush|אופיר כתבות תדמית|linkshak|לינקשייק", re.I)
AGENCY_WRITER_RE = re.compile(r"buypost|best links?|mega links|seo rush|אופיר כתבות תדמית|linkshak|לינקשייק", re.I)
_MKT_SUFFIX_RE = re.compile(r"\s*[-–]?\s*תוכן שיווקי\s*$|^\s*תוכן שיווקי\s*[-–]?\s*")
EMAIL_RE = re.compile(r"^[^@\s]+@[^@\s]+\.[^@\s]+$")

STAFF_ACCOUNTS = {
    "staff:mcity": "MCITY (מערכת, דוברות)",
    "staff:guest": "כתב אורח",
    "staff:marketing": "תוכן שיווקי",
    "staff:unknown": "כותב לא מזוהה",
}


def _all(db, sql, *a):
    cur = db.execute(sql, a)
    cols = [c[0] for c in cur.description]
    return [dict(zip(cols, r)) for r in cur.fetchall()]


def normalize_phone(raw):
    """ספרות בלבד. אם נשמט האפס המוביל מוסיפים אותו. מחזיר (ערך, דגל)."""
    digits = re.sub(r"\D", "", raw or "")
    if not digits:
        return "", None
    if digits.startswith("972"):
        return "0" + digits[3:], "intl_prefix"
    if not digits.startswith("0") and len(digits) in (8, 9):
        return "0" + digits, "leading_zero_added"
    return digits, None


def _ymd(date_text):
    m = re.match(r"^(\d{4})-(\d{2})-(\d{2})", date_text or "")
    return "".join(m.groups()) if m else None


def build_experts(db, domains_map, policy, as_of):
    """as_of: 'YYYYMMDD'. מחזיר (רשומות, סטטיסטיקה)."""
    subf = {r["id"]: r for r in _all(db, "select id,name,field from rt_forum_subfields")}
    fields = {r["id"]: r for r in _all(db, "select id,name from rt_forum_fields")}
    expiry = {r["forum_expert"]: _ymd(r["expiry"]) for r in _all(db, "select forum_expert,expiry from rt_forum_experts_expiry_date")}
    extra = {r["forum_expert_id"]: r for r in _all(db, "select * from rt_minisite_additional_fields")}
    rows = _all(db, "select * from rt_forum_experts order by id")

    # קבוצות אימייל
    groups = defaultdict(list)
    for r in rows:
        e = (r["emails"] or "").strip().lower()
        if e:
            groups[e].append(r["id"])

    stats = Counter()
    recs = []
    nick_pairs = []
    for r in rows:
        d, _err = decode_field(r["data"])
        d = d if isinstance(d, dict) else {}
        hold, flags = [], []
        rid = r["id"]

        # --- תחומים ---
        primary = r["field"] if r["field"] in subf else None
        sub_ids = [primary] if primary else []
        for s in as_list(d.get("fields")):
            if s.isdigit() and int(s) in subf and int(s) not in sub_ids:
                sub_ids.append(int(s))
        cats = []
        for s in sub_ids:
            sf = subf[s]
            f = fields.get(sf["field"], {})
            cats.append({"subfield_id": s, "subfield": norm_name(sf["name"]), "field_id": sf["field"], "field": norm_name(f.get("name", ""))})
        parent = cats[0]["field_id"] if cats else None
        if parent in NON_EXPERT_FIELDS:
            hold.append("not_an_expert")
        if parent in REALESTATE_FIELDS and policy.realestate_experts == "hold":
            hold.append("realestate_expert")
        if primary is None:
            flags.append("no_field")

        # --- ערים (domains של המומחה) ---
        cities, national = [], False
        for x in as_list(d.get("domains")):
            if not x.isdigit():
                continue
            m = domains_map.get(int(x), {})
            if m.get("kind") == "city":
                cities.append(m["slug"])
            elif m.get("kind") == "national":
                national = True
        cities = sorted(set(cities))
        if len(cities) == 14:
            flags.append("all_14_cities")

        # --- אימייל ---
        e = (r["emails"] or "").strip().lower()
        if not e:
            email_status, email_out = "missing", ""
        elif not EMAIL_RE.match(e):
            email_status, email_out = "invalid", ""
        elif len(groups[e]) == 1:
            email_status, email_out = "unique", e
        else:
            email_status, email_out = "shared", ""
            if policy.shared_email == "keep_first_active":
                active_ids = [i for i in groups[e] if next(x for x in rows if x["id"] == i)["active"] == 1]
                if active_ids and rid == min(active_ids):
                    email_out = e
            if not email_out:
                flags.append("email_pending")

        # --- מנוי ופעילות ---
        end = expiry.get(rid)
        expired = bool(end) and end < as_of
        active = 1 if r["active"] == 1 else 0
        if not active and policy.inactive_experts == "hold":
            hold.append("inactive_expert")
        if expired and policy.expired_experts == "hold":
            hold.append("expired_subscription")
        if end and end >= "20300101":
            flags.append("expiry_2030plus")

        # --- פרטים ---
        phone, pflag = normalize_phone(d.get("phone") or r["phones"])
        if pflag:
            flags.append("phone_" + pflag)
        m_extra = extra.get(rid, {})
        bio = (m_extra.get("odot_text") or d.get("minisite_about") or "").strip() or None
        short_desc = html.unescape(strip_tags(r["description"])) if r["description"] else None
        if not bio and short_desc:
            flags.append("bio_missing_short_only")
        minisite = {k[len("minisite_"):]: v for k, v in d.items()
                    if k.startswith("minisite_") and v not in ("", None, 0, "0")}
        for k in ("headline_1", "headline_2", "headline_3", "odot_headline"):
            if m_extra.get(k):
                minisite[k] = m_extra[k]

        city_id = d.get("city")
        address = (d.get("address") or "").strip()
        rec = {
            "key": "expert:%d" % rid,
            "kind": "expert",
            "legacy_id": rid,
            "role": "author",
            "login": "mc_expert_%d" % rid,
            "display_name": norm_name(r["name"]),
            "first_name": norm_name(r["real_name"]) or None,
            "last_name": norm_name(r["real_surname"]) or None,
            "email": email_out,
            "email_status": email_status,
            "email_group_size": len(groups[e]) if e else 0,
            "mc_active": str(active),
            "mc_subscription_end": end,
            "profession": cats[0]["subfield"] if cats else None,
            "phone": phone,
            "address": address or None,
            "address_fallback_city_id": city_id if (not address and city_id) else None,
            "url_business_card": (d.get("link_out") or "").strip() or None,
            "facebook": (d.get("facebook") or "").strip() or None,
            "bio": bio,
            "short_description": short_desc,
            "author_categories": cats,
            "cities": cities,
            "national": national,
            "avatar_file": r["image"] or None,
            "minisite": minisite or None,
            "hold": hold,
            "flags": flags,
        }
        recs.append(rec)
        nick_pairs.append((rid, rec["display_name"]))
        stats["experts"] += 1
        stats["email_" + email_status] += 1
        for h in hold:
            stats["hold_" + h] += 1
        if expired:
            stats["expired"] += 1
        if rec["hold"] == []:
            stats["ready"] += 1

    # nicename (קבוע לפי מדיניות slug, ייחודי)
    used, reserved = set(), set(RESERVED_SLUGS) | {"mcity-team", "mcity-editor"} | {v["slug"] for v in domains_map.values() if v.get("slug")}
    for rec in recs:
        base = slugify(rec["display_name"], policy.slug_policy, 60)
        s, n = base, 2
        while s in used or s in reserved:
            s = "%s-%d" % (base, n)
            n += 1
        used.add(s)
        rec["nicename"] = s
    return recs, stats


def classify_writer(name):
    n = norm_name(name)
    low = n.lower()
    if low in SYSTEM_NAMES:
        return "system"
    if n in GUEST_NAMES:
        return "guest"
    if MARKETING_WRITER_RE.search(n):
        return "marketing"
    return "writer"


def marketing_kind(name):
    """agency = קידום קישורים של סוכנות. expert = כתבה בשם מומחה משלם ('<שם> - תוכן שיווקי')."""
    return "agency" if AGENCY_WRITER_RE.search(norm_name(name)) else "expert"


def build_staff_and_writers(db, policy, experts_by_id=None):
    """מחזיר (רשומות משתמש, אינדקס: writer_id -> (author_key, סוג), סטטיסטיקה)."""
    counts = Counter()
    for r in _all(db, "select writer, count(*) c from rt_articles group by writer"):
        counts[str(r["writer"]).strip()] += r["c"]
    writers = {str(w["id"]): w for w in _all(db, "select id,name,role,active from rt_writers")}

    recs = [{"key": k, "kind": "staff", "role": "editor", "display_name": v, "hold": [], "flags": ["map_to_existing_user"]}
            for k, v in STAFF_ACCOUNTS.items()]
    index, stats = {}, Counter()
    by_name = defaultdict(list)
    for wid, w in writers.items():
        n_art = counts.get(wid, 0)
        kind = classify_writer(w["name"])
        if kind == "system":
            index[wid] = ("staff:mcity", kind)
        elif kind == "guest":
            index[wid] = ("staff:guest", kind)
        elif kind == "marketing":
            mk = marketing_kind(w["name"])
            key = "staff:marketing"
            if mk == "expert":
                base = _MKT_SUFFIX_RE.sub("", norm_name(w["name"])).strip()
                cands = [r["key"] for r in (experts_by_id or {}).values() if r["display_name"] == base]
                if len(cands) == 1:
                    key = cands[0]
                else:
                    stats["marketing_expert_unmatched"] += 1
            index[wid] = (key, "marketing_" + mk)
        elif n_art == 0:
            stats["writers_skipped_no_articles"] += 1
            continue
        elif policy.writer_policy == "staff":
            index[wid] = ("staff:mcity", "writer_as_staff")
        else:
            key = "writer:%s" % wid
            index[wid] = (key, "writer")
            recs.append({"key": key, "kind": "contributor", "legacy_id": int(wid), "role": "contributor",
                         "login": "mc_writer_%s" % wid, "display_name": norm_name(w["name"]), "email": "",
                         "hold": [], "flags": []})
            by_name[norm_name(w["name"]).lower()].append(int(wid))
        stats["writers_" + kind] += 1
    dups = {n: ids for n, ids in by_name.items() if len(ids) > 1}
    stats["writer_dup_name_groups"] = len(dups)
    stats["writer_dup_name_records"] = sum(len(v) for v in dups.values())
    stats["articles_writer_deleted"] = sum(c for wid, c in counts.items() if wid not in writers and wid not in ("", "0"))
    stats["articles_writer_empty"] = sum(c for wid, c in counts.items() if wid in ("", "0"))
    return recs, index, stats, dups
