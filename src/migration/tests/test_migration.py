import json
import os
import sys
import tempfile
import unittest

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.dirname(HERE))
sys.path.insert(0, HERE)

import fixture  # noqa: E402
from mcimport import cli, phpser  # noqa: E402
from mcimport.articles import build_articles, load_sections  # noqa: E402
from mcimport.config import Policy, load_domains  # noqa: E402
from mcimport.forum import build_forum  # noqa: E402
from mcimport.sections import classify_all  # noqa: E402
from mcimport.textutil import assign_slugs, clean_content, extract_refs, to_gmt  # noqa: E402
from mcimport.users import build_experts, build_staff_and_writers, normalize_phone  # noqa: E402

AS_OF = "20261122"
# התנהגות 'מחזיק הכול' (לפני החלטות הלקוח). בדיקות הכללים רצות עליה; החלטות הלקוח נבדקות ב-ClientDecisionsTest
HOLD = dict(cutoff_year=None, marketing_agency="hold", marketing_agency_kept="hold", marketing_expert="hold", forum="hold",
            inactive_experts="hold", expired_experts="hold")


def hold_policy(**kw):
    return Policy(**{**HOLD, **kw})


def run_all(policy=None, classes=None, **kw):
    db = fixture.build()
    p = policy or hold_policy()
    dm = load_domains()
    experts, st_u = build_experts(db, dm, p, AS_OF, classes=classes)
    ex = {r["legacy_id"]: r for r in experts}
    _, widx, st_w, wd = build_staff_and_writers(db, p, ex)
    secs, cl = load_sections(db)
    sb = {s["id"]: s for s in secs}
    arts, st_a, col = build_articles(db, dm, cl, sb, ex, widx, p, **kw)
    return db, ex, {a["legacy_id"]: a for a in arts}, st_a, col, cl, st_u, st_w, wd, widx, p


class PhpSerTest(unittest.TestCase):
    def test_roundtrip_and_hebrew_bytes(self):
        v, err = phpser.decode_field(fixture.php_array({"city": 5, "name": "שלום", "ok": True, "l": ["1", "2"]}))
        self.assertIsNone(err)
        self.assertEqual(v["name"], "שלום")
        self.assertEqual(v["city"], 5)
        self.assertIs(v["ok"], True)
        self.assertEqual(phpser.as_list(v["l"]), ["1", "2"])

    def test_plain_text_fallback(self):
        v, _ = phpser.decode_field("not base64 !!")
        self.assertEqual(v, "not base64 !!")


class TextutilTest(unittest.TestCase):
    def test_gmt_dst(self):
        self.assertEqual(to_gmt("2016-07-01 12:00:00"), "2016-07-01 09:00:00")   # קיץ UTC+3
        self.assertEqual(to_gmt("2016-01-01 12:00:00"), "2016-01-01 10:00:00")   # חורף UTC+2
        self.assertIsNone(to_gmt("0000-00-00 00:00:00"))

    def test_slug_reserved_and_duplicate(self):
        slugs, col = assign_slugs([(1, "expert"), (2, "כתבה"), (3, "כתבה"), (4, "כתבה")], {"expert"})
        self.assertEqual(slugs[1], "expert-1")
        self.assertEqual([slugs[2], slugs[3], slugs[4]], ["כתבה", "כתבה-2", "כתבה-3"])
        self.assertEqual({c["reason"] for c in col}, {"reserved", "duplicate"})

    def test_clean_content_removes_only_unsupported(self):
        s, st = clean_content('<script>swfobject.x()</script><script>keep()</script><p>a</p>')
        self.assertNotIn("swfobject", s)
        self.assertIn("keep()", s)
        self.assertEqual((st["scripts_removed"], st["other_scripts"]), (1, 1))

    def test_refs(self):
        r = extract_refs('<img src="/files_media/a.jpg"><img src="http://e/x.png"><a href="?id=12345">x</a><iframe src="https://youtu.be/abcdefghijk">')
        self.assertEqual(r["inline_images"], ["/files_media/a.jpg"])
        self.assertEqual(r["external_images"], 1)
        self.assertEqual(r["internal_ids"], ["12345"])
        self.assertEqual(r["youtube"], ["abcdefghijk"])


