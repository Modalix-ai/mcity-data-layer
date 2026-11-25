import os
import sys
import unittest

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))
from mcsearch import normalize as n  # noqa: E402


class NormalizeTest(unittest.TestCase):
    def test_final_letters_and_case(self):
        self.assertEqual(n.clean("שלום ABC"), "שלומ abc")
        self.assertEqual(n.tokens("מנוחה ארוך"), ["מנוחה", "ארוכ"])
        self.assertEqual(n.tokens("ארוך"), n.tokens("ארוכ"))

    def test_niqqud_and_quotes(self):
        self.assertEqual(n.tokens("שָׁלוֹם"), n.tokens("שלום"))
        self.assertEqual(n.tokens("מד״א"), n.tokens("מדא"))
        self.assertEqual(n.tokens("ג'ירפה"), n.tokens("גירפה"))

    def test_abbreviation_expands_before_quotes_are_removed(self):
        for q in ("עו\"ד", "עו״ד", "עוה\"ד"):
            self.assertEqual(n.tokens(q), ["עורכ", "דינ"], q)
        self.assertEqual(n.tokens("רו\"ח"), ["רואה", "חשבונ"])

    def test_prefix_variants(self):
        self.assertIn("חיפה", n.variants("בחיפה"))
        self.assertIn("חיפה", n.variants("לחיפה"))
        self.assertIn("חיפה", n.variants("והחיפה"))
        self.assertIn("חיפה", n.variants("מחיפה"))
        self.assertEqual(n.variants("בנק"), [])          # קצרה מ-4
        self.assertNotIn("", n.variants("בית"))

    def test_original_is_never_replaced(self):
        txt = n.index_text("גרים בחיפה")
        self.assertIn("בחיפה", txt.split())
        self.assertIn("חיפה", txt.split())

    def test_plural_and_synonyms(self):
        self.assertIn("עורכ", n.variants("עורכי"))
        self.assertIn("משכנתה", n.variants("משכנתא"))
        self.assertIn("רופא", n.variants("רופאי"))

    def test_feminine_forms(self):
        self.assertIn("עורכ", n.variants("עורכת"))
        self.assertIn("פסיכולוג", n.variants("פסיכולוגית"))
        self.assertIn("רופא", n.variants("רופאה"))
        self.assertNotIn("חיפ", n.variants("חיפה"))            # לא מורידים ה
        self.assertEqual(n.variants("בת"), [])

    def test_markup_stripped(self):
        raw = "<p>שלום <b>עולם</b></p>[caption]x[/caption]<script>bad()</script>"
        self.assertEqual(n.tokens(n.strip_markup(raw)), ["שלומ", "עולמ", "x"])
        self.assertEqual(n.tokens(n.strip_markup("&nbsp;&amp;שלום")), ["שלומ"])

    def test_stopwords_dropped_unless_only_stopwords(self):
        self.assertEqual(n.tokens("הכתבה של הצבא"), ["הכתבה", "הצבא"])
        self.assertEqual(n.tokens("של את"), ["של", "את"])

    def test_query_groups(self):
        g = n.query_groups("עורכי דין בחיפה")
        self.assertEqual(g[0][0], "עורכי")
        self.assertIn("עורכ", g[0])
        self.assertIn("חיפה", g[2])
        self.assertEqual(n.query_groups(""), [])


if __name__ == "__main__":
    unittest.main()
