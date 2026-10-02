#!/usr/bin/env bash
set -euo pipefail

SCRIPT_VERSION="2026-10-02-v1"
MOODLE_DIR="${MOODLE_DIR:-/var/www/moodle}"
PHP_BIN="${PHP_BIN:-/usr/bin/php8.4}"
SOURCE_DIR="${SOURCE_DIR:-}"
PARTS_DIR="${PARTS_DIR:-}"

[[ $EUID -eq 0 ]] || { echo "Uruchom jako root wewnątrz LXC." >&2; exit 1; }
[[ -f "$MOODLE_DIR/config.php" ]] || { echo "Brak Moodle: $MOODLE_DIR/config.php" >&2; exit 1; }
[[ -x "$PHP_BIN" ]] || { echo "Brak PHP: $PHP_BIN" >&2; exit 1; }

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
IMPORTER="$SCRIPT_DIR/import.php"

[[ -f "$IMPORTER" ]] || { echo "Brak importera: $IMPORTER" >&2; exit 1; }

echo "FUB Portal Wiedzy importer: $SCRIPT_VERSION"
"$PHP_BIN" -l "$IMPORTER"

TMPDIR=""
cleanup() {
    if [[ -n "$TMPDIR" && -d "$TMPDIR" ]]; then
        rm -rf "$TMPDIR"
    fi
}
trap cleanup EXIT

if [[ -z "$SOURCE_DIR" ]]; then
    [[ -n "$PARTS_DIR" ]] || {
        echo "Podaj SOURCE_DIR=/katalog/rozpakowany lub PARTS_DIR=/katalog/z/pw.zip.001..." >&2
        exit 1
    }

    for part in pw.zip.001 pw.zip.002 pw.zip.003; do
        [[ -f "$PARTS_DIR/$part" ]] || {
            echo "Brak części archiwum: $PARTS_DIR/$part" >&2
            exit 1
        }
    done

    command -v unzip >/dev/null 2>&1 || {
        echo "Brak programu unzip. Zainstaluj: apt install unzip" >&2
        exit 1
    }

    TMPDIR="$(mktemp -d /tmp/fub-portal-wiedzy.XXXXXX)"
    ARCHIVE="$TMPDIR/pw.zip"
    mkdir -p "$TMPDIR/source"

    echo "[1/4] Łączę części archiwum..."
    cat "$PARTS_DIR/pw.zip.001" "$PARTS_DIR/pw.zip.002" "$PARTS_DIR/pw.zip.003" > "$ARCHIVE"

    echo "[2/4] Sprawdzam archiwum..."
    unzip -tq "$ARCHIVE"

    echo "[3/4] Rozpakowuję dokumenty..."
    unzip -q "$ARCHIVE" -d "$TMPDIR/source"
    chown -R www-data:www-data "$TMPDIR/source"
    find "$TMPDIR/source" -type d -exec chmod 0750 {} +
    find "$TMPDIR/source" -type f -exec chmod 0640 {} +
    chmod 0755 "$TMPDIR"

    SOURCE_DIR="$TMPDIR/source"

    entries=( "$SOURCE_DIR"/* )
    if [[ ${#entries[@]} -eq 1 && -d "${entries[0]}" ]]; then
        SOURCE_DIR="${entries[0]}"
    fi
else
    echo "[1/4] Używam rozpakowanego katalogu: $SOURCE_DIR"
    echo "[2/4] Pomijam łączenie archiwum."
    echo "[3/4] Pomijam rozpakowywanie."
fi

echo "[4/4] Importuję Portal Wiedzy..."
cd "$MOODLE_DIR"
runuser -u www-data -- env MOODLE_DIR="$MOODLE_DIR" "$PHP_BIN" "$IMPORTER" --source="$SOURCE_DIR" "$@"

echo "Gotowe."