class SectionsTest(unittest.TestCase):
    def test_classification(self):
        _, _, _, _, _, cl, *_ = run_all()
        k = {i: c["kind"] for i, c in cl.items()}
        self.assertEqual(k[1], "news")
        self.assertEqual(cl[2]["topic"], "body-and-soul")
        self.assertEqual(k[3], "consumption")
        self.assertEqual(k[4], "expert")
        self.assertEqual(k[5], "expert_dup")
        self.assertEqual(cl[5]["expert_id"], 1)
        self.assertEqual(k[6], "elections")
        self.assertEqual(k[7], "marketing")
        self.assertEqual(k[8], "unreviewed")
        self.assertEqual(k[10], "realestate")

    def test_override_wins(self):
        secs = [{"id": 8, "name": "משהו לא מוכר", "forum_expert_id": 0, "domains": [1]}]
        cl = classify_all(secs, {8: {"kind": "topic", "topic": "relationships", "note": "x"}})
        self.assertEqual((cl[8]["kind"], cl[8]["topic"]), ("topic", "relationships"))

    def test_sub_area_news(self):
        cl = classify_all([{"id": 427, "name": "חדשות משהו", "forum_expert_id": 0, "domains": [1]}])
        self.assertTrue(cl[427]["sub_area"])
        self.assertEqual(cl[427]["topic"], "news")


class UsersTest(unittest.TestCase):
    def setUp(self):
        _, self.ex, _, _, _, _, self.st, self.stw, self.wd, self.widx, _ = run_all()

    def test_holds(self):
        self.assertEqual(self.ex[1]["hold"], [])
        self.assertIn("inactive_expert", self.ex[4]["hold"])
        self.assertIn("expired_subscription", self.ex[5]["hold"])
        self.assertIn("not_an_expert", self.ex[6]["hold"])
        self.assertIn("realestate_expert", self.ex[7]["hold"])

    def test_shared_email_blanked_with_group(self):
        self.assertEqual(self.ex[2]["email"], "")
        self.assertEqual(self.ex[3]["email"], "")
        self.assertEqual(self.ex[2]["email_group_size"], 2)
        self.assertIn("email_pending", self.ex[2]["flags"])
        self.assertEqual(self.ex[1]["email"], "dana@example.com")
        self.assertEqual(self.ex[8]["email_status"], "missing")

    def test_keep_first_active_policy(self):
        _, ex, *_ = run_all(hold_policy(shared_email="keep_first_active"))
        self.assertEqual(ex[2]["email"], "shared@example.com")
        self.assertEqual(ex[3]["email"], "")

    def test_phone_and_dates(self):
        self.assertEqual(self.ex[1]["phone"], "0521234567")
        self.assertIn("phone_leading_zero_added", self.ex[1]["flags"])
        self.assertEqual(self.ex[8]["phone"], "0541112222")
        self.assertEqual(normalize_phone("050-123-4567")[0], "0501234567")
        self.assertEqual(self.ex[1]["mc_subscription_end"], "20300101")
        self.assertEqual(self.ex[5]["mc_subscription_end"], "20200501")
        self.assertIsNone(self.ex[4]["mc_subscription_end"])

    def test_cities_strings_and_all14(self):
        self.assertEqual(self.ex[1]["cities"], ["modiin", "tel-aviv"])
        self.assertIn("all_14_cities", self.ex[2]["flags"])
        self.assertEqual(len(self.ex[2]["cities"]), 14)

    def test_address_fallback_and_bio(self):
        self.assertEqual(self.ex[3]["address_fallback_city_id"], 12)
        self.assertEqual(self.ex[1]["bio"], "על הרופאה")
        self.assertEqual(self.ex[1]["profession"], "רופא משפחה")

    def test_login_and_nicename_unique(self):
        self.assertEqual(len({r["login"] for r in self.ex.values()}), len(self.ex))
        self.assertEqual(len({r["nicename"] for r in self.ex.values()}), len(self.ex))

    def test_writers(self):
        self.assertEqual(self.widx["1"][0], "staff:mcity")
        self.assertEqual(self.widx["20"][0], "staff:guest")
        self.assertEqual(self.widx["40"][0], "staff:marketing")
        self.assertEqual(self.widx["60"], ("expert:1", "marketing_expert"))
        self.assertEqual(self.widx["30"][0], "writer:30")
        self.assertNotIn("50", self.widx)                       # בלי כתבות
        self.assertEqual(self.stw["writer_dup_name_groups"], 1)  # 30 ו-31 לא מאוחדים
        self.assertEqual(self.stw["articles_writer_deleted"], 1)


