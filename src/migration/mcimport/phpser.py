"""PHP unserialize מינימלי (מערכים, מחרוזות, מספרים, בוליאני, null) + פענוח base64 של שדות הדאמפ הישן.

הדאמפ שומר domains, sections, data כ-base64 של מחרוזת PHP מסודרת (serialize).
מחרוזות מסודרות מודדות אורך בבתים (UTF-8), ולכן הפענוח עובד על bytes.
"""
import base64
import binascii


class PhpSerError(ValueError):
    pass


def _parse(b, i):
    t = b[i:i + 1]
    if t == b"N":
        if b[i:i + 2] != b"N;":
            raise PhpSerError("N")
        return None, i + 2
    if t in (b"b", b"i", b"d"):
        j = b.index(b";", i)
        raw = b[i + 2:j]
        if t == b"b":
            return raw == b"1", j + 1
        if t == b"i":
            return int(raw), j + 1
        return float(raw), j + 1
    if t == b"s":
        j = b.index(b":", i + 2)
        n = int(b[i + 2:j])
        start = j + 2  # דילוג על :"
        s = b[start:start + n]
        if b[start + n:start + n + 2] != b'";':
            raise PhpSerError("s: אורך לא תואם")
        return s.decode("utf-8", "replace"), start + n + 2
    if t == b"a":
        j = b.index(b":", i + 2)
        n = int(b[i + 2:j])
        i = j + 2  # דילוג על :{
        out = {}
        for _ in range(n):
            k, i = _parse(b, i)
            v, i = _parse(b, i)
            out[k] = v
        if b[i:i + 1] != b"}":
            raise PhpSerError("a: חסר }")
        return out, i + 1
    raise PhpSerError("סוג לא נתמך: %r" % t)


def unserialize(data):
    if isinstance(data, str):
        data = data.encode("utf-8")
    v, end = _parse(data, 0)
    return v


def decode_field(value):
    """base64 -> PHP serialize -> פייתון. החזרה: (ערך, שגיאה). ריק = (None, None).
    אם זה לא base64 של serialize (למשל טקסט פשוט), מחזיר את הטקסט כמות שהוא."""
    if value is None or value == "":
        return None, None
    try:
        raw = base64.b64decode(value, validate=True)
    except (binascii.Error, ValueError):
        return value, None
    try:
        return unserialize(raw), None
    except (PhpSerError, ValueError, IndexError) as e:
        return None, "פענוח נכשל: %s" % e


def as_list(value):
    """מערך PHP מסודר (dict עם מפתחות 0..n) -> רשימה של מחרוזות."""
    if value is None:
        return []
    if isinstance(value, dict):
        return [str(v) for k, v in sorted(value.items(), key=lambda kv: str(kv[0])) if v not in (None, "")]
    return [str(value)]
