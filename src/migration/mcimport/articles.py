"""כתבות (rt_articles) -> רשומות ביניים. לפי מסמך 10 סעיפים 2, 3, 6, 7.

עקרונות:
- עיר מגיעה מ-domains של הכתבה. דומיין אזורי -> עיר. בלי דומיין אזורי -> ארצי (דומיין 9).
  רק דומיינים מוחרגים (6, 17, 18) או בלי דומיין -> לא מועברת (נספרת בדוח בלבד).
- נושא מגיע מהמדורים (sections.py).
- כל מה שממתין להכרעה נכנס ל-hold. הטוען לא כותב רשומה עם hold.
- תמונות לא מטופלות כאן: נרשמים שם הקובץ והתמונות בתוך התוכן.
"""
import html
from collections import Counter

from .config import RESERVED_SLUGS
from .phpser import as_list, decode_field
from .sections import classify_all
from .textutil import assign_slugs, clean_content, extract_refs, norm_name, strip_tags, to_gmt
from .users import classify_writer


def load_sections(db, overrides=None):
    cur = db.execute("select id,name,name2,active,forum_expert_id,domains from rt_sections")
    cols = [c[0] for c in cur.description]
    secs = []
    for row in cur.fetchall():
        d = dict(zip(cols, row))
        d["name"] = html.unescape(d["name"] or "")
        d["domains"] = [int(x) for x in as_list(decode_field(d["domains"])[0]) if str(x).isdigit()]
        secs.append(d)
    return secs, classify_all(secs, overrides)


def _ints(raw):
    return [int(x) for x in as_list(decode_field(raw)[0]) if str(x).isdigit()]


