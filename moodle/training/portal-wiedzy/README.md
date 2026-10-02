# Portal Wiedzy FUB – importer do Moodle

Importer odtwarza strukturę dokumentów ze starego Portalu Wiedzy w Moodle 5.2.x.

## Docelowa struktura

- główna kategoria: **Portal Wiedzy**,
- foldery pośrednie: kategorie Moodle,
- folder końcowy zawierający dokumenty: jeden kurs,
- wszystkie pliki w folderze końcowym trafiają do tego samego kursu, dzięki czemu dokument główny i istniejące aneksy zachowują ciągłość,
- jeśli plik leży bezpośrednio w folderze, który ma również podfoldery, taki plik staje się osobnym kursem w tej kategorii.

Przykład:

    Administracja/
      Zespół ds. Pracy/
        Regulaminy, uchwały, zarządzenia/
          Regulamin pracy/
            Regulamin pracy.pdf
            Aneks nr 1.pdf
            Aneks nr 2.pdf

powstaje jako:

    Portal Wiedzy
      Administracja
        Zespół ds. Pracy
          Regulaminy, uchwały, zarządzenia
            kurs: Regulamin pracy
              Regulamin pracy.pdf
              Aneks nr 1.pdf
              Aneks nr 2.pdf
              Potwierdzenie zapoznania

## Potwierdzenie zapoznania

Każdy kurs zawiera aktywność Moodle Choice **Potwierdzenie zapoznania** z jedną odpowiedzią:

**Potwierdzam, że zapoznałem/-am się z dokumentami**

Wysłanie odpowiedzi automatycznie kończy aktywność. Ukończenie tej aktywności jest kryterium ukończenia kursu.

Nie są tworzone testy wiedzy. Dokumenty nie są konwertowane ani przepisywane.

## Dostęp dla wszystkich użytkowników

Importer tworzy systemową kohortę:

- nazwa: `Portal Wiedzy – wszyscy zarejestrowani`
- idnumber: `FUB-PORTAL-WIEDZY`

Do kohorty trafiają wszyscy aktualni, aktywni i potwierdzeni użytkownicy Moodle.
Każdy kurs Portalu Wiedzy otrzymuje synchronizację kohorty z rolą `student`.

Wtyczka `auth_manualapproval` została również zmieniona tak, aby każdy nowo zatwierdzony użytkownik był automatycznie dodawany do tej kohorty. Kohorta jest obowiązkowa i nie jest pokazywana w zwykłym edytorze kohort FUB.

## Uruchomienie z rozpakowanego katalogu

    SOURCE_DIR=/srv/portal-wiedzy \
    MOODLE_DIR=/var/www/moodle \
    PHP_BIN=/usr/bin/php8.4 \
    bash import-to-moodle.sh

## Uruchomienie z trzyczęściowego archiwum

W jednym katalogu umieść:

    pw.zip.001
    pw.zip.002
    pw.zip.003

Następnie:

    PARTS_DIR=/srv/pw-import \
    MOODLE_DIR=/var/www/moodle \
    PHP_BIN=/usr/bin/php8.4 \
    bash import-to-moodle.sh

Wrapper połączy części, sprawdzi ZIP, rozpakowuje go do katalogu tymczasowego i uruchomi importer.

## Podgląd bez zmian

    runuser -u www-data -- \
      env MOODLE_DIR=/var/www/moodle \
      /usr/bin/php8.4 import.php \
      --source=/srv/portal-wiedzy \
      --dry-run

Tryb dry-run pokazuje plan kursów, kategorii i plików bez wprowadzania zmian w Moodle.

## Aktualizacje później

Importer służy do pierwszej migracji. Kolejne aneksy i dokumenty mogą być dokładane ręcznie do odpowiednich kursów w Moodle.
