import sqlite3, json
from pathlib import Path

DB = Path(r'c:\xampp\htdocs\easyschedF\data\easysched.sqlite')
REPORT = Path(r'c:\xampp\htdocs\easyschedF\data\student_schedule_report.json')

conn = sqlite3.connect(DB)
conn.row_factory = sqlite3.Row
cur = conn.cursor()
term_row = cur.execute("SELECT id FROM academic_terms WHERE is_active = 1 ORDER BY id DESC LIMIT 1").fetchone()
term_id = int(term_row['id']) if term_row else None
valid_section = cur.execute(
    "SELECT sec.id, sec.code FROM sections sec JOIN course_offerings co ON co.section_id = sec.id AND co.term_id = ? AND co.status = 'ACTIVE' WHERE sec.active = 1 GROUP BY sec.id ORDER BY sec.id LIMIT 1",
    (term_id,),
).fetchone() if term_id is not None else None
valid_section_id = int(valid_section['id']) if valid_section else None
before = [dict(r) for r in cur.execute("SELECT id, username, role, section_id, active FROM users WHERE username = 'student' ORDER BY id").fetchall()]
if valid_section_id is not None:
    cur.execute("UPDATE users SET section_id = ? WHERE username = ? AND role = ?", (valid_section_id, 'student', 'student'))
    conn.commit()
after = [dict(r) for r in cur.execute("SELECT id, username, role, section_id, active FROM users WHERE username = 'student' ORDER BY id").fetchall()]
valid_sections = [dict(r) for r in cur.execute(
    "SELECT sec.id, sec.code FROM sections sec JOIN course_offerings co ON co.section_id = sec.id AND co.term_id = ? AND co.status = 'ACTIVE' WHERE sec.active = 1 GROUP BY sec.id ORDER BY sec.id",
    (term_id,),
).fetchall()] if term_id is not None else []
report = {
    'term_id': term_id,
    'valid_section_id': valid_section_id,
    'student_before': before,
    'student_after': after,
    'valid_sections': valid_sections,
}
REPORT.write_text(json.dumps(report, indent=2), encoding='utf-8')
print(json.dumps(report, indent=2))
conn.close()