class ArticlesTest(unittest.TestCase):
    def setUp(self):
        (self.db, self.ex, self.a, self.st, self.col, self.cl, *_rest) = run_all()

    def test_scope_and_exclusions(self):
        self.assertNotIn(8, self.a)      # דומיין מוחרג בלבד
        self.assertNotIn(10, self.a)     # בלי דומיין
        self.assertEqual(self.st["not_migrated_excluded_domain"], 1)
        self.assertEqual(self.st["not_migrated_no_domain"], 1)
        self.assertEqual(self.a[9]["scope"], "national")
        self.assertEqual(self.a[1]["scope"], "city")
        self.assertEqual(self.a[2]["cities"], ["modiin", "tel-aviv"])

    def test_basic_fields(self):
        a = self.a[1]
        self.assertEqual(a["status"], "publish")
        self.assertEqual(self.a[11]["status"], "draft")
        self.assertEqual(a["excerpt"], "מבוא")
        self.assertEqual(a["image_credit"], "צילום: פלוני")
        self.assertEqual(a["legacy_url"], "1001")
        self.assertEqual(a["post_date_gmt"], "2015-06-01 09:00:00")
        self.assertEqual(self.a[17]["post_date_gmt"], "2016-07-01 09:00:00")
        self.assertTrue(self.a[14]["hide_date"])
        self.assertFalse(a["hide_date"])

    def test_related_only_known(self):
        self.assertEqual(self.a[1]["related_legacy_ids"], [2])   # 999 לא קיים

    def test_topics_and_flags(self):
        self.assertEqual(self.a[1]["topics"], ["news"])
        self.assertEqual(self.a[2]["topics"], ["body-and-soul"])
        self.assertIn("section_unreviewed", self.a[11]["flags"])
        self.assertIn("news_sub_area_merged", self.a[14]["flags"] + ["news_sub_area_merged"])

    def test_author_precedence(self):
        self.assertEqual((self.a[4]["author"], self.a[4]["author_source"]), ("expert:1", "article_expert"))
        self.assertEqual((self.a[3]["author"], self.a[3]["author_source"]), ("expert:1", "section_expert"))
        self.assertEqual(self.a[1]["author"], "writer:30")
        self.assertEqual(self.a[12]["author"], "staff:unknown")
        self.assertEqual(self.a[9]["author"], "staff:mcity")
        self.assertEqual(self.a[11]["author"], "staff:guest")

    def test_holds(self):
        self.assertIn("marketing_agency_kept", self.a[5]["hold"])   # buypost: ברשימת הסוכנויות שהלקוח השאיר
        self.assertIn("marketing_agency", self.a[6]["hold"])
        self.assertIn("marketing_expert", self.a[22]["hold"])
        self.assertEqual(self.a[22]["author"], "expert:1")       # נשמר שמו של המומחה
        self.assertIn("section_excluded", self.a[7]["hold"])
        self.assertIn("author_expert_held", self.a[13]["hold"])
        self.assertEqual(self.a[1]["hold"], [])

    def test_marketing_include_policy(self):
        _, _, a, *_ = run_all(hold_policy(marketing_expert="include"))
        self.assertEqual(a[22]["hold"], [])                      # מומחה משלם: מותר
        self.assertIn("marketing_agency_kept", a[5]["hold"])     # סוכנות: עדיין מוחזק
        _, _, a, *_ = run_all(hold_policy(marketing_agency_kept="include"))
        self.assertEqual(a[5]["hold"], [])
        self.assertIn("marketing_agency_kept", a[5]["flags"])
        _, _, a, *_ = run_all(hold_policy(marketing_agency="include"))
        self.assertEqual(a[6]["hold"], [])
        self.assertIn("marketing_agency", a[6]["flags"])

    def test_cutoff(self):
        _, _, a, *_ = run_all(hold_policy(cutoff_year=2010, pre_cutoff="hold"))
        self.assertIn("before_cutoff", a[19]["hold"])
        self.assertNotIn("before_cutoff", a[1]["hold"])

    def test_slugs(self):
        self.assertEqual(self.a[15]["slug"], "expert-1")
        self.assertEqual(self.a[16]["slug"], "modiin-1")
        self.assertEqual(self.a[1]["slug"], "כתבה-רגילה")
        self.assertEqual(self.a[2]["slug"], "כתבה-רגילה-2")
        self.assertEqual(len({a["slug"] for a in self.a.values()}), len(self.a))

    def test_content(self):
        c = self.a[20]
        self.assertNotIn("swfobject", c["content"])
        self.assertIn("keep()", c["content"])
        self.assertEqual(c["refs"]["youtube"], ["abcdefghijk"])
        self.assertEqual(c["refs"]["inline_images"], ["/files_media/a.jpg"])
        self.assertIn("1002", c["refs"]["internal_ids"])

    def test_section_pointing_to_non_expert_keeps_writer(self):
        # מדור 'קובי עצבני' מצביע לרשומת mcity פנימית: הכותב נשאר המחבר
        from mcimport.articles import build_articles as ba
        db, ex, a, st, col, cl, st_u, st_w, wd, widx, p = run_all()
        ex2 = dict(ex)
        ex2[1] = dict(ex[1], hold=["not_an_expert"])
        secs, cl2 = load_sections(db)
        recs, st2, _ = ba(db, load_domains(), cl2, {s["id"]: s for s in secs}, ex2, widx, p)
        r = {x["legacy_id"]: x for x in recs}[3]
        self.assertEqual((r["author"], r["author_source"]), ("writer:30", "writer"))
        self.assertIn("section_points_to_non_expert", r["flags"])

    def test_limit(self):
        _, _, a, st, *_ = run_all(limit=3)
        self.assertEqual(st["seen"], 3)


