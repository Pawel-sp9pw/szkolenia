# Szkolenia RODO – pakiet FUB dla Moodle 5.2.2

Pakiet tworzy w Moodle kategorię **RODO** i pięć profilowanych kursów. Część wspólna nie jest osobnym kursem – jest wbudowana w każde szkolenie, dzięki czemu pracownik otrzymuje jeden kurs odpowiadający jego roli.

## Kursy

| Kod | Nazwa krótka | Pełna nazwa | Przypisanie |
| --- | --- | --- | --- |
| `RODO-MED` | `RODO | Personel medyczny` | **RODO w praktyce – personel medyczny** | lekarze, pielęgniarki, technicy RTG, fizjoterapeuci/rehabilitanci i pozostały personel medyczny |
| `RODO-REJ` | `RODO | Rejestracja` | **RODO w praktyce – rejestracja i pacjent** | rejestracja stacjonarna, centralna rejestracja i osoby obsługujące pacjenta |
| `RODO-ADM` | `RODO | Administracja` | **RODO w praktyce – administracja i wsparcie** | księgowość, kadry, płace, dział świadczeń, dział prawny, BHP, controlling, dział techniczno-majątkowy i pozostała administracja |
| `RODO-IT` | `RODO | IT` | **RODO w praktyce – IT i bezpieczeństwo informacji** | IT, administratorzy systemów i osoby z dostępem uprzywilejowanym |
| `RODO-MKT` | `RODO | Marketing` | **RODO w praktyce – marketing i komunikacja** | marketing, WWW, social media, eventy i komunikacja |

## Struktura każdego kursu

Każdy kurs zawiera:

1. start i cele szkolenia,
2. osiem rozbudowanych modułów wspólnych RODO,
3. cztery moduły profilowane dla danej grupy,
4. test końcowy.

Treść została przygotowana dla realiów sieci 16 przychodni. Stan prawny materiału oznaczono na **8 września 2026 r.**

## Testy

Każdy kurs ma własny bank **30 pytań**:

- 15 pytań wspólnych,
- 15 pytań stanowiskowych,
- quiz losuje **5 pytań z 30**,
- maksymalnie **3 podejścia**,
- próg zaliczenia **80% = 4/5**,
- kolejność odpowiedzi jest losowana,
- pytania są sytuacyjne i mają wyjaśnienia.

## Format pakietu

Wersja `fub-rodo-v2` nie używa skompresowanych plików Base64. Treści i pytania są przechowywane w czytelnych plikach JSON w katalogu `data/`, a importer PHP korzysta z nich bezpośrednio.

Najważniejsze pliki:

- `import.php` – importer Moodle 5.2.2,
- `import-to-moodle.sh` – bezpieczny wrapper do uruchomienia w LXC,
- `validate.py` – walidator kompletności pakietu,
- `data/manifest.json` – definicje kursów i ustawienia quizów,
- `data/*-sections.json` – treści modułów,
- `data/*-questions.json` – banki pytań.

## Automatyczny import do istniejącego Moodle

Na hoście Proxmox dla CT 116:

```bash
pct exec 116 -- bash -lc '
curl -fsSL https://raw.githubusercontent.com/Pawel-sp9pw/szkolenia/main/moodle/training/rodo/import-to-moodle.sh -o /root/import-rodo.sh
chmod 700 /root/import-rodo.sh
bash -n /root/import-rodo.sh
bash /root/import-rodo.sh
'
```

Wrapper przed importem:

- pobiera aktualny pakiet z GitHub,
- uruchamia `validate.py`,
- sprawdza składnię PHP,
- dopiero potem uruchamia importer jako `www-data`.

Importer:

- tworzy kategorię `RODO` po `idnumber`,
- tworzy pięć kursów po `idnumber`,
- dodaje i aktualizuje zarządzane sekcje,
- tworzy bank pytań zgodny z Moodle 5.2,
- importuje po 30 pytań do każdego kursu,
- tworzy quiz z 5 losowymi pytaniami,
- ustawia 3 podejścia i próg zaliczenia 80%,
- nie duplikuje kursów, pytań ani testów przy ponownym uruchomieniu.

### Import tylko wybranych kursów

```bash
bash /root/import-rodo.sh --only=medical,registration
```

Dostępne klucze:

`medical`, `registration`, `admin`, `it`, `marketing`.

### Aktualizacja treści istniejących kursów

```bash
bash /root/import-rodo.sh --update-content
```

Ta opcja aktualizuje nazwy, opisy i zarządzane sekcje. Nie usuwa pytań ani prób użytkowników.

## Walidacja w GitHub Actions

Workflow `.github/workflows/validate-rodo.yml` sprawdza:

- kompletność 5 kursów,
- 8 sekcji wspólnych i 4 profilowane na kurs,
- 15 pytań wspólnych i 15 profilowanych na kurs,
- ustawienia quizu 5/30, 3 podejścia, 80%,
- poprawność JSON,
- składnię PHP i Bash,
- obecność API Moodle 5.2 wykorzystywanego przez importer.

## Podstawy prawne wykorzystane w treści

Materiały odwołują się m.in. do:

- rozporządzenia Parlamentu Europejskiego i Rady (UE) 2016/679 (RODO), w szczególności art. 4–6, 9, 12–22, 24–25, 28–30 i 32–35,
- ustawy z 10 maja 2018 r. o ochronie danych osobowych,
- ustawy o prawach pacjenta i Rzeczniku Praw Pacjenta,
- ustawy o działalności leczniczej,
- ustawy o systemie informacji w ochronie zdrowia,
- przepisów dotyczących dokumentacji medycznej,
- właściwych przepisów zawodowych dla personelu medycznego.

Materiały szkoleniowe opisują zasady ogólne. Wewnętrzne procedury organizacji i instrukcje IOD mają pierwszeństwo w zakresie szczegółowego sposobu postępowania w organizacji.
