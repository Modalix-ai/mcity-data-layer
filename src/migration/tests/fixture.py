"""דאמפ סינתטי קטן (SQLite בזיכרון) עם כל המקרים שהכללים מטפלים בהם. בלי מידע אמיתי."""
import base64
import sqlite3


def ser_str(s):
    b = s.encode("utf-8")
    return b's:%d:"%s";' % (len(b), b)


def php_array(items):
    """items: רשימה של ערכים (מחרוזת/מספר) או dict -> מחרוזת PHP מסודרת ב-base64."""
    if isinstance(items, dict):
        pairs = list(items.items())
    else:
        pairs = list(enumerate(items))
    body = b""
    for k, v in pairs:
        body += (b"i:%d;" % k) if isinstance(k, int) else ser_str(k)
        if isinstance(v, bool):
            body += b"b:%d;" % int(v)
        elif isinstance(v, int):
            body += b"i:%d;" % v
        elif v is None:
            body += b"N;"
        elif isinstance(v, (list, dict)):
            body += base64.b64decode(php_array(v))
        else:
            body += ser_str(str(v))
    raw = b"a:%d:{%s}" % (len(pairs), body)
    return base64.b64encode(raw).decode()


SCHEMA = """
create table rt_articles(id,headline,content,writer,sections,domains,image,url,title,description,data,created,modified,active,forum_expert_id);
create table rt_sections(id,name,name2,url,title,description,domains,folders,data,created,modified,active,forum_expert_id);
create table rt_writers(id,name,address,phone,email,role,last_access,password,data,created,modified,active,add_rel_nofollow);
create table rt_forum_experts(id,name,real_name,real_surname,franchisee,pass,image,phones,emails,field,title,description,data,data_icons,created,modified,active);
create table rt_forum_subfields(id,name,image,field,description,data,created,modified,active);
create table rt_forum_fields(id,name,link,image,title,description,data,created,modified,active);
create table rt_forum_experts_expiry_date(id,forum_expert,expiry,data,created,modified,active);
create table rt_minisite_additional_fields(id,forum_expert_id,headline_1,headline_2,headline_3,odot_headline,odot_text,created_at,updated_at);
create table rt_forum_questions(id,user_email,image,title,content,address,phone,expert,user_name,data,created,modified,active);
create table rt_forum_answers(id,link,image,title,content,expert,question,scale,data,created,modified,active);
create table rt_articles_forum_questions(id,rt_articles_id,rt_forum_questions_id,data,created,modified,active);
"""