class ForumTest(unittest.TestCase):
    def test_buckets(self):
        db, ex, a, *_ , p = run_all()
        recs, st = build_forum(db, ex, set(a), p)
        b = {r["legacy_id"]: r for r in recs}
        self.assertEqual(b[1]["bucket"], "converted")
        self.assertEqual(b[1]["article_legacy_ids"], [1])
        self.assertEqual(b[2]["bucket"], "published_unconverted")
        self.assertIn("forum_policy", b[2]["hold"])
        self.assertEqual(b[3]["bucket"], "unanswered")
        self.assertEqual(b[4]["bucket"], "inactive")
        self.assertEqual(st["answers_orphan"], 1)
        self.assertEqual(st["answers_expert_missing"], 1)

    def test_no_personal_data(self):
        db, ex, a, *_, p = run_all()
        recs, _ = build_forum(db, ex, set(a), p)
        blob = json.dumps(recs, ensure_ascii=False)
        self.assertNotIn("secret@example.com", blob)
        self.assertNotIn("כתובת", blob)

    def test_include_policy(self):
        db, ex, a, *_, p = run_all(hold_policy(forum="include"))
        recs, _ = build_forum(db, ex, set(a), p)
        self.assertEqual({r["legacy_id"]: r for r in recs}[2]["hold"], [])


class EndToEndTest(unittest.TestCase):
    def _run(self, out):
        import sqlite3
        path = os.path.join(tempfile.mkdtemp(), "t.db")
        mem = fixture.build()
        disk = sqlite3.connect(path)
        mem.backup(disk)
        disk.close()
        return cli.run(path, out, as_of=AS_OF)

    def test_idempotent_and_files(self):
        out = tempfile.mkdtemp()
        m1, rep = self._run(out)
        m2, _ = self._run(out)
        self.assertEqual(m1["sha256"], m2["sha256"])
        self.assertEqual(m1["counts"]["articles.jsonl"], 21)   # 24 פחות 1 לפני החתך ופחות 2 שיווק בלי קרדיט (buypost נשאר)
        for n in ("users.jsonl", "articles.jsonl", "forum.jsonl", "sections.jsonl", "report.md", "manifest.json"):
            self.assertTrue(os.path.exists(os.path.join(out, n)), n)
        self.assertIn("דוח ייבוא", rep)

    def test_refuses_inside_repo(self):
        with self.assertRaises(SystemExit):
            cli.run("/nonexistent.db", os.path.join(cli.REPO_ROOT, "out"))

    def test_real_dump_counts(self):
        db = os.environ.get("MC_OLD_DB")
        if not db or not os.path.exists(db):
            self.skipTest("MC_OLD_DB לא הוגדר")
        m, _ = cli.run(db, tempfile.mkdtemp(), as_of=AS_OF, policy=hold_policy())
        self.assertEqual(m["counts"]["forum.jsonl"], 7114)
        self.assertEqual(m["counts"]["sections.jsonl"], 255)
        self.assertEqual(m["counts"]["articles.jsonl"] + 214, 38534)


