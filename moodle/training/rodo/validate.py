#!/usr/bin/env python3
import json
import pathlib
import sys

root = pathlib.Path(sys.argv[1] if len(sys.argv) > 1 else pathlib.Path(__file__).parent)
data = root / "data"

def load(name):
    path = data / name
    if not path.is_file():
        raise SystemExit(f"Brak pliku: {path}")
    try:
        return json.loads(path.read_text(encoding="utf-8"))
    except Exception as exc:
        raise SystemExit(f"Niepoprawny JSON {path}: {exc}")

manifest = load("manifest.json")
if manifest.get("schema") != "fub-rodo-v2":
    raise SystemExit("Nieprawidłowy schema manifestu.")

common_sections = load("common-sections-1.json") + load("common-sections-2.json")
common_questions = load("common-questions.json")

if len(common_sections) != 8:
    raise SystemExit(f"Oczekiwano 8 sekcji wspólnych, jest {len(common_sections)}.")
if len(common_questions) != 15:
    raise SystemExit(f"Oczekiwano 15 pytań wspólnych, jest {len(common_questions)}.")

required_courses = {"medical", "registration", "admin", "it", "marketing"}
courses = manifest.get("courses", [])
keys = {c.get("key") for c in courses}
if keys != required_courses or len(courses) != 5:
    raise SystemExit(f"Nieprawidłowy zestaw kursów: {sorted(keys)}")

all_ids = set()
for q in common_questions:
    qid = q.get("id")
    if not qid or qid in all_ids:
        raise SystemExit(f"Duplikat/brak ID pytania wspólnego: {qid}")
    all_ids.add(qid)
    if len(q.get("answers", [])) != 4:
        raise SystemExit(f"Pytanie {qid} nie ma 4 odpowiedzi.")
    if q.get("correct") not in range(4):
        raise SystemExit(f"Pytanie {qid} ma nieprawidłowy indeks poprawnej odpowiedzi.")

for course in courses:
    key = course["key"]
    sections = load(f"{key}-sections.json")
    questions = load(f"{key}-questions.json")
    if len(sections) != 4:
        raise SystemExit(f"{key}: oczekiwano 4 sekcji profilowanych, jest {len(sections)}.")
    if len(questions) != 15:
        raise SystemExit(f"{key}: oczekiwano 15 pytań profilowanych, jest {len(questions)}.")
    local = set()
    for q in questions:
        qid = q.get("id")
        if not qid or qid in local:
            raise SystemExit(f"{key}: duplikat/brak ID pytania: {qid}")
        local.add(qid)
        if len(q.get("answers", [])) != 4:
            raise SystemExit(f"{key}/{qid}: pytanie nie ma 4 odpowiedzi.")
        if q.get("correct") not in range(4):
            raise SystemExit(f"{key}/{qid}: błędny indeks poprawnej odpowiedzi.")
        for field in ("name", "text", "feedback"):
            if not str(q.get(field, "")).strip():
                raise SystemExit(f"{key}/{qid}: brak pola {field}.")
    for section in sections:
        if not str(section.get("name", "")).strip() or not str(section.get("summary", "")).strip():
            raise SystemExit(f"{key}: niepełna sekcja.")

quiz = manifest.get("defaults", {}).get("quiz", {})
expected = {"random_questions": 5, "attempts": 3, "pass_percent": 80}
for field, value in expected.items():
    if quiz.get(field) != value:
        raise SystemExit(f"Nieprawidłowe ustawienie quizu {field}: {quiz.get(field)!r}")

print("OK: 5 kursów, 8 sekcji wspólnych, 4 sekcje profilowane/kurs, 30 pytań/kurs, quiz 5/30, 3 podejścia, próg 80%.")
