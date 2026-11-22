# MCity - ממצאי האתר של המעצב

מקור: https://portalmcity.ussl.nl/ (דף ראשי בלבד, נקרא ב-2026-11-09 בבקשות GET בלבד).
הערה: זה מה שנראה מבחוץ. שמות שדות ו-slugs של CPT הם הסקה מהכתובות, לא אימות.

## טכנולוגיה
- וורדפרס עם Elementor 4.3.6. JetEngine לא אומת.
- עדכון אחרון של הדף: 2026-10-25.
- כל התוכן הוא תוכן בדיקה (לורם איפסום, כותרות "חדשות 1-6", אותה תמונה בכל מקום).

## מבנה כתובות

| סוג | תבנית | הערה |
|---|---|---|
| כתבות | `/{slug}/` | בשורש, בלי `/news/` ובלי אזור |
| קטגוריות | `/cat-2/{parent}/{child}/` | למשל `/cat-2/living-better/body-and-soul/` |
| מומחים ועסקים | `/expert/{slug}/` | ארכיון: `/all_expert/` |
| תחומי מומחים | `/index/{term}/` | 12 מונחים: advocates, coaching-and-consulting, assets-and-real-estate, marketing-and-digital, professionals, state-circle, business-cycle, health, finance, body-and-soul, free-circuits, organizations. בנוסף `/index/business/` |
| מדורים | `/madorim/artzi/{slug}/` | ארכיון: `/section/`. `artzi` כנראה "ארצי" |
| אזורים | `/{region}/` | 14 דפים |
| אחר | `/video/`, `/category/news/`, `/2026/11/09/` | |

## 14 האזורים (סרגל עליון)
חיפה והקריות (`haifa`), אשדוד–אשקלון (`ashdod`), ערי הצפון (`north`), באר שבע (`beer-sheva`), דרום השרון (`drom-hasharon`), ערי השרון (`sharon`), חולון–בת ים (`holon`), תל-אביב (`tel-aviv`), ירושלים (`jerusalem`), מודיעין–שהם (`modiin`), ראשון לציון (`rishon`), רחובות–יבנה (`rehovot`), רמת גן (`ramat-gan`), פ"ת–אונו (`petah-tikva`).

## שדות נראים בכל כרטיס
- **כתבה:** תמונה ממוזערת, כותרת, תאריך, קטגוריה, מחבר (ישות מומחה, `/expert/mcity-team/`).
- **מומחה או עסק:** תמונה, שם, מקצוע, עיר (ערך בודד), טלפון, כתובת רחוב (באחד המקרים), קישור. יש מצב ריק: "לא נמצאו משתמשים בקטגוריה זו".
- **מדור:** תמונה וכותרת.
- **טופס לידים:** שם, טלפון, אימייל, סיבת פנייה (פנייה כללית / דיווח חדשות / פרסום / אחר), הודעה, הסכמה לדיוור. אין שדה אזור.
- **באנרים:** תמונות קבועות 728×90, 300×100, 336×280, כולן מקשרות ל-m-media.co.il. אין סימן ל-Geo-Targeting.

## פערים מול הסיכום המקורי
1. נראה שעסקים ומומחים הם CPT אחד (`expert`), בסיכום היו שניים (`business`, `experts`).
2. לא נראה CPT של לוח מודעות (`board`).
3. אין `/c/{city}/` ואין `service_area`: האזורים הם דפי שורש, ומומחה מציג עיר אחת בלבד.
4. מומחים בלי תת-דומיין (`/expert/{slug}/` ולא `biz.mcity.co.il`).
5. קטע "מעגלי העסקים" עדיין מפנה לאתר הישן (`mcity.co.il/expert/מעגל-...`).
6. כבר קיים `hamumhim.mcity.co.il` (פורום המומחים).

## לבדיקה בהמשך (ממתין לאישור)
- `/wp-json/wp/v2/types` ו-`/wp-json/wp/v2/taxonomies` לשמות אמיתיים של CPTs וטקסונומיות.
- דף מומחה אחד ודף כתבה אחד, לשדות המלאים.

---

## עדכון (2026-11-11): תוספים לפי רשימת ה-namespaces ב-REST

מקור: הרשימה שהודבקה מ-`/wp-json/` של האתר. אין בה גרסאות, והיא מעידה על מה שמותקן ולא בהכרח על מה שבשימוש.

