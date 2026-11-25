import json
import os
import sqlite3
import sys
import tempfile
import unittest

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))
from mcsearch import index as ix  # noqa: E402

DOMAINS = os.path.join(os.path.dirname(__file__), "..", "..", "migration", "mappings", "domains.json")


def _write(path, rows):
    with open(path, "w", encoding="utf-8") as f:
        for r in rows:
            f.write(json.dumps(r, ensure_ascii=False) + "\n")


def _art(i, title, body, cities=("haifa",), status="publish", hold=(), author="staff:mcity"):
    return {"key": "article:%d" % i, "status": status, "hold": list(hold), "title": title, "content": "<p>%s</p>" % body,
            "excerpt": "", "author": author, "cities": list(cities), "slug": "s%d" % i, "post_date": "2020-01-01 10:00:00",
            "legacy_sections": [], "topics": []}


def _exp(i, name, prof, active="1", end="20300101", hold=(), kind="expert"):
    return {"key": "expert:%d" % i, "kind": kind, "display_name": name, "profession": prof, "hold": list(hold),
            "mc_active": active, "mc_subscription_end": end, "cities": ["modiin"], "author_categories": [],
            "short_description": "", "bio": "", "nicename": "e%d" % i}


class IndexTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        d = self.tmp.name
        _write(os.path.join(d, "articles.jsonl"), [
            _art(1, "פתיחת מרפאה בחיפה", "המרפאה החדשה נפתחה"),
            _art(2, "כתבה על ירושלים", "אין קשר", cities=("jerusalem",)),
            _art(3, "כתבה מוחזקת על חיפה", "x", hold=["author_expert_held"]),
            _art(4, "טיוטה על חיפה", "x", status="draft"),
            _art(5, "מה עורכי הדין אומרים", "ייעוץ משפטי"),
        ])
        _write(os.path.join(d, "users.jsonl"), [
            {"key": "staff:mcity", "kind": "staff", "display_name": "מערכת"},
            _exp(10, "דנה כהן", "עורכת דין"),
            _exp(11, "יוסי לוי", "עורך דין", active="0"),
            _exp(12, "רונית", "עורכת דין", end="20200101"),
            _exp(13, "אבי", "עורך דין", hold=["not_an_expert"]),
            _exp(14, "מעגל מודיעין", "", active="1", kind="circle"),
        ])
        self.db_path = os.path.join(d, "index.db")
        self.counts = ix.build(os.path.join(d, "articles.jsonl"), os.path.join(d, "users.jsonl"), self.db_path, DOMAINS, "20261125")
        self.db = sqlite3.connect(self.db_path)

    def tearDown(self):
        self.db.close()
        self.tmp.cleanup()

    def keys(self, q, **kw):
        return [r[0] for r in ix.search(self.db, q, **kw)[0]]

    def test_counts_and_filters(self):
        self.assertEqual((self.counts["article"], self.counts["expert"], self.counts["circle"]), (2 + 1, 1, 1))
        self.assertEqual(self.counts["skipped_hold"], 2)          # מוחזקת וטיוטה
        self.assertEqual(self.counts["skipped_hidden_expert"], 3)  # לא פעיל, פג, מוחזק

    def test_prefix_match_returns_same_set(self):
        self.assertEqual(set(self.keys("חיפה")), set(self.keys("בחיפה")))
        self.assertIn("article:1", self.keys("חיפה"))
        self.assertNotIn("article:3", self.keys("חיפה"))          # מוחזקת
        self.assertNotIn("article:4", self.keys("חיפה"))          # טיוטה

    def test_hidden_experts_never_appear(self):
        k = self.keys("עורך דין")
        self.assertIn("expert:10", k)
        for hidden in ("expert:11", "expert:12", "expert:13"):
            self.assertNotIn(hidden, k)

    def test_abbreviation_and_plural(self):
        self.assertEqual(set(self.keys("עו\"ד")), set(self.keys("עורך דין")))
        self.assertIn("article:5", self.keys("עורך דין"))

    def test_type_and_city_filters(self):
        self.assertEqual(self.keys("חיפה", typ="expert"), [])
        self.assertEqual(self.keys("כתבה", city="jerusalem"), ["article:2"])

    def test_fallback_to_or_and_strict_mode(self):
        rows, fb = ix.search(self.db, "מרפאה ירושלים")
        self.assertTrue(fb)
        self.assertEqual({r[0] for r in rows}, {"article:1", "article:2"})
        rows, fb = ix.search(self.db, "מרפאה ירושלים", mode="and")
        self.assertEqual((rows, fb), ([], False))

    def test_robustness(self):
        for q in ("", "   ", "'; drop table docs; --", '"', "OR AND NOT", "(((", "a*", "😀"):
            ix.search(self.db, q)                                 # לא זורק
        self.assertEqual(self.db.execute("select count(*) from docs").fetchone()[0], 5)


if __name__ == "__main__":
    unittest.main()
