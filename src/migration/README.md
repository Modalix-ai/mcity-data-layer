# חבילת המרה מהדאמפ הישן (MCity)

קוראת את הדאמפ של האתר הישן (SQLite, קריאה בלבד) וכותבת **קבצי ביניים** (JSONL) ודוח. **לא נוגעת באתר ולא כותבת לוורדפרס.** טוען WP-CLI (שיכתוב את הביניים לאתר) ועיבוד תמונות עוד לא נכתבו.

## הרצה

```bash
python -m mcimport run --db /path/old.db --out /path/OUTSIDE_REPO/out --as-of 20261122
```

| פרמטר | משמעות |
|---|---|
| `--db` | הדאמפ כ-SQLite (נפתח בקריאה בלבד) |
| `--out` | תיקיית פלט. **חייבת להיות מחוץ לריפו** (מידע אישי); הכלי מסרב אחרת |
| `--as-of` | תאריך לחישוב פקיעת מנוי (YYYYMMDD). ברירת מחדל: היום |
| `--limit N` | רק N כתבות ראשונות (לבדיקה) |
| `--policy k=v` | מדיניות (חוזר). ראו למטה |
| `--only` | `users,articles,forum` (כרגע משפיע רק על מה שנכתב) |

דורש Python 3.9+ בלבד (ספרייה סטנדרטית).

## פלט

| קובץ | תוכן |
|---|---|
| `users.jsonl` | מומחים (`expert:ID`), חשבונות צוות (`staff:*`) וכותבים (`writer:ID`) |
| `articles.jsonl` | כתבות עם עיר, נושאים, כותב, תאריכים ב-GMT, slug, `hold`, `flags` |
| `forum.jsonl` | שאלה עם תשובות מקוננות ו-`bucket` |
| `sections.jsonl` | סיווג כל מדור ישן, ורמז התאמת שם למומחה למדורים שלא סווגו |
| `report.md` | דוח קריא: מה הועבר, מה ממתין, ולמה |
| `manifest.json` | גרסה, מדיניות, ו-sha256 לכל קובץ (הרצה חוזרת נותנת אותם ערכים) |

## hold ו-flags

- **`hold`**: החלטה פתוחה. רשומה עם hold לא נכתבת לאתר. כך שום דבר שתלוי בלקוח או במעצב לא נכתב בשקט.
- **`flags`**: לידיעה בלבד (לא עוצרים).

## מדיניות (`--policy`)

| פרמטר | ברירת מחדל | אפשרויות |
|---|---|---|
| `marketing` | `hold` | `include` |
| `inactive_experts`, `expired_experts`, `realestate_experts` | `hold` | `include` |
| `shared_email` | `blank` | `keep_first_active` |
| `section_exclusion` | `hold` | `include` |
| `forum` | `hold` | `include` |
| `cutoff_year` | ללא | שנה (כתבות קודמות מקבלות hold) |
| `slug_policy` | `hebrew` | `translit` |
| `writer_policy` | `contributor` | `staff` |

## איך מסווגים מדורים שלא סווגו

מוסיפים שורה ל-`mappings/section_overrides.csv` (`id,kind,topic,expert_id,note`) ומריצים שוב. הרשימה נמצאת בדוח, וכוללת רמז להתאמת שם למומחה.

## בדיקות

```bash
python -m unittest discover -s tests            # סינתטי, 33 בדיקות
MC_OLD_DB=/path/old.db python -m unittest discover -s tests   # כולל בדיקת ספירות מול הדאמפ האמיתי
```

## בדיקת תיקיית תמונות (לפני כל עיבוד)

```bash
python -m mcimport images-manifest --db old.db --out images_manifest.csv       # רשימת הקבצים הצפויה (שמות בלבד)
python -m mcimport images-check --folder D:\\old_images --manifest images_manifest.csv --report images_report.md --db old.db
```

`--db` (אופציונלי) מוסיף לדוח כמה כתבות איבדו תמונה ראשית, לפי שנה. חשוב כי הלקוח ניקה תמונות באתר הישן אחרי שהדאמפ נלקח, ולכן "חסר" אינו בהכרח שגיאה: הרשימה היא הגבול העליון.

הבדיקה לא קוראת ולא ממירה תמונות, רק סורקת שמות וגדלים (תיקייה של 10GB בשניות). מדווחת: כמה נמצאו וכמה חסרים לפי מקור (ראשית בכתבה, בתוך תוכן, מומחה, פורום), קבצים שאינם תמונה, קבצים כפולים בתת-תיקיות, ואפס בייט. ההמרה (גדלים ופורמט) והעלאה לאתר ממתינות להגדרות המעצב.

## מה לא כאן (בכוונה)

- המרת תמונות והעלאתן (ממתין לפורמטים וגדלים מהמעצב)
- כתיבה לוורדפרס (טוען WP-CLI אידמפוטנטי לפי `_mc_legacy_id`, עם `update_field`)
- התאמת מדור ישן ל-term בטקסונומיה `section` (נעשית בטוען)
- סיסמאות ושליחת מיילים: אין. הטוען ייצור סיסמה אקראית ויכבה מיילי ברכה
- `data.city` של מומחה הוא מזהה מספרי; ממתין לטבלת הערים מ-SPD (נשמר ב-`address_fallback_city_id`)