| namespace | תוסף (לפי השם) | משמעות עבורנו |
|---|---|---|
| `yoast/v1` | Yoast SEO | תוסף ה-SEO. עונה על שאלה 16, אבל לא על canonical דינמי לפי שדה |
| `redirection/v1` | Redirection | ניהול הפניות 301. רלוונטי להגירה |
| `burst/v1` | נראה Burst Statistics | אנליטיקס פנימי. רלוונטי למדידת צפיות |
| `siteground-optimizer/v1` | SiteGround Optimizer | מטמון ואופטימיזציה. **לא LiteSpeed Cache**, בניגוד לתכנון |
| `elementor/v1`, `elementor-pro/v1`, `elementor-one/v1`, `elementor-ai/v1` | Elementor, Elementor Pro ותוספות | בניית הממשק |
| `elementor-hello-elementor/v1` | ערכת Hello Elementor | ערכת נושא מינימלית. כל התבניות נבנות ב-Elementor |
| `aios/v1/onboarding` | נראה All In One Security | תוסף אבטחה. עלול להשפיע על גישה ל-REST |
| `safe-svg/v1`, `duplicate-post/v1` | העלאת SVG, שכפול פוסטים | |
| `file-manager-advanced/v1`, `advanced-db-cleaner/v1` | ניהול קבצים וניקוי מסד נתונים | כלי פיתוח. לא להשאיר בפרודקשן |
| `mcp`, `elementor-mcp-composer/v1.0.20`, `wp-abilities/v1` | ממשק MCP ויכולות | מרמזים שהאתר חושף ממשק לסוכני AI. לא משתמשים בו |
| `novamira/v1` | לא מזוהה | לברר |
| `wp/v2`, `oembed/1.0`, `wp-site-health/v1`, `wp-block-editor/v1` | ליבת וורדפרס | |

**מה לא נמצא ברשימה:** אין namespace שמכיל `jet` (JetEngine ותוספי Crocoblock), וגם לא `acf`, `pods` או `metabox`.

**משמעות:** אין סימן ל-JetEngine באתר הדוגמה. זה לא מוכיח שהוא לא מותקן, כי לא ידוע אם JetEngine רושם namespace משלו. לבדיקה נוספת: מקור הדף (חיפוש `jet-engine`) ולשונית Network (סינון `plugins/`). אם הוא באמת לא בשימוש, צריך לברר איך נבנו ה-CPTs והתצוגות הדינמיות, כי זה משפיע על התוכנית כולה.

---

## בדיקה בדפדפן (2026-11-11): REST ומקור הדף, קריאה בלבד (GET)

בוצעה בדפדפן המובנה על האתר של המעצב. נתונים אמיתיים, לא הסקה.

**גרסאות:** WordPress 7.1.4, Elementor 4.4.0.

**JetEngine: אין.** אין namespace של Jet ב-REST, אין מחלקות `jet-` בדף. תוספים שנטענים בדף הבית: `elementor`, `elementor-pro`, `safe-svg`, `burst-statistics`. כלומר CPTs ושדות לא נבנו ב-JetEngine (לפחות לא בצורה שנראית מבחוץ).

**CPTs שחשופים ב-REST:** `post` (כתבות), `page`, `videos` (עם טקסונומיה `video_place`), ועוד סוגים של Elementor ושל הליבה.

**טקסונומיות על `post`:** `category`, `post_tag`, `section` (היררכית), `cat-2` (היררכית). כלומר "מדורים" הם טקסונומיה, לא CPT (עונה על נקודה פתוחה 4).

**מומחים הם משתמשי וורדפרס, לא CPT.** `/expert/{slug}/` הוא עמוד מחבר (body class: `archive author author-mcity-team`), ו-`/wp-json/wp/v2/users` מחזיר משתמשים (`mcity-team`, `mcity-editor`, `oren`, `yuri`, `moshe`) עם קישור `/expert/{slug}/`. אין CPT בשם `expert`.

**תחומי מומחים (`/index/{term}/`)** הם טקסונומיה בשם **`author_category`** (body class: `tax-author_category`). היא לא חשופה ב-REST, ולא קשורה ל-`post`. כנראה טקסונומיה על משתמשים, מתוסף או מקוד.

**`/all_expert/`** הוא עמוד רגיל (`page-id-1576`) שנבנה ב-Elementor, לא ארכיון של CPT.

**לא נמצא:** CPT של אזורים או טקסונומיית `area` (14 האזורים הם כנראה עמודים רגילים), ושום CPT של לידים או באנרים.

**משמעות:**
1. החלטה 4 ביומן ("מומחה = CPT `expert`") לא תואמת למה שבנה המעצב. צריך להכריע: להמשיך עם משתמשים או לעבור ל-CPT (ראה שאלה 6).
2. מומחים כמשתמשים: קשה להחזיק 200 עד 500 כרטיסים עם שדות עשירים, סטטוס טיוטה/פרסום, ושיוך לכמה אזורים. ההמלצה שלנו: CPT. אבל זה פוגע בעבודה שהמעצב כבר עשה.
3. אין שום תשתית JetEngine, אז אין CPTs או שדות מוכנים לשימוש. כל מה שבחוזה נבנה מאפס.
