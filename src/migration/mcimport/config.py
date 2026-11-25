"""מדיניות ופרמטרים. כל מה שתלוי בהחלטת לקוח או מעצב הוא כאן, עם ברירת מחדל שמחזיקה (hold) ולא כותבת בשקט."""
import json
import os
from dataclasses import dataclass, field

HERE = os.path.dirname(os.path.abspath(__file__))
MAPPINGS_DIR = os.path.join(os.path.dirname(HERE), "mappings")

# כתובות ששמורות ולא יכולות להיות slug של כתבה (מסמך 10 סעיף 2)
RESERVED_SLUGS = {"expert", "all_expert", "all-expert", "madorim", "index", "section", "video", "thanks", "news", "author"}

TOPICS = [
    "news", "local-consumption", "body-and-soul", "parents-and-children",
    "relationships", "culture-and-leisure", "city-and-area-events",
]


@dataclass
class Policy:
    # --- תלויי לקוח (X7a). ברירת מחדל: להחזיק ולא לכתוב ---
    cutoff_year: int = 2015              # שנת חתך (הלקוח, 24.11.2026): מ-2015 ואילך. None = בלי חיתוך
    pre_cutoff: str = "exclude"          # exclude | hold   (כתבות לפני החתך: מוסרות, לא מוחזקות)
    marketing_agency: str = "exclude"    # exclude | hold | include   (הלקוח: תוכן שיווקי בלי שם מומחה או חברה מוסר) (קידום קישורים של סוכנויות: buypost, Best Links וכו', ~5,400 כתבות)
    marketing_agency_kept: str = "include"  # exclude | hold | include   (הלקוח, 24.11: buypost, Best Links, אופיר כתבות תדמית, Mega Links, לינקשייק, Seo Rush נשארים, ~5,400 כתבות)
    marketing_expert: str = "include"    # exclude | hold | include   (הלקוח: עם שם מומחה או חברה נשאר) (כתבות בשם מומחה משלם, "<שם> - תוכן שיווקי", ~2,700, רובן שאלות ותשובות)
    expired_experts: str = "include"     # hold | include   (הלקוח, 24.11: נשארים, מטעמים משפטיים. הלקוח יסיר ידנית אם ירצה)   (מנוי שפג: מיובא כלא פעיל רק אם include)
    inactive_experts: str = "include"    # hold | include   (הלקוח, 24.11: נשארים, מטעמים משפטיים)   (מומחים לא פעילים)
    realestate_experts: str = "hold"     # hold | include
    shared_email: str = "blank"          # blank | keep_first_active
    section_exclusion: str = "hold"      # hold | include  (כתבות שכל המדורים שלהן נדל"ן/בחירות/בדיקה)
    forum: str = "exclude"               # exclude | hold | include  (הלקוח: שאלות שפורסמו ולא הומרו לכתבה מוסרות)
    # --- תלויי מעצב ---
    slug_policy: str = "hebrew"          # hebrew | translit   (X19)
    # --- מדיניות פנימית שלנו, להכרעה ---
    writer_policy: str = "contributor"   # contributor | staff (כתב רגיל עם כתבות)
    all14_as_national: bool = False      # כתבה בכל 14 הערים נשמרת כמסומנת (דגל all_regions), לא הופכת לארצית

    @classmethod
    def from_pairs(cls, pairs):
        p = cls()
        for s in pairs or []:
            k, _, v = s.partition("=")
            if not hasattr(p, k):
                raise SystemExit("מדיניות לא מוכרת: %s" % k)
            cur = getattr(p, k)
            if isinstance(cur, bool):
                v = v.lower() in ("1", "true", "yes")
            elif k == "cutoff_year":
                v = int(v) if v else None
            setattr(p, k, v)
        return p


def load_domains(path=None):
    path = path or os.path.join(MAPPINGS_DIR, "domains.json")
    with open(path, encoding="utf-8") as f:
        raw = json.load(f)
    return {int(k): v for k, v in raw.items() if not k.startswith("_")}


def load_overrides(path=None):
    import csv
    path = path or os.path.join(MAPPINGS_DIR, "section_overrides.csv")
    out = {}
    if not os.path.exists(path):
        return out
    with open(path, encoding="utf-8", newline="") as f:
        for row in csv.DictReader(f):
            if row.get("id"):
                out[int(row["id"])] = row
    return out
