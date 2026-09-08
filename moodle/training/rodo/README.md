# Szkolenia RODO – pakiet FUB dla Moodle 5.2.2

Pakiet tworzy w Moodle kategorię **RODO** i pięć profilowanych kursów. Część wspólna nie jest osobnym kursem – jest pierwszą częścią każdego szkolenia, dzięki czemu pracownik otrzymuje jeden kurs odpowiadający jego roli.

## Kursy

| Kod | Nazwa w Moodle | Przypisanie |
| --- | --- | --- |
| `RODO-MED` | **RODO w praktyce – personel medyczny** | lekarze, pielęgniarki, technicy RTG, fizjoterapeuci/rehabilitanci i pozostały personel medyczny |
| `RODO-REJ` | **RODO w praktyce – rejestracja i pacjent** | rejestracja stacjonarna, centralna rejestracja i osoby obsługujące pacjenta |
| `RODO-ADM` | **RODO w praktyce – administracja i wsparcie** | księgowość, kadry, płace, dział świadczeń, dział prawny, BHP, controlling, dział techniczno-majątkowy i pozostała administracja |
| `RODO-IT` | **RODO w praktyce – IT i bezpieczeństwo informacji** | IT, administratorzy systemów i osoby z dostępem uprzywilejowanym |
| `RODO-MKT` | **RODO w praktyce – marketing i komunikacja** | marketing, WWW, social media, eventy i komunikacja |

Przyjazne nazwy krótkie w Moodle:

- `RODO | Personel medyczny`
- `RODO | Rejestracja`
- `RODO | Administracja`
- `RODO | IT`
- `RODO | Marketing`

Dzięki temu kursy są czytelne na liście i łatwe do przypisywania do kohort.

## Struktura każdego kursu

Każdy kurs zawiera:

1. start i cele szkolenia,
2. wspólne podstawy ochrony danych,
3. zasady RODO i podstawy prawne,
4. dostęp do danych i poufność,
5. bezpieczną pracę na co dzień,
6. prawa osób i zasady udostępniania,
7. naruszenia ochrony danych,
8. podsumowanie wspólne,
9. cztery moduły profilowane dla danej grupy,
10. test końcowy.

Treść została przygotowana dla realiów sieci 16 przychodni. Stan prawny materiału oznaczono na **8 września 2026 r.**

## Testy

Każdy kurs ma własny bank **30 pytań**:

- 15 pytań sprawdza wspólną wiedzę RODO,
- 15 pytań dotyczy sytuacji charakterystycznych dla danej grupy,
- quiz losuje **5 pytań z 30**,
- maksymalnie **3 podejścia**,
- próg zaliczenia **80% = 4/5**,
- kolejność odpowiedzi jest losowana,
- pytania są sytuacyjne i zawierają wyjaśnienia.

Pliki Moodle XML znajdują się w `questions/`. Można je także importować ręcznie do banku pytań Moodle niezależnie od automatycznego importera.

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

Importer:

- tworzy kategorię `RODO`,
- tworzy kursy po `idnumber`,
- dodaje sekcje i profesjonalnie sformatowane treści,
- tworzy bank pytań wymagany przez Moodle 5.2,
- importuje 30 pytań do każdego kursu,
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

Ta opcja aktualizuje nazwy, opis i zarządzane sekcje. Nie kasuje pytań ani prób użytkowników.

## Ręczny import banków pytań

Pliki:

- `questions/rodo-med.xml`
- `questions/rodo-rej.xml`
- `questions/rodo-adm.xml`
- `questions/rodo-it.xml`
- `questions/rodo-mkt.xml`

Format: **Moodle XML**.

## Podstawy prawne wykorzystane w treści

W materiałach odwołano się m.in. do:

- rozporządzenia Parlamentu Europejskiego i Rady (UE) 2016/679 (RODO), w szczególności art. 4–6, 9, 12–22, 24–25, 28–30 i 32–35,
- ustawy z 10 maja 2018 r. o ochronie danych osobowych,
- ustawy o prawach pacjenta i Rzeczniku Praw Pacjenta,
- ustawy o działalności leczniczej,
- ustawy o systemie informacji w ochronie zdrowia,
- przepisów dotyczących dokumentacji medycznej,
- właściwych przepisów zawodowych dla personelu medycznego.

Materiały szkoleniowe opisują zasady ogólne. Wewnętrzne procedury organizacji i instrukcje IOD mają pierwszeństwo w zakresie szczegółowego sposobu postępowania w danej organizacji.