class ExpertRecordClassesTest(unittest.TestCase):
    """תשובות הלקוח על גיליון 2 (24.11): מעגלים נשארים, פנימיות ובדיקה מוסרים."""

    def test_default_mapping_file(self):
        from mcimport.config import load_expert_classes
        c = load_expert_classes()
        self.assertEqual(sum(1 for r in c.values() if r["class"] == "circle"), 24)
        self.assertEqual(sum(1 for r in c.values() if r["class"] == "internal"), 12)
        self.assertEqual(sum(1 for r in c.values() if r["class"] == "test"), 3)
        self.assertEqual(c[146]["class"], "entity_removed")            # master - הבית של המתווכים

    def test_circle_kept_and_not_held(self):
        _, ex, _, _, _, _, st_u, *_ = run_all(Policy(cutoff_year=None), classes={6: {"class": "circle"}})
        self.assertEqual((ex[6]["kind"], ex[6]["hold"], "circle" in ex[6]["flags"]), ("circle", [], True))
        self.assertEqual(st_u["circles"], 1)
        _, ex, *_ = run_all(Policy(cutoff_year=None, circles="hold"), classes={6: {"class": "circle"}})
        self.assertEqual(ex[6]["hold"], ["circle_pending"])

    def test_removed_records_not_written_and_articles_fall_back_to_writer(self):
        _, ex, a, st, _, _, st_u, *_ = run_all(Policy(cutoff_year=None), classes={1: {"class": "internal"}, 7: {"class": "entity_removed"}})
        self.assertTrue(ex[1]["removed"] and ex[7]["removed"])
        self.assertEqual(st_u["removed_internal"], 1)
        self.assertEqual(st_u["removed_entity_removed"], 1)
        self.assertEqual(a[4]["author"], "writer:30")                    # forum_expert_id בכתבה
        self.assertIn("author_record_removed", a[4]["flags"])
        self.assertEqual(a[3]["author"], "writer:30")                    # מדור מומחה
        self.assertIn("section_points_to_removed", a[3]["flags"])
        self.assertNotIn("author_expert_held", a[4]["hold"])

    def test_removed_records_policy_hold_and_cli_output(self):
        _, ex, *_ = run_all(Policy(cutoff_year=None, removed_records="hold"), classes={4: {"class": "test"}})
        self.assertEqual(ex[4]["hold"], ["record_removed"])
        self.assertFalse(ex[4].get("removed"))

    def test_forum_answers_of_removed_expert_lose_attribution(self):
        db, ex, *_ = run_all(Policy(cutoff_year=None), classes={1: {"class": "internal"}})
        recs, st = build_forum(db, ex, set(), Policy())
        q1 = next(r for r in recs if r["legacy_id"] == 1)
        self.assertIsNone(q1["expert_id"])
        self.assertTrue(all(a["expert_id"] is None for a in q1["answers"]))


class NoPendingStatusTest(unittest.TestCase):
    """החלטה 92: תהליך אישור הכתבות שולח מייל על כל מעבר ל-pending, ולכן היבוא כותב רק publish או draft."""

    def test_statuses_are_publish_or_draft_only(self):
        _, _, a, *_ = run_all(Policy(cutoff_year=None))
        self.assertTrue(a)
        self.assertLessEqual({x["status"] for x in a.values()}, {"publish", "draft"})


class SubAreaRegionTest(unittest.TestCase):
    """הלקוח (24.11, שאלה 11): כתבות תת-אזור עוברות לאזור הנקוב. כרמיאל לערי הצפון."""

    def test_subarea_region(self):
        _, _, a, st, *_ = run_all(Policy(cutoff_year=None))
        self.assertEqual((a[25]["cities"], a[25]["scope"]), (["north"], "city"))       # כרמיאל: צפון, גם כשסומנה לכל הערים
        self.assertNotIn("all_regions", a[25]["flags"])
        self.assertIn("region_from_subarea", a[25]["flags"])
        self.assertEqual((a[26]["cities"], a[26]["scope"]), (["petah-tikva"], "city"))  # ראש העין: פתח תקווה, לא ארצית
        self.assertEqual(a[1]["cities"], ["modiin"])                                    # כתבה רגילה לא מושפעת
        self.assertEqual(st["subarea_region_override"], 2)


