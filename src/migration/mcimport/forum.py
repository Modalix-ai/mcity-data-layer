"""פורום מומחים: שאלות ותשובות (מסמך 10 סעיף 8).

מחולק לדליים. כל מה שאינו הומר לכתבה ממתין להחלטת לקוח (policy.forum).
לא מועברים: אימייל, טלפון וכתובת של השואל (מידע אישי). שם השואל נשמר כפי שפורסם.
"""
from collections import Counter, defaultdict

from .textutil import norm_name, to_gmt


def _rows(db, sql):
    cur = db.execute(sql)
    cols = [c[0] for c in cur.description]
    return [dict(zip(cols, r)) for r in cur.fetchall()]


def build_forum(db, experts_by_id, article_legacy_ids, policy):
    """מחזיר (רשומות, סטטיסטיקה). רשומה לכל שאלה; התשובות מקוננות בתוכה."""
    stats = Counter()
    questions = _rows(db, "select id,user_name,title,content,expert,created,modified,active from rt_forum_questions order by id")
    answers = defaultdict(list)
    for a in _rows(db, "select id,question,expert,content,scale,created,modified,active from rt_forum_answers order by id"):
        answers[a["question"]].append(a)
    q_ids = {q["id"] for q in questions}
    stats["answers_orphan"] = sum(len(v) for k, v in answers.items() if k not in q_ids)
    links = defaultdict(list)
    for l in _rows(db, "select rt_articles_id,rt_forum_questions_id from rt_articles_forum_questions"):
        links[l["rt_forum_questions_id"]].append(l["rt_articles_id"])

    recs = []
    for q in questions:
        stats["questions"] += 1
        ans = [a for a in answers.get(q["id"], []) if a["active"] == 1]
        linked = sorted(set(links.get(q["id"], [])))
        linked_in_scope = [i for i in linked if i in article_legacy_ids]
        if linked:
            bucket = "converted"
        elif q["active"] != 1:
            bucket = "inactive"
        elif not ans:
            bucket = "unanswered"
        else:
            bucket = "published_unconverted"
        hold = []
        if bucket in ("published_unconverted", "unanswered") and policy.forum == "hold":
            hold.append("forum_policy")
        if bucket == "inactive":
            hold.append("question_inactive")
        flags = []
        if bucket == "converted" and not linked_in_scope:
            flags.append("article_not_migrated")
        if q["expert"] and q["expert"] not in experts_by_id:
            flags.append("expert_missing")
        out_answers = []
        for a in ans:
            e = a["expert"] if a["expert"] in experts_by_id else None
            if a["expert"] and e is None:
                stats["answers_expert_missing"] += 1
            out_answers.append({
                "legacy_id": a["id"], "expert_id": e, "content": a["content"], "scale": a["scale"],
                "created": (a["created"] or "")[:19], "created_gmt": to_gmt((a["created"] or "")[:19]),
            })
            stats["answers_active"] += 1
        stats["bucket_" + bucket] += 1
        for h in hold:
            stats["hold_" + h] += 1
        recs.append({
            "key": "forum:%d" % q["id"], "kind": "forum_question", "legacy_id": q["id"],
            "bucket": bucket, "title": norm_name(q["title"]), "content": q["content"],
            "asker_name": norm_name(q["user_name"]) or None,
            "expert_id": q["expert"] if q["expert"] in experts_by_id else None,
            "created": (q["created"] or "")[:19], "created_gmt": to_gmt((q["created"] or "")[:19]),
            "article_legacy_ids": linked, "answers": out_answers, "hold": hold, "flags": flags,
        })
    return recs, stats