def build_articles(db, domains_map, sec_cls, secs_by_id, experts_by_id, writer_index, policy, limit=None, only_ids=None):
    """מחזיר (רשומות, סטטיסטיקה, התנגשויות slug)."""
    stats = Counter()
    recs = []
    city_slugs = {v["slug"] for v in domains_map.values() if v.get("slug")}
    q = "select id,headline,content,writer,sections,domains,image,url,title,description,data,created,modified,active,forum_expert_id from rt_articles order by id"
    cur = db.execute(q)
    cols = [c[0] for c in cur.description]
    for row in cur:
        a = dict(zip(cols, row))
        if only_ids and a["id"] not in only_ids:
            continue
        if limit and stats["seen"] >= limit:
            break
        stats["seen"] += 1

        # --- חתך שנים (הלקוח): לפני השנה מוסרות, לפני כל בדיקה אחרת ---
        created, modified = (a["created"] or "")[:19], (a["modified"] or "")[:19]
        year = int(created[:4]) if created[:4].isdigit() else None
        if policy.cutoff_year and year and year < policy.cutoff_year and policy.pre_cutoff == "exclude":
            stats["excluded_before_cutoff"] += 1
            continue

        # --- ערים ---
        dom_ids = _ints(a["domains"])
        regional = sorted({domains_map[d]["slug"] for d in dom_ids if domains_map.get(d, {}).get("kind") == "city"})
        national = any(domains_map.get(d, {}).get("kind") == "national" for d in dom_ids)
        if not regional and not national:
            stats["not_migrated_" + ("excluded_domain" if dom_ids else "no_domain")] += 1
            continue
        hold, flags = [], []
        scope = "city" if regional else "national"
        if len(regional) == len(city_slugs) and regional:
            flags.append("all_regions")

        # --- מדורים ונושאים ---
        sec_ids = _ints(a["sections"])
        kinds = [sec_cls[s]["kind"] for s in sec_ids if s in sec_cls]
        topics = sorted({sec_cls[s]["topic"] for s in sec_ids if s in sec_cls and sec_cls[s].get("topic")})
        legacy_sections = [{"id": s, "name": norm_name(secs_by_id[s]["name"]), "kind": sec_cls[s]["kind"]}
                           for s in sec_ids if s in sec_cls]
        sec_expert = next((sec_cls[s]["expert_id"] for s in sec_ids if s in sec_cls and sec_cls[s].get("expert_id")), None)
        if any(sec_cls[s].get("sub_area") for s in sec_ids if s in sec_cls):
            flags.append("news_sub_area_merged")
        if "unreviewed" in kinds:
            flags.append("section_unreviewed")
        if not sec_ids:
            flags.append("no_section")
        excluded_kinds = {"realestate", "elections", "junk"}
        if kinds and all(k in excluded_kinds for k in kinds) and policy.section_exclusion == "hold":
            hold.append("section_excluded")
        marketing_sec = any(sec_cls[s]["kind"] == "marketing" and sec_cls[s]["confidence"] == "auto" for s in sec_ids if s in sec_cls)
        marketing_sec_proposed = any(sec_cls[s]["kind"] == "marketing" and sec_cls[s]["confidence"] == "proposed" for s in sec_ids if s in sec_cls)
        if not topics and not sec_expert:
            flags.append("no_topic")
        if len(topics) > 1:
            flags.append("multi_topic")

        # --- כותב ---
        wid = str(a["writer"]).strip()
        w_key, w_kind = writer_index.get(wid, ("staff:unknown", "deleted"))
        author_key, author_src = w_key, "writer"
        if a["forum_expert_id"] and experts_by_id.get(a["forum_expert_id"], {}).get("removed"):
            flags.append("author_record_removed")       # חשבון פנימי או בדיקה שהוסר: הכתבה נחתמת על הכותב שלה
        elif a["forum_expert_id"] and a["forum_expert_id"] in experts_by_id:
            author_key, author_src = "expert:%d" % a["forum_expert_id"], "article_expert"
        elif a["forum_expert_id"]:
            flags.append("article_expert_missing")
        if author_src == "writer" and sec_expert:
            if sec_expert in experts_by_id and experts_by_id[sec_expert].get("removed"):
                flags.append("section_points_to_removed")
            elif sec_expert in experts_by_id and "not_an_expert" in experts_by_id[sec_expert]["hold"]:
                # מדור שמצביע לרשומה פנימית (למשל 'מעגל mcity') הוא טור של כותב, לא של מומחה
                flags.append("section_points_to_non_expert")
            elif sec_expert in experts_by_id:
                author_key, author_src = "expert:%d" % sec_expert, "section_expert"
            else:
                flags.append("section_expert_missing")
        if author_key.startswith("expert:") and experts_by_id[int(author_key.split(":")[1])]["hold"]:
            hold.append("author_expert_held")
        w_mkt = w_kind.startswith("marketing_")
        marketing = marketing_sec or w_mkt
        # כתבה שיווקית עם קרדיט של מומחה או חברה = expert (נשארת). בלי קרדיט = agency (מוסרת)
        mkt_kind = None
        if marketing:
            # קרדיט (הלקוח, 24.11): שיווקי עם מומחה או חברה בקרדיט נשאר, בלי קרדיט מוסר. קרדיט = מומחה מקושר, או כותב בשם מומחה/חברה
            credited = author_src in ("article_expert", "section_expert") or w_kind == "marketing_expert"
            if credited:
                mkt_kind = "expert"
            elif w_kind == "marketing_agency_kept":
                mkt_kind = "agency_kept"      # הלקוח (24.11): 6 סוכנויות נשארות גם בלי קרדיט; חתומות 'תוכן שיווקי'
            else:
                mkt_kind = "agency"
            act = {"expert": policy.marketing_expert, "agency_kept": policy.marketing_agency_kept}.get(mkt_kind, policy.marketing_agency)
            if act == "exclude":
                stats["excluded_marketing_" + mkt_kind] += 1
                continue
            if act == "hold":
                hold.append("marketing_" + mkt_kind)
            flags.append("marketing_" + mkt_kind)
            stats["marketing_%s_%s" % (mkt_kind, "section" if marketing_sec else "writer")] += 1

        if marketing_sec_proposed and not marketing:
            hold.append("marketing_section_unconfirmed")
            flags.append("marketing_section_proposed")

        # --- תאריכים וסטטוס ---
        if policy.cutoff_year and year and year < policy.cutoff_year:
            hold.append("before_cutoff")
        status = "publish" if a["active"] == 1 else "draft"

        # --- תוכן ---
        content, cstats = clean_content(a["content"])
        refs = extract_refs(content)
        for k, v in cstats.items():
            stats["content_" + k] += v
        if refs["inline_images"]:
            flags.append("inline_images")
        if refs["youtube"]:
            flags.append("youtube")
        data, _ = decode_field(a["data"])
        data = data if isinstance(data, dict) else {}
        credit = (data.get("image_text") or "").strip() or None
        readmore = [int(x) for x in (data.get("readmore") or {}).values() if str(x).isdigit()] if isinstance(data.get("readmore"), dict) else []
        title = norm_name(a["headline"]) or norm_name(a["title"])
        seo_title = norm_name(a["title"]) if norm_name(a["title"]) != title else None
        if not title:
            hold.append("no_title")

        rec = {
            "key": "article:%d" % a["id"],
            "kind": "article",
            "legacy_id": a["id"],
            "legacy_url": a["url"],
            "title": title,
            "seo_title": seo_title,
            "excerpt": strip_tags(a["description"]) or None,
            "content": content,
            "status": status,
            "post_date": created,
            "post_date_gmt": to_gmt(created),
            "post_modified": modified,
            "post_modified_gmt": to_gmt(modified),
            "scope": scope,
            "cities": regional,
            "topics": topics,
            "legacy_sections": legacy_sections,
            "author": author_key,
            "author_source": author_src,
            "image_credit": credit,
            "hide_date": data.get("time_display") is False,
            "related_legacy_ids": readmore,
            "image_file": a["image"] or None,
            "refs": refs,
            "marketing_kind": mkt_kind,
            "hold": hold,
            "flags": flags,
        }
        recs.append(rec)
        stats["migrated"] += 1
        stats["status_" + status] += 1
        stats["scope_" + scope] += 1
        stats["author_" + author_src] += 1
        for h in hold:
            stats["hold_" + h] += 1
        for f in flags:
            stats["flag_" + f] += 1
        if not hold:
            stats["ready"] += 1

    slugs, collisions = assign_slugs([(r["legacy_id"], r["title"]) for r in recs],
                                     RESERVED_SLUGS | city_slugs, policy.slug_policy)
    for r in recs:
        r["slug"] = slugs[r["legacy_id"]]
    stats["slug_collisions"] = len(collisions)
    # קישורים פנימיים שמצביעים לכתבה שלא מועברת
    known = {r["legacy_id"] for r in recs}
    for r in recs:
        r["related_legacy_ids"] = [i for i in r["related_legacy_ids"] if i in known] if only_ids is None and limit is None else r["related_legacy_ids"]
    return recs, stats, collisions