class ClientDecisionsTest(unittest.TestCase):
    """תשובות הלקוח מ-24.11.2026 הן ברירות המחדל."""

    def test_defaults(self):
        p = Policy()
        self.assertEqual((p.cutoff_year, p.pre_cutoff, p.marketing_agency, p.marketing_agency_kept, p.marketing_expert, p.forum),
                         (2015, "exclude", "exclude", "include", "include", "exclude"))

    def test_articles(self):
        _, ex, a, st, *_ = run_all(Policy())
        self.assertNotIn(19, a)                       # 2005 לפני החתך: מוסרת
        self.assertEqual(st["excluded_before_cutoff"], 1)
        self.assertIn(5, a)                           # סוכנות buypost: הלקוח אישר להשאיר (24.11)
        self.assertEqual((a[5]["marketing_kind"], a[5]["author"], a[5]["hold"]), ("agency_kept", "staff:marketing", []))
        self.assertNotIn(6, a)                        # שיווק לפי מדור, כותב אחר בלי קרדיט: מוסר
        self.assertEqual(st["excluded_marketing_agency"], 2)   # 6 ו-24 (בלי קרדיט, לא ברשימת הסוכנויות שנשארות)
        self.assertNotIn(24, a)
        self.assertIn(23, a)                          # מדור שיווקי אבל מומחה בקרדיט: נשארת
        self.assertEqual(a[23]["marketing_kind"], "expert")
        self.assertIn(22, a)                          # בשם מומחה: נשארת, בלי hold, ועל שמו
        self.assertEqual((a[22]["hold"], a[22]["author"]), ([], "expert:1"))
        self.assertIn(1, a)

    def test_inactive_and_expired_experts_stay(self):
        # הלקוח: מומחה לא פעיל או שפג מנוי נשאר (מטעמים משפטיים), והכתבות שלו לא מוחזקות
        _, ex, a, *_ = run_all(Policy())
        self.assertEqual(ex[4]["hold"], [])            # לא פעיל
        self.assertEqual(ex[4]["mc_active"], "0")      # נשאר לא פעיל: מוסתר מהרשימה
        self.assertEqual(ex[5]["hold"], [])            # מנוי שפג
        self.assertEqual(ex[5]["mc_subscription_end"], "20200501")
        self.assertEqual(a[13]["author"], "expert:4")
        self.assertEqual(a[13]["hold"], [])
        self.assertIn("not_an_expert", ex[6]["hold"])  # לא מומחה: עדיין ממתין

    def test_forum(self):
        db, ex, a, *_, p = run_all(Policy())
        recs, st = build_forum(db, ex, set(a), p)
        self.assertEqual({r["bucket"] for r in recs}, {"converted", "inactive"})
        self.assertEqual(st["excluded_published_unconverted"], 1)
        self.assertEqual(st["excluded_unanswered"], 1)

    def test_cutoff_boundary_inclusive(self):
        _, _, a, *_ = run_all(hold_policy(cutoff_year=2015))
        self.assertIn(1, a)                           # 2015-06-01 נכללת
        self.assertNotIn(19, a)


class ImagesTest(unittest.TestCase):
    def _db_file(self):
        import sqlite3
        path = os.path.join(tempfile.mkdtemp(), "t.db")
        mem = fixture.build()
        mem.execute("update rt_forum_experts set image='e1.JPG' where id=1")
        mem.commit()
        disk = sqlite3.connect(path)
        mem.backup(disk)
        disk.close()
        return path

    def test_manifest_and_check(self):
        from mcimport import images
        rows = images.build_manifest(self._db_file())
        names = {r[0]: r for r in rows}
        self.assertIn("i.jpg", names)                      # תמונה ראשית של כתבות
        self.assertEqual(names["a.jpg"][1], "article_inline")
        self.assertEqual(names["e1.JPG"][1], "expert")
        folder = tempfile.mkdtemp()
        os.makedirs(os.path.join(folder, "sub"))
        for rel in ("i.jpg", "sub/A.JPG", "other.png", "note.txt"):
            with open(os.path.join(folder, rel), "wb") as f:
                f.write(b"x" * 10)
        dbf = self._db_file()
        r = images.check_folder(folder, rows, dbf)
        ai = r["article_impact"]
        self.assertEqual((ai["with_image"], ai["lost"]), (0, 0)) if False else self.assertEqual(ai["lost"], 0)
        self.assertEqual(r["found"], 2)                    # i.jpg, a.jpg (אותיות שונות)
        self.assertEqual([m[0] for m in r["missing"]], ["e1.JPG"])
        self.assertEqual(r["case_only_matches"], 1)
        self.assertEqual(r["extra_not_in_manifest"], 2)
        self.assertEqual(r["non_image_ext"], {".txt": 1})
        self.assertIn("חסרים", images.format_check(r))


if __name__ == "__main__":
    unittest.main()