def build():
    db = sqlite3.connect(":memory:")
    db.executescript(SCHEMA)
    # --- תחומים ---
    db.executemany("insert into rt_forum_fields(id,name) values(?,?)", [(10, "בריאות"), (92, "מעגלי תמיכה"), (96, "נדלן")])
    db.executemany("insert into rt_forum_subfields(id,name,field) values(?,?,?)",
                   [(101, "רופא משפחה", 10), (102, "קבוצת תמיכה", 92), (103, "מתווך", 96)])
    # --- מומחים ---
    def ex(i, name, email, field, active, data, phones="", desc="", extra_bio=None):
        db.execute("insert into rt_forum_experts(id,name,real_name,real_surname,emails,phones,field,description,data,active,image) values(?,?,?,?,?,?,?,?,?,?,?)",
                   (i, name, "", "", email, phones, field, desc, php_array(data), active, ""))
        if extra_bio:
            db.execute("insert into rt_minisite_additional_fields(forum_expert_id,odot_text) values(?,?)", (i, extra_bio))
    ex(1, "ד\"ר דנה כהן", "dana@example.com", 101, 1, {"domains": ["1", "5"], "fields": ["101"], "phone": "521234567", "address": "רחוב א 1", "link_out": "http://x"}, desc="רופאה", extra_bio="על הרופאה")
    ex(2, "ישראל ישראלי", "shared@example.com", 101, 1, {"domains": [str(i) for i in range(1, 6)] + ["7", "8"] + [str(i) for i in range(10, 17)], "phone": "0501234567"})
    ex(3, "שרה לוי", "shared@example.com", 101, 1, {"domains": ["1"], "city": 12})
    ex(4, "לא פעיל", "inactive@example.com", 101, 0, {"domains": ["1"]})
    ex(5, "מנוי שפג", "expired@example.com", 101, 1, {"domains": ["1"]})
    ex(6, "קבוצת תמיכה", "support@example.com", 102, 1, {"domains": ["1"]})
    ex(7, "מתווך", "broker@example.com", 103, 1, {"domains": ["6"]})
    ex(8, "בלי אימייל", "", 101, 1, {"domains": ["8"], "phone": "+972-54-111-2222"})
    db.executemany("insert into rt_forum_experts_expiry_date(forum_expert,expiry) values(?,?)",
                   [(1, "2030-01-01"), (2, "2030-01-01"), (3, "2030-01-01"), (5, "2020-05-01")])
    # --- כותבים ---
    db.executemany("insert into rt_writers(id,name,role,active) values(?,?,?,?)",
                   [(1, "mcity", 1, 1), (20, "כתב אורח", 1, 1), (30, "יוסי כותב", 1, 1), (31, "יוסי כותב", 1, 1),
                    (40, "buypost תוכן שיווקי", 1, 1), (60, "ד\"ר דנה כהן - תוכן שיווקי", 1, 1), (61, "תוכן שיווקי", 1, 1), (50, "כותב בלי כתבות", 1, 1)])
    # --- מדורים ---
    secs = [
        (1, "חדשות מודיעין", 0), (2, "גוף ונפש", 0), (3, "צרכנות מודיעין", 0),
        (4, "ד\"ר דנה כהן", 1), (5, "ד\"ר דנה כהן - 2", 0), (6, "בחירות 2018", 0),
        (7, "תוכן שיווקי", 0), (8, "משהו לא מוכר", 0), (9, "חדשות הכפר", 0), (10, "נדל\"ן מגורים", 0),
    ]
    for sid, name, fe in secs:
        db.execute("insert into rt_sections(id,name,domains,forum_expert_id,active) values(?,?,?,?,1)", (sid, name, php_array(["1"]), fe))
    # --- כתבות ---
    def art(i, title, writer, sections, domains, content="<p>x</p>", active=1, fe=0, created="2015-06-01 12:00:00", data=None, desc="<b>מבוא</b>"):
        db.execute("insert into rt_articles values(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                   (i, title, content, writer, php_array(sections), php_array(domains), "i.jpg", str(1000 + i), title, desc,
                    php_array(data or {}), created, created, active, fe))
    art(1, "כתבה רגילה", "30", ["1"], ["1"], data={"image_text": "צילום: פלוני", "readmore": {0: 2, 1: 999}})
    art(2, "כתבה רגילה", "30", ["2"], ["1", "5"], created="2015-07-01 08:00:00")             # slug כפול
    art(3, "מומחה", "30", ["4"], ["1"])                                                      # מדור מומחה
    art(4, "מומחה מפורש", "30", ["3"], ["1"], fe=1)                                          # forum_expert_id בכתבה
    art(5, "שיווקית לפי כותב", "40", ["1"], ["1"])
    art(6, "שיווקית לפי מדור", "30", ["7"], ["1"])
    art(7, "רק בחירות", "30", ["6"], ["1"])
    art(8, "רק נדלן", "30", ["10"], ["6"])                                                   # דומיין מוחרג בלבד
    art(9, "ארצית", "1", ["1"], ["9"])
    art(10, "בלי דומיין", "1", ["1"], [])
    art(11, "טיוטה", "20", ["8"], ["1"], active=0)
    art(12, "כותב נמחק", "777", ["1"], ["1"])
    art(13, "מומחה לא פעיל", "30", ["1"], ["1"], fe=4)
    art(14, "תג מוסתר", "30", ["9"], ["1"], data={"time_display": False})
    art(15, "expert", "30", ["1"], ["1"])                                                    # slug שמור
    art(16, "modiin", "30", ["1"], ["1"])                                                   # slug שמור (עיר)
    art(17, "קיץ", "30", ["1"], ["1"], created="2016-07-01 12:00:00")                       # DST
    art(18, "חורף", "30", ["1"], ["1"], created="2016-01-01 12:00:00")
    art(19, "ישנה", "30", ["1"], ["1"], created="2005-01-01 12:00:00")
    art(20, "עם סקריפט", "30", ["1"], ["1"],
        content='<p>a</p><script>swfobject.embed()</script><script>keep()</script><img src="/files_media/a.jpg"><iframe src="https://www.youtube.com/embed/abcdefghijk"></iframe><a href="?id=1002">x</a>')
    art(22, "מאמר בשם מומחה", "60", ["1"], ["1"])
    art(23, "שיווקי במדור אבל מומחה בקרדיט", "30", ["7"], ["1"], fe=1)
    art(24, "שיווקי בלי קרדיט", "61", ["1"], ["1"])
    art(21, "כתבת כותב כפול", "31", ["1"], ["1"])
    # --- פורום ---
    for qid, active in ((1, 1), (2, 1), (3, 1), (4, 0)):
        db.execute("insert into rt_forum_questions(id,title,content,expert,user_name,created,active,user_email,phone,address) values(?,?,?,?,?,?,?,?,?,?)",
                   (qid, "שאלה %d" % qid, "תוכן", 1, "שואל", "2016-01-01 10:00:00", active, "secret@example.com", "050", "כתובת"))
    db.executemany("insert into rt_forum_answers(id,question,expert,content,scale,created,active) values(?,?,?,?,?,?,?)",
                   [(1, 1, 1, "ת1", 0, "2016-01-02 10:00:00", 1), (2, 2, 1, "ת2", 0, "2016-01-02 10:00:00", 1),
                    (3, 99, 1, "יתומה", 0, "2016-01-02 10:00:00", 1), (4, 1, 555, "מומחה לא קיים", 0, "2016-01-02 10:00:00", 1)])
    db.execute("insert into rt_articles_forum_questions(rt_articles_id,rt_forum_questions_id) values(1,1)")
    db.commit()
    return db
