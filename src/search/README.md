# mcsearch: אב-טיפוס חיפוש עברי (Q0)

חבילה בלי תלויות (stdlib, SQLite FTS5). בונה אינדקס מפלט ההגירה (`articles.jsonl`, `users.jsonl`), לא מחוברת לוורדפרס.
זה אב-טיפוס להוכחת הנרמול והדירוג; מנוע האתר (Q2) ייבנה אחרי החלטות X7c ובדיקת מסד הנתונים באתר (Q1).

## שימוש
```
cd src/search
python3 -m mcsearch build  --migration-out <תיקיית פלט הגירה> --out <תיקיית אינדקס> --as-of YYYYMMDD
python3 -m mcsearch search --out <תיקיית אינדקס> "עו\"ד בחיפה" [--type article|expert|circle] [--city haifa]
python3 -m mcsearch report --out <תיקיית אינדקס>      # search_report.md: שקילויות, קלטים עוינים, זמני שאילתה
python3 -W ignore -m unittest discover -s tests
```
הפקודה `build` מסרבת לכתוב בתוך הריפו (האינדקס מכיל נתונים אישיים ולא נכנס ל-git).

## קבצים
- `mcsearch/normalize.py`: ניקוי טקסט ווריאנטים (קידומות, ריבוי, נקבה, ראשי תיבות, מילים נרדפות). לעולם לא מחליפים מילה, רק מוסיפים.
- `mcsearch/index.py`: `build` ו-`search` (טבלת FTS5 `fts(title, author, extra, lead, body)`, bm25 במשקלים 10/6/4/3/1; AND ואם אין תוצאות OR).
- `mcsearch/queries.json`: זוגות שקילות, שאילתות לדוגמה, קלטים עוינים.
- חוק נראות: מומחה מופיע רק אם פעיל, לא מוחזק ומנוי בתוקף. כתבות מוחזקות וטיוטות לא באינדקס.

## שאלות פתוחות (החלטה 93)
שם עיר מנפח תוצאות; הנמכת תוכן שיווקי; כותרות כפולות; כתבות של מומחים לא פעילים (נכנסות לאינדקס כרגע).
