"""בניית אינדקס FTS5 מפלט ההגירה, וחיפוש בו. stdlib בלבד (sqlite3 עם FTS5)."""
import json
import os
import re
import sqlite3
import time

from . import normalize as n

WEIGHTS = (10.0, 6.0, 4.0, 3.0, 1.0)   # title, author, extra, lead, body
SCHEMA = """
create table docs(id integer primary key, key text unique, type text, title text, slug text,
                  author text, cities text, date text, snippet text);
create virtual table fts using fts5(title, author, extra, lead, body, tokenize='unicode61', content='');
"""


def read_jsonl(path):
    with open(path, encoding="utf-8") as f:
        for line in f:
            if line.strip():
                yield json.loads(line)


def load_city_names(domains_path):
    with open(domains_path, encoding="utf-8") as f:
        raw = json.load(f)
    names = {}
    for k, v in raw.items():
        if isinstance(v, dict) and v.get("slug") and v.get("kind") == "city":
            names[v["slug"]] = v["name"]
    return names


def expert_visible(u, as_of):
    """מומחה מופיע בחיפוש רק אם פעיל, לא מוחזק, והמנוי לא פג (docs/11, סעיף 5.3)."""
    if u.get("hold"):
        return False
    if str(u.get("mc_active")) not in ("1", "True", "true"):
        return False
    end = u.get("mc_subscription_end")
    return not end or str(end) >= as_of


def build(articles_path, users_path, out_path, domains_path, as_of, include_held=False):
    if os.path.exists(out_path):
        os.unlink(out_path)
    city_names = load_city_names(domains_path)
    users = {u["key"]: u for u in read_jsonl(users_path)}
    db = sqlite3.connect(out_path)
    db.executescript(SCHEMA)
    t0 = time.time()
    counts = {"article": 0, "expert": 0, "circle": 0, "skipped_hold": 0, "skipped_hidden_expert": 0}
    rid = 0

    def add(key, typ, title, slug, author, cities, date, snippet, extra, lead, body):
        nonlocal rid
        rid += 1
        db.execute("insert into docs values(?,?,?,?,?,?,?,?,?)",
                   (rid, key, typ, title, slug, author, ",".join(cities), date, snippet))
        db.execute("insert into fts(rowid,title,author,extra,lead,body) values(?,?,?,?,?,?)",
                   (rid, n.index_text(title), n.index_text(author), n.index_text(extra),
                    n.index_text(lead), n.index_text(body)))

    for u in users.values():
        if u["kind"] not in ("expert", "circle"):
            continue
        if not expert_visible(u, as_of) and not (u["kind"] == "circle" and not u.get("hold")):
            counts["skipped_hidden_expert"] += 1
            continue
        cats = u.get("author_categories") or []
        field_names = " ".join("%s %s" % (c.get("field", ""), c.get("subfield", "")) for c in cats if isinstance(c, dict))
        cities = u.get("cities") or []
        city_text = " ".join(city_names.get(c, c) for c in cities)
        prof = u.get("profession") or ""
        short = n.strip_markup(u.get("short_description") or "")
        add(u["key"], u["kind"], u["display_name"], u.get("nicename") or "", prof, cities, "", short[:160],
            "%s %s" % (field_names, city_text), short, n.strip_markup(u.get("bio") or ""))
        counts[u["kind"]] += 1

    for a in read_jsonl(articles_path):
        if a.get("status") != "publish" or (a.get("hold") and not include_held):
            counts["skipped_hold"] += 1
            continue
        author = (users.get(a.get("author")) or {}).get("display_name", "")
        sections = " ".join(s.get("name", "") for s in a.get("legacy_sections") or [])
        cities = a.get("cities") or []
        city_text = " ".join(city_names.get(c, c) for c in cities)
        topics = " ".join(a.get("topics") or [])
        body = n.strip_markup(a.get("content") or "")
        lead = n.strip_markup(a.get("excerpt") or "")
        add(a["key"], "article", a["title"], a.get("slug") or "", author, cities, (a.get("post_date") or "")[:10],
            (lead or body)[:160], "%s %s %s" % (sections, city_text, topics), lead, body)
        counts["article"] += 1
    db.commit()
    db.execute("insert into fts(fts) values('optimize')")
    db.commit()
    counts["seconds"] = round(time.time() - t0, 1)
    counts["size_mb"] = round(os.path.getsize(out_path) / 1e6, 1)
    db.close()
    return counts


def _fts_query(groups):
    def q(t):
        return '"%s"' % t.replace('"', "")
    parts = ["(" + " OR ".join(q(t) for t in g) + ")" for g in groups]
    return parts


def search(db, query, limit=10, typ=None, city=None, mode="auto"):
    """מחזיר (רשימת תוצאות, האם נעשה fallback ל-OR). שאילתה ריקה ← ריק."""
    groups = n.query_groups(query)
    if not groups:
        return [], False
    parts = _fts_query(groups)
    fallback = False
    for attempt in ("and", "or"):
        if attempt == "or" and (mode == "and" or len(parts) == 1):
            break
        match = (" AND " if attempt == "and" else " OR ").join(parts)
        sql = ("select d.key, d.type, d.title, d.author, d.cities, d.date, bm25(fts, %s) as score "
               "from fts join docs d on d.id = fts.rowid where fts match ?" % ",".join(str(w) for w in WEIGHTS))
        args = [match]
        if typ:
            sql += " and d.type = ?"
            args.append(typ)
        if city:
            sql += " and (',' || d.cities || ',') like ?"
            args.append("%%,%s,%%" % city)
        sql += " order by score limit ?"
        args.append(limit)
        rows = db.execute(sql, args).fetchall()
        if rows or mode == "and":
            return rows, fallback
        fallback = True
    return [], fallback
